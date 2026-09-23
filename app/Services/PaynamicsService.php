<?php

namespace App\Services;

use App\Models\PaynamicsPaymentReference;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Support\DealMeta;
use App\Support\RelatedPaymentSync;
use App\Support\WebDesignQuotation;
use Illuminate\Http\Client\Response;
use Carbon\Carbon;
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

    private const HOSTED_SUCCESS_CODES = ['GR001', 'GR002', 'GR033'];

    private const PENDING_CODES = [
        'GR033',
        'GR020',
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
            'payment_method' => $this->paymentMethodFromTransactionNotes($transaction),
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

            if ($this->requestId($body) !== $requestId) {
                throw new RuntimeException('Paynamics returned a different request ID.');
            }

            $responseCode = $this->responseCode($body);
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

            throw $exception;
        }
    }

    public function assertCustomerProfile(User $customer, ?SalesTransaction $transaction = null): void
    {
        $this->hydrateCustomerForCheckout($customer, $transaction);

        [$fname, $lname] = User::paynamicsPersonName(
            $customer->fname,
            $customer->lname,
            $customer->mname
        );

        $errors = [];

        $required = [
            'fname' => $fname,
            'lname' => $lname,
            'email' => $customer->email,
            'address_street' => $customer->address_street,
            'address_city' => $this->billingCity($customer),
            'address_province' => $customer->address_province,
            'address_zip' => $customer->address_zip,
        ];

        $requiredMessages = [
            'fname' => 'Complete your first name to continue Paynamics checkout.',
            'lname' => 'Complete your last name to continue Paynamics checkout.',
            'email' => 'Complete your email to continue Paynamics checkout.',
            'address_street' => 'Complete your billing address to continue Paynamics checkout.',
            'address_city' => 'Complete your billing address to continue Paynamics checkout.',
            'address_province' => 'Complete your billing address to continue Paynamics checkout.',
            'address_zip' => 'Complete your billing address to continue Paynamics checkout.',
        ];

        foreach ($required as $field => $value) {
            if (trim((string) $value) === '') {
                $errors[$field][] = $requiredMessages[$field];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function processNotification(
        array $payload,
        bool $requireSignature = true,
        bool $fromHostedReturn = false
    ): PaynamicsPaymentReference {
        $payload = $this->unwrapPayload($payload);
        $this->assertConfigured();

        $requestId = $this->requestId($payload);
        if ($requestId === '') {
            throw ValidationException::withMessages([
                'request_id' => ['The Paynamics request ID is required.'],
            ]);
        }

        if ($requireSignature && !$this->hasValidResponseSignature($payload)) {
            throw ValidationException::withMessages([
                'signature' => ['The Paynamics response signature is invalid.'],
            ]);
        }

        if ($requireSignature && $this->value($payload, 'merchant_id', 'merchantid') !== config('paynamics.merchant_id')) {
            throw ValidationException::withMessages([
                'merchant_id' => ['The Paynamics merchant ID does not match.'],
            ]);
        }

        $result = DB::transaction(function () use ($payload, $requestId, $fromHostedReturn) {
            $reference = PaynamicsPaymentReference::query()
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->firstOrFail();

            $transaction = SalesTransaction::query()
                ->whereKey($reference->sales_transaction_id)
                ->lockForUpdate()
                ->firstOrFail();

            $responseCode = $this->responseCode($payload);
            if ($fromHostedReturn && $responseCode === '') {
                $responseCode = 'GR033';
            }

            $nextStatus = $this->statusForResponseCode($responseCode, $fromHostedReturn);

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

            $methodLabel = $this->paymentMethodLabelFromPayload($payload);
            if ($methodLabel) {
                $existing = trim((string) $reference->payment_method);
                $keepExistingSpecific = $existing !== ''
                    && !self::isCategoryPaymentLabel($existing)
                    && self::isCategoryPaymentLabel($methodLabel);

                if (!$keepExistingSpecific) {
                    $referenceUpdates['payment_method'] = $methodLabel;
                }
            }

            $paidAt = null;
            if ($nextStatus === 'paid') {
                $paidAt = $this->paidAtFromPayload($payload, $reference->paid_at);
                $referenceUpdates['paid_at'] = $paidAt;
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

            $modeLabel = trim((string) ($referenceUpdates['payment_method'] ?? $reference->payment_method ?? $methodLabel ?? ''));
            if ($nextStatus === 'paid') {
                RelatedPaymentSync::apply(
                    $transaction->fresh() ?? $transaction,
                    $modeLabel !== '' ? $modeLabel : 'Paynamics',
                    $paidAt ?? now()
                );
            }

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

    public function settlePaidByRequestId(string $requestId, string $responseCode = 'GR033'): PaynamicsPaymentReference
    {
        return $this->processNotification(
            [
                'request_id' => $requestId,
                'response_code' => $responseCode,
            ],
            false,
            true
        );
    }

    public function normalizeCallbackPayload(array $payload): array
    {
        return $this->unwrapPayload($payload);
    }

    public function hasValidResponseSignature(array $payload): bool
    {
        $provided = strtolower(trim((string) ($payload['signature'] ?? '')));
        if ($provided === '') {
            return false;
        }

        $forSign =
            $this->value($payload, 'merchant_id', 'merchantid') .
            $this->requestId($payload) .
            $this->value($payload, 'response_id', 'responseid') .
            $this->value($payload, 'gateway_id', 'gatewayid') .
            $this->responseCode($payload) .
            $this->value($payload, 'response_message', 'responsemessage') .
            $this->value($payload, 'response_advise', 'responseadvise') .
            $this->value($payload, 'timestamp', 'Timestamp') .
            $this->value($payload, 'processor_response_id', 'processorresponseid') .
            $this->value($payload, 'processor_response_authcode', 'processorresponseauthcode') .
            $this->value($payload, 'pay_reference', 'payreference') .
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
        $amount = $this->money(WebDesignQuotation::displayAmount($transaction));

        $notificationUrl = $this->notificationUrl();
        $responseUrl = $this->responseUrl($requestId);
        $cancelUrl = $this->cancelUrl($requestId);

        $collectionMethod = 'single_pay';
        $notificationStatus = (string) config('paynamics.notification_status', '1');
        $notificationChannel = (string) config('paynamics.notification_channel', '1');

        $dob = $customer->birth_date
            ? date('Y-m-d', strtotime((string) $customer->birth_date))
            : '';

        $phone = $this->normalizePhone($customer->phone);
        $mobile = $this->normalizePhone($customer->mobile);
        $fname = $this->limitPaynamicsField('fname', (string) $customer->fname);
        $lname = $this->limitPaynamicsField('lname', (string) $customer->lname);
        $mname = $this->limitPaynamicsField('mname', (string) ($customer->mname ?? ''));
        $email = $this->limitPaynamicsField('email', (string) $customer->email);
        $street = $this->limitPaynamicsField('address_street', (string) $customer->address_street);
        $city = $this->limitPaynamicsField('address_city', $this->billingCity($customer));
        $province = $this->limitPaynamicsField('address_province', (string) $customer->address_province);
        $zip = $this->limitPaynamicsField('address_zip', (string) $customer->address_zip);

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
            $fname .
            $lname .
            $mname .
            $email .
            $phone .
            $mobile .
            $dob .
            $merchantKey
        );

        $customerInfo = [
            'fname' => $fname,
            'lname' => $lname,
            'mname' => $mname,
            'email' => $email,
            'dob' => $dob,
            'signature' => $customerSignature,
        ];

        if ($phone !== '') {
            $customerInfo['phone'] = $phone;
        }

        if ($mobile !== '') {
            $customerInfo['mobile'] = $mobile;
        }

        $orders = $this->paynamicsOrderLines($transaction, $amount);

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
                'billing_address1' => $street,
                'billing_address2' => '',
                'billing_city' => $city,
                'billing_state' => $province,
                'billing_country' => 'PH',
                'billing_zip' => $zip,
            ],
            'order_details' => [
                'orders' => $orders['lines'],
                'subtotalprice' => $orders['subtotal'],
                'shippingprice' => $this->money(0),
                'discountamount' => $this->money(0),
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

        // +639171234567 / 639171234567 → 09171234567
        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            $digits = '0' . substr($digits, 2);
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
        $this->assertCustomerProfile($customer, $transaction);

        $errors = [];

        if (!$transaction->items->count()) {
            $errors['items'][] = 'At least one order item is required.';
        }

        if ((float) WebDesignQuotation::displayAmount($transaction) <= 0) {
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
            $alphabet = 'ABCDEFGHJKMNPQRTUVWXYZ23456789';
            $suffix = '';
            for ($i = 0; $i < 8; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $requestId = 'WF' . now()->format('ymdHis') . $suffix;
        } while (PaynamicsPaymentReference::where('request_id', $requestId)->exists());

        return $requestId;
    }

    private function statusForResponseCode(string $responseCode, bool $fromHostedReturn = false): string
    {
        if (in_array($responseCode, self::SUCCESS_CODES, true)) {
            return 'paid';
        }

        /*
         * Paynamics test cards often show "Payment Success" while the merchant
         * dashboard stays on GR033 until a later settlement IPN. The hosted
         * return is the customer completing checkout, so treat it as paid.
         * GR033 on the initial RPF/IPN remains pending so unpaid redirects
         * are not marked paid early.
         */
        if ($fromHostedReturn && in_array($responseCode, self::HOSTED_SUCCESS_CODES, true)) {
            return 'paid';
        }

        if (in_array($responseCode, self::PENDING_CODES, true)) {
            return 'pending';
        }

        return 'failed';
    }

    private function paymentMethodLabelFromPayload(array $payload): ?string
    {
        $keys = [
            'pchannel',
            'p_channel',
            'payment_channel',
            'paymentchannel',
            'pmt_channel',
            'pmethod',
            'p_method',
            'payment_method',
            'paymentmethod',
            'pmt_method',
            'ptype',
            'p_type',
            'payment_type',
            'paymenttype',
            'channel',
        ];

        $labels = [];
        foreach ($keys as $key) {
            $raw = $this->payloadValue($payload, $key);
            if ($raw === '') {
                continue;
            }
            $label = self::labelForPaymentChannel($raw);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        if ($labels === []) {
            return null;
        }

        $specific = array_values(array_filter(
            $labels,
            fn (string $label) => !self::isCategoryPaymentLabel($label)
        ));

        return $specific[0] ?? $labels[0];
    }

    public static function isCategoryPaymentLabel(string $label): bool
    {
        return in_array($label, [
            'Credit / Debit Card',
            'Installment (Non-Credit Card)',
            'E-Wallet',
            'Online Bank Transfer',
            'Online Bills Payment',
        ], true);
    }

    public static function labelForPaymentChannel(string $raw): string
    {
        $pretty = trim($raw);
        if ($pretty === '') {
            return '';
        }

        if (preg_match('/^[0-9a-f]{16,}$/i', $pretty) || preg_match('/^\d{8,}$/', $pretty)) {
            return '';
        }

        $needle = strtolower(str_replace(['_', '-', ' '], '', $pretty));
        if ($needle === '' || $needle === 'paynamics' || str_contains($needle, 'ipg') || str_contains($needle, 'hosted')) {
            return '';
        }

        $map = [
            'cc' => 'Credit / Debit Card',
            'creditcard' => 'Credit / Debit Card',
            'creditdebitcard' => 'Credit / Debit Card',
            'visa' => 'Credit / Debit Card',
            'mastercard' => 'Credit / Debit Card',
            'installment' => 'Installment (Non-Credit Card)',
            'billease' => 'Billease',
            'billeas' => 'Billease',
            'bdoinstall' => 'Installment (Non-Credit Card)',
            'bpiinstall' => 'Installment (Non-Credit Card)',
            'hsbcinstall' => 'Installment (Non-Credit Card)',
            'wallet' => 'E-Wallet',
            'ewallet' => 'E-Wallet',
            'gc' => 'GCash',
            'gcash' => 'GCash',
            'maya' => 'Maya',
            'paymaya' => 'Maya',
            'pwallet' => 'Maya',
            'coins' => 'coins.ph',
            'coinsph' => 'coins.ph',
            'coinsphwallet' => 'coins.ph',
            'grabpay' => 'GrabPay',
            'gry' => 'GrabPay',
            'bn' => 'Online Bank Transfer',
            'bancnet' => 'Online Bank Transfer',
            'onlinebanktransfer' => 'Online Bank Transfer',
            'bpi' => 'BPI',
            'bpionline' => 'BPI',
            'bdo' => 'BDO',
            'bdoobp' => 'BDO',
            'brankasbdo' => 'BDO',
            'qrph' => 'QRPh',
            'qrphl' => 'QRPh',
            'instapay' => 'QRPh',
            'unionbank' => 'UnionBank',
            'ubp' => 'UnionBank',
            'ubpobp' => 'UnionBank',
            'landbank' => 'Landbank',
            'lbl' => 'Landbank',
            'brankaslandbank' => 'Landbank',
            'ecpay' => 'Online Bills Payment',
            'onlinebillspayment' => 'Online Bills Payment',
            'onlinebillspyament' => 'Online Bills Payment',
            'bankotc' => 'Online Bills Payment',
            'nonbankotc' => 'Online Bills Payment',
            'robinsons' => 'Robinsons Bank',
            'robinsonsbank' => 'Robinsons Bank',
            'rbank' => 'Robinsons Bank',
        ];

        if (isset($map[$needle])) {
            return $map[$needle];
        }

        if (preg_match('/billease|billeas/', $needle)) {
            return 'Billease';
        }
        if (preg_match('/install|instl|noncredit/', $needle)) {
            return 'Installment (Non-Credit Card)';
        }
        if (preg_match('/gcash|^gc$/', $needle)) {
            return 'GCash';
        }
        if (preg_match('/paymaya|maya/', $needle)) {
            return 'Maya';
        }
        if (preg_match('/grabpay|^gry$/', $needle)) {
            return 'GrabPay';
        }
        if (preg_match('/coins/', $needle)) {
            return 'coins.ph';
        }
        if (preg_match('/ewallet|pwallet|wallet|shopee|alipay/', $needle)) {
            return 'E-Wallet';
        }
        if (preg_match('/^bpi|bpionline/', $needle)) {
            return 'BPI';
        }
        if (preg_match('/brankasbdo|^bdo$|bdoobp/', $needle)) {
            return 'BDO';
        }
        if (preg_match('/qrph|instapay/', $needle)) {
            return 'QRPh';
        }
        if (preg_match('/unionbank|^ubp|ubpobp/', $needle)) {
            return 'UnionBank';
        }
        if (preg_match('/landbank|brankaslandbank|^lbl$/', $needle)) {
            return 'Landbank';
        }
        if (preg_match('/robinson|^rbank$/', $needle)) {
            return 'Robinsons Bank';
        }
        if (preg_match('/bancnet|onlinebank|banktransfer|^bn$|onlinebanking/', $needle)) {
            return 'Online Bank Transfer';
        }
        if (preg_match('/ecpay|bills|otc|overthecounter|711|7eleven|cebuana|mlhuillier|palawan|dragonpay/', $needle)) {
            return 'Online Bills Payment';
        }
        if (preg_match('/^cc$|credit|debit|visa|master|^card$/', $needle)) {
            return 'Credit / Debit Card';
        }

        return $pretty;
    }

    private function payloadValue(array $payload, string $key): string
    {
        $want = strtolower($key);

        foreach ($payload as $name => $value) {
            if (strtolower((string) $name) === $want) {
                if (is_scalar($value)) {
                    return trim((string) $value);
                }
            }

            if (is_array($value)) {
                $nested = $this->payloadValue($value, $key);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function paidAtFromPayload(array $payload, mixed $existing): Carbon
    {
        if ($existing) {
            try {
                return Carbon::parse($existing);
            } catch (Throwable) {
                // Fall through to payload / now.
            }
        }

        foreach (['timestamp', 'payment_date', 'paymentdate', 'txn_time', 'txntime'] as $key) {
            $raw = $this->payloadValue($payload, $key);
            if ($raw === '') {
                continue;
            }

            try {
                if (preg_match('/^\d{14}$/', $raw)) {
                    return Carbon::createFromFormat('YmdHis', $raw) ?: now();
                }

                return Carbon::parse($raw);
            } catch (Throwable) {
                continue;
            }
        }

        return now();
    }

    private function paymentMethodFromTransactionNotes(SalesTransaction $transaction): ?string
    {
        if (!preg_match('/Payment method:\s*([^\n]+)/i', (string) $transaction->notes, $matches)) {
            return null;
        }

        $line = trim($matches[1]);
        if (preg_match('/\(([^)]+)\)\s*$/', $line, $labelMatch)) {
            $label = self::labelForPaymentChannel(trim($labelMatch[1]));
            if ($label !== '') {
                return $label;
            }
            $fallback = trim($labelMatch[1]);
            return $fallback !== '' && strcasecmp($fallback, 'Paynamics') !== 0 ? $fallback : null;
        }

        if (preg_match('/Paynamics-(\w+)/i', $line, $idMatch)) {
            $label = self::labelForPaymentChannel($idMatch[1]);
            return $label !== '' ? $label : null;
        }

        $label = self::labelForPaymentChannel($line);
        return $label !== '' ? $label : null;
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
            throw ValidationException::withMessages([
                'paynamics' => [
                    app()->isLocal() || config('app.debug')
                        ? 'Paynamics merchant credentials are missing in the backend .env (PAYNAMICS_MERCHANT_ID, PAYNAMICS_MERCHANT_KEY, PAYNAMICS_BASIC_AUTH_USERNAME, PAYNAMICS_BASIC_AUTH_PASSWORD).'
                        : 'Paynamics is temporarily unavailable. Please try again later or contact support.',
                ],
            ]);
        }
    }

    private function notificationUrl(): string
    {
        return (string) (
            config('paynamics.notification_url') ?: route('paynamics.notification')
        );
    }

    private function responseUrl(?string $requestId = null): string
    {
        return $this->callbackUrl(
            (string) (config('paynamics.response_url') ?: route('paynamics.return')),
            $requestId
        );
    }

    private function cancelUrl(?string $requestId = null): string
    {
        return $this->callbackUrl(
            (string) (config('paynamics.cancel_url') ?: route('paynamics.cancel')),
            $requestId
        );
    }

    private function callbackUrl(string $url, ?string $requestId = null): string
    {
        $url = trim($url);
        if ($url === '' || !$requestId) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'request_id=' . rawurlencode($requestId);
    }

    public function hydrateCustomerForCheckout(User $customer, ?SalesTransaction $transaction = null): User
    {
        $billing = $this->resolveCheckoutBilling($customer, $transaction);
        [$fname, $lname] = $this->resolveCheckoutNames($customer, $billing);

        $persist = [];
        if ($fname !== '' && trim((string) $customer->fname) !== $fname) {
            $persist['fname'] = $fname;
        }

        $storedLastName = User::usableLastName($customer->lname, $customer->mname);
        if ($storedLastName === '' && trim((string) $customer->lname) !== '') {
            $persist['lname'] = '';
        }
        if ($storedLastName === '' && User::usableLastName($lname, $customer->mname) !== '') {
            $persist['lname'] = $lname;
        }

        $city = $this->billingCity($customer);
        $fieldMap = [
            'address_street' => trim((string) $customer->address_street),
            'address_city' => $city,
            'address_province' => trim((string) $customer->address_province),
            'address_zip' => trim((string) $customer->address_zip),
        ];
        foreach ($fieldMap as $field => $current) {
            $next = $this->limitPaynamicsField($field, $billing[$field] ?? '');
            if ($current === '' && $next !== '') {
                $persist[$field] = $next;
            }
        }

        if (trim((string) $customer->address_city) === '' && ($persist['address_city'] ?? $city) !== '') {
            $persist['address_city'] = $persist['address_city'] ?? $city;
        }

        if ($persist !== []) {
            if (empty($customer->address_country) && isset($persist['address_street'])) {
                $persist['address_country'] = 'Philippines';
            }
            $customer->fill($persist);
            $customer->save();
        }

        if ($fname !== '') {
            $customer->fname = $fname;
        }
        $customer->lname = User::usableLastName($customer->lname, $customer->mname);
        if (trim((string) $customer->address_city) === '' && $this->billingCity($customer) !== '') {
            $customer->address_city = $this->billingCity($customer);
        }

        return $customer;
    }

    /**
     * @return array{address_street: string, address_city: string, address_province: string, address_zip: string, contact_name: string}
     */
    private function resolveCheckoutBilling(User $customer, ?SalesTransaction $transaction): array
    {
        $billing = $this->billingFromNotes($transaction?->notes);
        if ($this->billingIsComplete($billing) && $this->usableText($billing['contact_name']) !== '') {
            return $billing;
        }

        $query = SalesTransaction::query()
            ->where('customer_id', $customer->id)
            ->whereNotNull('notes')
            ->where(function ($inner) {
                $inner->where('notes', 'like', '%[INVOICE_META]%')
                    ->orWhere('notes', 'like', '%[DEAL_META]%');
            })
            ->latest('id')
            ->limit(12);

        if ($transaction?->id) {
            $query->where('id', '!=', $transaction->id);
        }

        foreach ($query->get(['notes']) as $row) {
            $billing = $this->mergeBilling($billing, $this->billingFromNotes($row->notes));
            if ($this->billingIsComplete($billing)) {
                break;
            }
        }

        return $billing;
    }

    /**
     * @param  array{address_street: string, address_city: string, address_province: string, address_zip: string, contact_name: string}  $billing
     * @return array{0: string, 1: string}
     */
    private function resolveCheckoutNames(User $customer, array $billing): array
    {
        [$fname, $lname] = User::paynamicsPersonName(
            $customer->fname,
            $customer->lname,
            $customer->mname,
            $customer->contact_person ?: ($billing['contact_name'] ?? ''),
            $customer->email
        );

        return [
            $this->limitPaynamicsField('fname', $fname),
            $this->limitPaynamicsField('lname', $lname),
        ];
    }

    /**
     * @return array{address_street: string, address_city: string, address_province: string, address_zip: string, contact_name: string}
     */
    private function billingFromNotes(?string $notes): array
    {
        $empty = [
            'address_street' => '',
            'address_city' => '',
            'address_province' => '',
            'address_zip' => '',
            'contact_name' => '',
        ];
        $meta = $this->prefixedJson($notes, '[INVOICE_META]');
        if ($meta === []) {
            $meta = $this->prefixedJson($notes, '[DEAL_META]');
        }
        if ($meta === []) {
            return $empty;
        }

        return [
            'address_street' => $this->usableText($meta['billingStreet'] ?? $meta['address_street'] ?? null),
            'address_city' => $this->usableText($meta['billingCity'] ?? $meta['address_city'] ?? null),
            'address_province' => $this->usableText(
                $meta['billingState'] ?? $meta['billingProvince'] ?? $meta['address_province'] ?? null
            ),
            'address_zip' => $this->usableText($meta['billingCode'] ?? $meta['billingZip'] ?? $meta['address_zip'] ?? null),
            'contact_name' => $this->usableText($meta['contactName'] ?? $meta['contact_name'] ?? null),
        ];
    }

    /**
     * @param  array{address_street: string, address_city: string, address_province: string, address_zip: string, contact_name: string}  $current
     * @param  array{address_street: string, address_city: string, address_province: string, address_zip: string, contact_name: string}  $incoming
     * @return array{address_street: string, address_city: string, address_province: string, address_zip: string, contact_name: string}
     */
    private function mergeBilling(array $current, array $incoming): array
    {
        foreach ($current as $key => $value) {
            if ($this->usableText($value) === '') {
                $current[$key] = $incoming[$key] ?? '';
            }
        }

        return $current;
    }

    /**
     * @param  array{address_street: string, address_city: string, address_province: string, address_zip: string, contact_name?: string}  $billing
     */
    private function billingIsComplete(array $billing): bool
    {
        return $this->usableText($billing['address_street'] ?? '') !== ''
            && $this->usableText($billing['address_city'] ?? '') !== ''
            && $this->usableText($billing['address_province'] ?? '') !== ''
            && $this->usableText($billing['address_zip'] ?? '') !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function prefixedJson(?string $notes, string $prefix): array
    {
        $text = (string) $notes;
        $marker = strpos($text, $prefix);
        if ($marker === false) {
            return [];
        }

        $jsonLine = strtok(substr($text, $marker + strlen($prefix)), "\n") ?: '';
        $jsonLine = trim($jsonLine);
        if ($jsonLine === '' || ! str_starts_with($jsonLine, '{')) {
            return [];
        }

        $decoded = json_decode($jsonLine, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function usableText(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '' || $text === '—' || $text === '-' || strcasecmp($text, 'n/a') === 0) {
            return '';
        }

        return $text;
    }

    /**
     * @return array{lines: array<int, array<string, string>>, subtotal: string}
     */
    private function paynamicsOrderLines(SalesTransaction $transaction, string $amount): array
    {
        $lines = $transaction->items
            ->map(function ($item) {
                $quantity = max(1, (float) $item->quantity);
                $total = (float) ($item->total_price ?? 0);
                if ($total <= 0) {
                    $total = (float) ($item->price ?? 0) * $quantity;
                }

                return [
                    'itemname' => Str::limit((string) $item->name, 100, ''),
                    'quantity' => $this->quantity($item->quantity),
                    'unitprice' => $this->money($item->price),
                    'totalprice' => $this->money($total),
                ];
            })
            ->values()
            ->all();

        $domain = DealMeta::mappedLine($transaction);
        if ($domain) {
            $lines[] = [
                'itemname' => Str::limit((string) $domain['name'], 100, ''),
                'quantity' => '1',
                'unitprice' => $this->money($domain['unitPrice'] ?? $domain['price'] ?? 0),
                'totalprice' => $this->money($domain['total'] ?? $domain['price'] ?? 0),
            ];
        }

        $sum = array_reduce(
            $lines,
            fn (float $carry, array $line) => $carry + (float) $line['totalprice'],
            0.0
        );
        $target = (float) $amount;
        if (abs($sum - $target) > 0.009 && $lines !== []) {
            $last = count($lines) - 1;
            $adjustment = round($target - ($sum - (float) $lines[$last]['totalprice']), 2);
            $lines[$last]['totalprice'] = $this->money(max(0, $adjustment));
            $qty = max(1, (float) $lines[$last]['quantity']);
            $lines[$last]['unitprice'] = $this->money(max(0, $adjustment / $qty));
            $sum = $target;
        }

        return [
            'lines' => $lines,
            'subtotal' => $this->money($sum),
        ];
    }

    private function limitPaynamicsField(string $field, string $value): string
    {
        $maximums = [
            'fname' => 50,
            'lname' => 50,
            'mname' => 32,
            'email' => 100,
            'address_street' => 100,
            'address_city' => 30,
            'address_province' => 30,
            'address_zip' => 12,
        ];
        $maximum = $maximums[$field] ?? 100;
        $value = trim($value);
        if (mb_strlen($value) <= $maximum) {
            return $value;
        }

        return trim(mb_substr($value, 0, $maximum));
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
        if (!empty($payload['data1']) && is_string($payload['data1'])) {
            $decoded = $this->decodeEmbeddedPayload($payload['data1']);
            if ($decoded) {
                $payload = array_merge($decoded, $payload);
            }
        }

        foreach (['response', 'transaction', 'data', 'payment_response', 'paymentresponse'] as $key) {
            $nested = $payload[$key] ?? null;
            if (is_array($nested)) {
                return array_merge($payload, $nested);
            }
            if (is_string($nested) && $nested !== '') {
                $decoded = $this->decodeEmbeddedPayload($nested);
                if ($decoded) {
                    return array_merge($payload, $decoded);
                }
            }
        }

        return $payload;
    }

    private function decodeEmbeddedPayload(string $raw): ?array
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $json = json_decode($value, true);
        if (is_array($json)) {
            return $json;
        }

        $fromQuery = [];
        parse_str($value, $fromQuery);
        if (isset($fromQuery['response_code']) || isset($fromQuery['request_id'])) {
            return $fromQuery;
        }

        if (preg_match('/^[0-9a-fA-F]+$/', $value) && strlen($value) % 2 === 0) {
            $binary = @hex2bin($value);
            if (is_string($binary) && $binary !== '') {
                $fromHex = json_decode($binary, true);
                if (is_array($fromHex)) {
                    return $fromHex;
                }
            }
        }

        $fromBase64 = json_decode(base64_decode($value, true) ?: '', true);
        return is_array($fromBase64) ? $fromBase64 : null;
    }

    private function requestId(array $payload): string
    {
        return trim((string) (
            $payload['request_id']
            ?? $payload['requestid']
            ?? $payload['RequestId']
            ?? $payload['org_trxid']
            ?? ''
        ));
    }

    private function responseCode(array $payload): string
    {
        return strtoupper(trim((string) (
            $payload['response_code']
            ?? $payload['responsecode']
            ?? $payload['ResponseCode']
            ?? ''
        )));
    }

    private function value(array $payload, string $primary, string $legacy): string
    {
        return (string) ($payload[$primary] ?? $payload[$legacy] ?? '');
    }

    
}
