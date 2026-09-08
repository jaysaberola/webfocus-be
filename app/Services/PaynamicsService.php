<?php

namespace App\Services;

use App\Models\PaynamicsPaymentReference;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use Illuminate\Support\Facades\Log;

class PaynamicsService
{
    private const SUCCESS_CODES = ['GR001', 'GR002'];

    private const PENDING_CODES = [
        'GR020',
        'GR033',
        'GR063',
        'GR119',
        'GR206',
        'RM035',
        'RM046',
    ];

    public function initiate(
        SalesTransaction $transaction,
        User $customer,
        ?string $clientIp = null,
        ?string $userAgent = null
    ): array {
        $this->assertConfigured();

        $transaction->loadMissing('items');
        $this->validateCheckoutData($transaction, $customer);

        $requestId = $this->generateRequestId();
        $reference = PaynamicsPaymentReference::create([
            'sales_transaction_id' => $transaction->id,
            'request_id' => $requestId,
            'status' => 'initiating',
        ]);

        $payload = $this->buildRequestPayload(
            $transaction,
            $customer,
            $requestId,
            $clientIp,
            $userAgent
        );

        try {
            $response = $this->sendRequest($requestId, $payload);
            $body = $response->json();

            if (!is_array($body)) {
                throw new RuntimeException('Paynamics returned an invalid JSON response.');
            }

            if (!$response->successful()) {
                throw new RuntimeException(
                    $this->gatewayErrorMessage($body, $response->status())
                );
            }

            if (!$this->hasValidResponseSignature($body)) {
                throw new RuntimeException('Paynamics response signature verification failed.');
            }

            if ($this->value($body, 'merchant_id', 'merchantid') !== config('paynamics.merchant_id')) {
                throw new RuntimeException('Paynamics returned a different merchant ID.');
            }

            if ((string) ($body['request_id'] ?? '') !== $requestId) {
                throw new RuntimeException('Paynamics returned a different request ID.');
            }

            $responseCode = (string) ($body['response_code'] ?? '');
            $redirectUrl = $this->redirectUrl($body);

            if (
                !in_array($responseCode, self::SUCCESS_CODES, true) &&
                !in_array($responseCode, self::PENDING_CODES, true)
            ) {
                throw new RuntimeException(
                    $this->gatewayErrorMessage($body, $response->status())
                );
            }

            if (!$redirectUrl) {
                throw new RuntimeException(
                    $this->gatewayErrorMessage($body, $response->status())
                );
            }

            $reference->update([
                'response_id' => $body['response_id'] ?? null,
                'response_code' => $responseCode ?: null,
                'status' => 'redirect_ready',
            ]);

            return [
                'request_id' => $requestId,
                'redirect_url' => $redirectUrl,
                'response_code' => $responseCode,
            ];
        } catch (Throwable $exception) {
            $reference->update([
                'status' => 'failed',
                'failed_at' => now(),
            ]);

            $transaction->update([
                'payment_status' => 'failed',
                'order_status' => 'payment_failed',
            ]);

            throw $exception;
        }
    }

