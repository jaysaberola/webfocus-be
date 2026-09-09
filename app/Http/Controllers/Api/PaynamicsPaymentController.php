<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaynamicsPaymentReference;
use App\Services\PaynamicsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PaynamicsPaymentController extends Controller
{
    /**
     * Receive Paynamics' signed server-to-server payment notification.
     */
    public function notification(
        Request $request,
        PaynamicsService $paynamics
    ): JsonResponse {
        $payload = $this->paynamicsPayload($request);

        Log::info('PAYNAMICS NOTIFICATION RECEIVED', [
            'request_id' => $payload['request_id'] ?? $request->input('request_id'),
            'response_code' => $payload['response_code'] ?? $request->input('response_code'),
            'has_signature' => !empty($payload['signature']),
        ]);

        try {
            $reference = $paynamics->processNotification($payload);

            return response()->json([
                'message' => 'Notification accepted.',
                'request_id' => $reference->request_id,
                'status' => $reference->status,
            ], 200);
        } catch (ValidationException $exception) {
            Log::warning('PAYNAMICS NOTIFICATION REJECTED', [
                'request_id' => $request->input('request_id'),
                'errors' => $exception->errors(),
            ]);

            return response()->json([
                'message' => 'Invalid Paynamics notification.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            Log::error('PAYNAMICS NOTIFICATION ERROR', [
                'request_id' => $request->input('request_id'),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Notification processing failed.',
            ], 500);
        }
    }

    /**
     * Return the customer's browser to the frontend.
     */
    public function returnFromGateway(
        Request $request,
        PaynamicsService $paynamics
    ): RedirectResponse {
        $payload = $paynamics->normalizeCallbackPayload($this->paynamicsPayload($request));
        $requestId = $this->callbackRequestId($payload, $request);
        $responseCode = strtoupper(trim((string) (
            $payload['response_code']
            ?? $payload['responsecode']
            ?? $request->input('response_code')
            ?? ''
        )));
        $status = 'pending';
        $hostedSuccess = in_array($responseCode, ['GR001', 'GR002', 'GR033', ''], true);

        Log::info('PAYNAMICS BROWSER RETURN RECEIVED', [
            'request_id' => $requestId,
            'response_code' => $responseCode,
            'has_signature' => !empty($payload['signature']),
        ]);

        if ($requestId !== '' && $hostedSuccess) {
            try {
                $reference = $paynamics->processNotification(
                    $payload,
                    !empty($payload['signature']),
                    true
                );

                $status = $reference->status;
                $requestId = $reference->request_id;
            } catch (Throwable $exception) {
                Log::warning('Paynamics hosted return was not applied with the original payload.', [
                    'request_id' => $requestId,
                    'error' => $exception->getMessage(),
                ]);

                try {
                    $reference = $paynamics->settlePaidByRequestId(
                        $requestId,
                        $responseCode !== '' ? $responseCode : 'GR033'
                    );
                    $status = $reference->status;
                    $requestId = $reference->request_id;
                } catch (Throwable $fallbackException) {
                    Log::warning('Paynamics hosted return fallback was not applied.', [
                        'request_id' => $requestId,
                        'error' => $fallbackException->getMessage(),
                    ]);
                    $status = PaynamicsPaymentReference::query()
                        ->where('request_id', $requestId)
                        ->value('status') ?: 'pending';
                }
            }
        } elseif ($requestId !== '') {
            $status = PaynamicsPaymentReference::query()
                ->where('request_id', $requestId)
                ->value('status') ?: 'pending';
        }

        return $this->frontendRedirect(
            $this->frontendStatus($status),
            $requestId
        );
    }

    /**
     * Settle a hosted Paynamics checkout after the customer returns to the portal.
     * Only the owning customer can confirm their own request ID.
     */
    public function confirm(
        Request $request,
        PaynamicsService $paynamics
    ): JsonResponse {
        $validated = $request->validate([
            'request_id' => ['required', 'string', 'max:40'],
        ]);

        $customer = $request->user();
        abort_unless($customer, 401);

        $reference = PaynamicsPaymentReference::query()
            ->with('salesTransaction')
            ->where('request_id', $validated['request_id'])
            ->firstOrFail();

        $transaction = $reference->salesTransaction;
        abort_unless(
            $transaction && (int) $transaction->customer_id === (int) $customer->id,
            403,
            'This Paynamics payment does not belong to the signed-in customer.'
        );

        if ($reference->status !== 'paid' && $transaction->payment_status !== 'paid') {
            abort_unless(
                in_array($reference->status, ['initiating', 'redirect_ready', 'pending'], true),
                422,
                'This Paynamics payment can no longer be confirmed.'
            );

            $reference = $paynamics->settlePaidByRequestId($reference->request_id);
        }

        return response()->json([
            'message' => 'Payment confirmed.',
            'request_id' => $reference->request_id,
            'status' => $reference->status,
        ]);
    }

    /**
     * Return the customer after cancelling the hosted checkout.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $payload = $this->paynamicsPayload($request);
        $requestId = trim((string) (
            $payload['request_id'] ??
            $request->input('request_id') ??
            ''
        ));

        if ($requestId === '') {
            Log::warning('PAYNAMICS CANCELLATION WITHOUT REQUEST ID');

            return $this->frontendRedirect('cancelled');
        }

        /*
         * Update only non-final transactions. A late browser cancellation must
         * never overwrite an already successful signed payment notification.
         */
        $updated = PaynamicsPaymentReference::query()
            ->where('request_id', $requestId)
            ->whereIn('status', [
                'initiating',
                'redirect_ready',
                'pending',
            ])
            ->update([
                'status' => 'cancelled',
                'failed_at' => now(),
            ]);

        $status = PaynamicsPaymentReference::query()
            ->where('request_id', $requestId)
            ->value('status');

        Log::info('PAYNAMICS CHECKOUT CANCELLED', [
            'request_id' => $requestId,
            'updated' => $updated === 1,
            'status' => $status,
        ]);

        return $this->frontendRedirect(
            $this->frontendStatus($status ?: 'cancelled'),
            $requestId
        );
    }

    private function frontendRedirect(
        string $status,
        ?string $requestId = null
    ): RedirectResponse {
        $url = trim((string) config('paynamics.frontend_return_url'));

        if ($url === '') {
            throw new RuntimeException(
                'PAYNAMICS_FRONTEND_RETURN_URL is not configured.'
            );
        }

        $query = [
            'paynamics' => $status,
        ];

        if ($requestId !== null && trim($requestId) !== '') {
            $query['request_id'] = trim($requestId);
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return redirect()->away(
            $url . $separator . http_build_query($query)
        );
    }

    private function frontendStatus(string $status): string
    {
        return match ($status) {
            'initiating', 'redirect_ready' => 'pending',
            default => $status,
        };
    }

    private function paynamicsPayload(Request $request): array
    {
        return $request->all();
    }

    private function callbackRequestId(array $payload, Request $request): string
    {
        return trim((string) (
            $payload['request_id']
            ?? $payload['requestid']
            ?? $payload['RequestId']
            ?? $payload['org_trxid']
            ?? $request->input('request_id')
            ?? $request->query('request_id')
            ?? ''
        ));
    }
}
