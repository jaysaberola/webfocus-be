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
        Log::info('PAYNAMICS NOTIFICATION RECEIVED', [
            'request_id' => $request->input('request_id'),
            'response_code' => $request->input('response_code'),
            'has_signature' => $request->filled('signature'),
        ]);

        try {
            $reference = $paynamics->processNotification(
                $request->all()
            );

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
        $status = 'pending';

        if ($request->filled('signature')) {
            try {
                $reference = $paynamics->processNotification(
                    $request->all()
                );

                $status = $reference->status;
            } catch (Throwable $exception) {
                Log::warning('Invalid Paynamics browser return.', [
                    'request_id' => $request->input('request_id'),
                    'error' => $exception->getMessage(),
                ]);

                $status = 'verification_failed';
            }
        } elseif ($request->filled('request_id')) {
            /*
             * An unsigned browser response is never used to update payment
             * status. We only display the status already stored by the signed
             * Paynamics notification.
             */
            $status = PaynamicsPaymentReference::query()
                ->where(
                    'request_id',
                    (string) $request->input('request_id')
                )
                ->value('status') ?: 'pending';
        }

        return $this->frontendRedirect($status);
    }

    /**
     * Return the customer after cancelling the hosted checkout.
     */
    public function cancel(): RedirectResponse
    {
        /*
         * Never change payment status from an unsigned browser cancellation.
         * The signed server notification remains the source of truth.
         */
        return $this->frontendRedirect('cancelled');
    }

    private function frontendRedirect(string $status): RedirectResponse
    {
        $url = trim(
            (string) config('paynamics.frontend_return_url')
        );

        if ($url === '') {
            throw new RuntimeException(
                'PAYNAMICS_FRONTEND_RETURN_URL is not configured.'
            );
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return redirect()->away(
            $url .
            $separator .
            http_build_query([
                'paynamics' => $status,
            ])
        );
    }
}