    public function assertCustomerProfile(User $customer): void
    {
        $errors = [];

        $required = [
            'fname' => $customer->fname,
            'lname' => $customer->lname,
            'email' => $customer->email,
            'address_street' => $customer->address_street,
            'address_city' => $this->billingCity($customer),
            'address_province' => $customer->address_province,
            'address_zip' => $customer->address_zip,
        ];

        foreach ($required as $field => $value) {
            if (trim((string) $value) === '') {
                $errors[$field][] = 'Complete your billing address to continue Paynamics checkout.';
            }
        }

        $maximumLengths = [
            'fname' => [$customer->fname, 50],
            'lname' => [$customer->lname, 50],
            'mname' => [$customer->mname, 32],
            'email' => [$customer->email, 100],
            'phone' => [$customer->phone, 32],
            'mobile' => [$customer->mobile, 32],
            'address_street' => [$customer->address_street, 100],
            'address_city' => [$this->billingCity($customer), 30],
            'address_province' => [$customer->address_province, 30],
            'address_zip' => [$customer->address_zip, 12],
        ];

        foreach ($maximumLengths as $field => [$value, $maximum]) {
            if (mb_strlen((string) ($value ?? '')) > $maximum) {
                $errors[$field][] =
                    "This profile field must not exceed {$maximum} characters for Paynamics.";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function processNotification(array $payload): PaynamicsPaymentReference
    {
        $payload = $this->unwrapPayload($payload);
        $this->assertConfigured();

        $requestId = (string) ($payload['request_id'] ?? '');
        if ($requestId === '') {
            throw ValidationException::withMessages([
                'request_id' => ['The Paynamics request ID is required.'],
            ]);
        }

        if (!$this->hasValidResponseSignature($payload)) {
            throw ValidationException::withMessages([
                'signature' => ['The Paynamics response signature is invalid.'],
            ]);
        }

        if ($this->value($payload, 'merchant_id', 'merchantid') !== config('paynamics.merchant_id')) {
            throw ValidationException::withMessages([
                'merchant_id' => ['The Paynamics merchant ID does not match.'],
            ]);
        }

        $result = DB::transaction(function () use ($payload, $requestId) {
            $reference = PaynamicsPaymentReference::query()
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->firstOrFail();

            $transaction = SalesTransaction::query()
                ->whereKey($reference->sales_transaction_id)
                ->lockForUpdate()
                ->firstOrFail();

            $responseCode = (string) ($payload['response_code'] ?? '');
            $nextStatus = $this->statusForResponseCode($responseCode);

            /*
             * A delayed or duplicate failure notification must never downgrade
             * a transaction that was already confirmed as paid.
             */
            if ($reference->status === 'paid' || $transaction->payment_status === 'paid') {
                $nextStatus = 'paid';
            }

            $referenceUpdates = [
                'response_id' => $payload['response_id'] ?? $reference->response_id,
                'response_code' => $responseCode ?: $reference->response_code,
                'status' => $nextStatus,
            ];

            if ($nextStatus === 'paid') {
                $referenceUpdates['paid_at'] = $reference->paid_at ?? now();
                $referenceUpdates['failed_at'] = null;

                $transaction->update([
                    'payment_status' => 'paid',
                    'order_status' => 'processing',
                ]);
            } elseif ($nextStatus === 'pending') {
                $transaction->update([
                    'payment_status' => 'pending',
                    'order_status' => 'pending',
                ]);
            } else {
                $referenceUpdates['failed_at'] = $reference->failed_at ?? now();

                $transaction->update([
                    'payment_status' => 'failed',
                    'order_status' => 'payment_failed',
                ]);
            }

            $reference->update($referenceUpdates);

            return $reference->fresh(['salesTransaction.items']);
        });

        if ($result->status === 'paid' && !$result->provisioned_at) {
            /*
             * The provisioner should be idempotent. If it throws, the callback
             * returns an error and Paynamics can retry without losing payment state.
             */
            app(CustomerPortalProvisioner::class)
                ->provisionFromTransaction($result->salesTransaction);

            $result->update(['provisioned_at' => now()]);
        }

        return $result->fresh();
    }

    public function hasValidResponseSignature(array $payload): bool
    {
        $provided = strtolower(trim((string) ($payload['signature'] ?? '')));
        if ($provided === '') {
            return false;
        }

        $forSign =
            $this->value($payload, 'merchant_id', 'merchantid') .
            (string) ($payload['request_id'] ?? '') .
            (string) ($payload['response_id'] ?? '') .
            (string) ($payload['gateway_id'] ?? '') .
            (string) ($payload['response_code'] ?? '') .
            (string) ($payload['response_message'] ?? '') .
            (string) ($payload['response_advise'] ?? '') .
            (string) ($payload['timestamp'] ?? '') .
            (string) ($payload['processor_response_id'] ?? '') .
            (string) ($payload['processor_response_authcode'] ?? '') .
            (string) ($payload['pay_reference'] ?? '') .
            (string) ($payload['redirect_url'] ?? '') .
            (string) config('paynamics.merchant_key');

        return hash_equals(hash('sha512', $forSign), $provided);
    }

    private function sendRequest(string $requestId, array $payload): Response
{
    if (app()->isLocal()) {
        Log::info('PAYNAMICS RPF PAYLOAD', $payload);
    }

    return Http::asJson()
        ->acceptJson()
        ->withBasicAuth(
            (string) config('paynamics.username'),
            (string) config('paynamics.password')
        )
        ->withHeaders([
            'Idempotency-Key' => $requestId,
        ])
        ->connectTimeout(10)
        ->timeout((int) config('paynamics.timeout', 30))
        ->post(
            (string) config('paynamics.rpf_url'),
            $payload
        );
}

    private function buildRequestPayload(
        SalesTransaction $transaction,
        User $customer,
        string $requestId,
        ?string $clientIp,
        ?string $userAgent
    ): array {
        $merchantId = (string) config('paynamics.merchant_id');
        $merchantKey = (string) config('paynamics.merchant_key');
        $currency = (string) config('paynamics.currency', 'PHP');
        $amount = $this->money($transaction->grand_total);

        $notificationUrl = $this->notificationUrl();
        $responseUrl = $this->responseUrl();
        $cancelUrl = $this->cancelUrl();

        $collectionMethod = 'single_pay';
        $notificationStatus = '0';
        $notificationChannel = '1';

        $dob = $customer->birth_date
            ? date('Y-m-d', strtotime((string) $customer->birth_date))
            : '';

        $phone = $this->normalizePhone($customer->phone);
        $mobile = $this->normalizePhone($customer->mobile);

        $transactionSignature = hash(
            'sha512',
            $merchantId .
            $requestId .
            $notificationUrl .
            $responseUrl .
            $cancelUrl .
            $collectionMethod .
            $amount .
            $currency .
            $notificationStatus .
            $notificationChannel .
            $merchantKey
        );

        $customerSignature = hash(
            'sha512',
            (string) $customer->fname .
            (string) $customer->lname .
            (string) ($customer->mname ?? '') .
            (string) $customer->email .
            $phone .
            $mobile .
            $dob .
            $merchantKey
        );

        $customerInfo = [
            'fname' => (string) $customer->fname,
            'lname' => (string) $customer->lname,
            'mname' => (string) ($customer->mname ?? ''),
            'email' => (string) $customer->email,
            'dob' => $dob,
            'signature' => $customerSignature,
        ];

        if ($phone !== '') {
            $customerInfo['phone'] = $phone;
        }

        if ($mobile !== '') {
            $customerInfo['mobile'] = $mobile;
        }

        return [
            'transaction' => [
                'merchant_id' => $merchantId,
                'request_id' => $requestId,
                'notification_url' => $notificationUrl,
                'response_url' => $responseUrl,
                'cancel_url' => $cancelUrl,
                'amount' => $amount,
                'payment_action' => 'url_link',
                'collection_method' => $collectionMethod,
                'currency' => $currency,
                'descriptor_note' => Str::limit(
                    (string) config(
                        'paynamics.descriptor',
                        'WebFocus Solutions'
                    ),
                    24,
                    ''
                ),
                'payment_notification_status' => $notificationStatus,
                'payment_notification_channel' => $notificationChannel,
                'trx_type' => 'sale',
                'signature' => $transactionSignature,
            ],
            'customer_info' => $customerInfo,
            'billing_info' => [
                'billing_address1' => (string) $customer->address_street,
                'billing_address2' => '',
                'billing_city' => $this->billingCity($customer),
                'billing_state' => (string) $customer->address_province,
                'billing_country' => 'PH',
                'billing_zip' => (string) $customer->address_zip,
            ],
            'order_details' => [
                'orders' => $transaction->items
                    ->map(function ($item) {
                        return [
                            'itemname' => Str::limit(
                                (string) $item->name,
                                100,
                                ''
                            ),
                            'quantity' => $this->quantity($item->quantity),
                            'unitprice' => $this->money($item->price),
                            'totalprice' => $this->money($item->total_price),
                        ];
                    })
                    ->values()
                    ->all(),
                'subtotalprice' => $this->money($transaction->subtotal),
                'shippingprice' => $this->money($transaction->shipping_total),
                'discountamount' => $this->money($transaction->discount_total),
                'totalorderamount' => $amount,
            ],
            'contextual_info' => [
                'client_ip' => Str::limit(
                    (string) ($clientIp ?? ''),
                    20,
                    ''
                ),
                'user_agent' => Str::limit(
                    (string) ($userAgent ?? ''),
                    50,
                    ''
                ),
            ],
        ];
    }

    private function normalizePhone(?string $value): string
    {
        $digits = preg_replace(
            '/\D+/',
            '',
            trim((string) $value)
        ) ?? '';

        if ($digits === '') {
            return '';
        }

        // 00639171234567 → 639171234567
        if (str_starts_with($digits, '0063')) {
            $digits = substr($digits, 2);
        }

        // 9171234567 → 09171234567
        if (
            strlen($digits) === 10 &&
            str_starts_with($digits, '9')
        ) {
            $digits = '0' . $digits;
        }

        return $digits;
    }

    private function validateCheckoutData(
        SalesTransaction $transaction,
        User $customer
    ): void {
        $this->assertCustomerProfile($customer);

        $errors = [];

        if (!$transaction->items->count()) {
            $errors['items'][] = 'At least one order item is required.';
        }

        if ((float) $transaction->grand_total <= 0) {
            $errors['grand_total'][] = 'The Paynamics amount must be greater than zero.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function generateRequestId(): string
    {
        do {
            /*
             * Twenty-two alphanumeric characters, below the 23-character
             * Cybersource limit documented by Paynamics.
             */
            $requestId = 'WF' .
                now()->format('ymdHis') .
                Str::upper(Str::random(8));
        } while (PaynamicsPaymentReference::where('request_id', $requestId)->exists());

        return $requestId;
    }

    private function statusForResponseCode(string $responseCode): string
    {
        if (in_array($responseCode, self::SUCCESS_CODES, true)) {
            return 'paid';
        }

        if (in_array($responseCode, self::PENDING_CODES, true)) {
            return 'pending';
        }

        return 'failed';
    }

    private function redirectUrl(array $body): ?string
    {
        $url = trim((string) (
            $body['redirect_url'] ??
            $body['payment_action_info'] ??
            ''
        ));

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function gatewayErrorMessage(array $body, int $status): string
    {
        $code = trim((string) ($body['response_code'] ?? ''));
        $message = trim((string) ($body['response_message'] ?? ''));
        $advice = trim((string) ($body['response_advise'] ?? ''));

        $parts = array_values(array_filter([$code, $message, $advice]));

        return $parts
            ? implode(' - ', $parts)
            : "Paynamics rejected the request with HTTP status {$status}.";
    }

    private function assertConfigured(): void
    {
        $missing = collect([
            'PAYNAMICS_MERCHANT_ID' => config('paynamics.merchant_id'),
            'PAYNAMICS_MERCHANT_KEY' => config('paynamics.merchant_key'),
            'PAYNAMICS_BASIC_AUTH_USERNAME' => config('paynamics.username'),
            'PAYNAMICS_BASIC_AUTH_PASSWORD' => config('paynamics.password'),
        ])->filter(fn($value) => trim((string) $value) === '')->keys()->all();

        if ($missing) {
            throw new RuntimeException(
                'Missing Paynamics configuration: ' . implode(', ', $missing)
            );
        }
    }

    private function notificationUrl(): string
    {
        return (string) (
            config('paynamics.notification_url') ?: route('paynamics.notification')
        );
    }

    private function responseUrl(): string
    {
        return (string) (
            config('paynamics.response_url') ?: route('paynamics.return')
        );
    }

    private function cancelUrl(): string
    {
        return (string) (
            config('paynamics.cancel_url') ?: route('paynamics.cancel')
        );
    }

    private function billingCity(User $customer): string
    {
        return trim((string) (
            $customer->address_city ?: $customer->address_municipality
        ));
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function quantity(mixed $value): string
    {
        $number = (float) $value;

        return fmod($number, 1.0) === 0.0
            ? (string) (int) $number
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    private function unwrapPayload(array $payload): array
    {
        foreach (['response', 'transaction', 'data'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return $payload;
    }

    private function value(array $payload, string $primary, string $legacy): string
    {
        return (string) ($payload[$primary] ?? $payload[$legacy] ?? '');
    }

    
}
