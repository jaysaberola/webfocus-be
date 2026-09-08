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
        $payload = $this->paynamicsPayload($request);
        $requestId = trim((string) (
            $payload['request_id'] ??
            $request->input('request_id') ??
            ''
        ));
        $status = 'pending';

        Log::info('PAYNAMICS BROWSER RETURN RECEIVED', [
            'request_id' => $requestId,
            'response_code' => $payload['response_code'] ?? $request->input('response_code'),
            'has_signature' => !empty($payload['signature']),
        ]);

        if (!empty($payload['signature'])) {
            try {
                $reference = $paynamics->processNotification($payload);

                $status = $reference->status;
                $requestId = $reference->request_id;
            } catch (Throwable $exception) {
                Log::warning('Invalid Paynamics browser return.', [
                    'request_id' => $request->input('request_id'),
                    'error' => $exception->getMessage(),
                ]);

                $status = 'verification_failed';
            }
        } elseif ($requestId !== '') {
            /*
             * An unsigned browser response is never used to update payment
             * status. We only display the status already stored by the signed
             * Paynamics notification.
             */
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
        $payload = $request->all();

        foreach (['response', 'transaction', 'data'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return $payload;
    }
}
