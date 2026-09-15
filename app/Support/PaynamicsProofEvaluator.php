<?php

namespace App\Support;

class PaynamicsProofEvaluator
{
    public const CODE_OK = 'ok';
    public const CODE_UNREADABLE = 'unreadable';
    public const CODE_NOT_PAYNAMICS = 'not_paynamics';
    public const CODE_AMOUNT_MISMATCH = 'amount_mismatch';
    public const CODE_NOT_SUCCESS = 'not_success';
    public const CODE_CHECKOUT_PAGE = 'checkout_page';

    /**
     * @param  array<int, string>  $requestIds
     * @return array{valid: bool, code: string, message: string, has_brand: bool, has_amount: bool, has_success: bool, matched_request_id: ?string}
     */
    public static function evaluate(string $text, float $amount, array $requestIds = []): array
    {
        $normalized = self::normalize($text);
        $compact = preg_replace('/\s+/', '', $normalized) ?? '';

        if (! self::looksReadable($text, $compact)) {
            return self::result(
                false,
                self::CODE_UNREADABLE,
                'We could not read this file. Upload a clearer PNG, JPG, or PDF of the Paynamics payment success page.',
            );
        }

        $hasBrand = self::hasBrand($normalized, $compact);
        $matchedRequestId = self::matchedRequestId($compact, $requestIds);
        $hasAmount = self::hasAmount($text, $normalized, $amount);
        $hasSuccess = self::hasSuccess($normalized, $compact);
        $isCheckout = self::isCheckoutPage($normalized, $compact) && ! $hasSuccess && $matchedRequestId === null;

        if ($isCheckout) {
            return self::result(
                false,
                self::CODE_CHECKOUT_PAGE,
                'This looks like the Paynamics checkout page, not a completed payment. Upload the Payment Success receipt.',
                $hasBrand,
                $hasAmount,
                $hasSuccess,
                $matchedRequestId,
            );
        }

        if (! $hasBrand && $matchedRequestId === null) {
            return self::result(
                false,
                self::CODE_NOT_PAYNAMICS,
                'This does not look like a Paynamics payment proof. Upload the Paynamics Payment Success screenshot or receipt.',
                $hasBrand,
                $hasAmount,
                $hasSuccess,
            );
        }

        if (! $hasAmount && $matchedRequestId === null) {
            return self::result(
                false,
                self::CODE_AMOUNT_MISMATCH,
                'The amount on this receipt does not match this invoice.',
                $hasBrand,
                $hasAmount,
                $hasSuccess,
            );
        }

        if (! $hasSuccess && $matchedRequestId === null) {
            return self::result(
                false,
                self::CODE_NOT_SUCCESS,
                'This receipt does not show a successful Paynamics payment.',
                $hasBrand,
                $hasAmount,
                $hasSuccess,
            );
        }

        return self::result(
            true,
            self::CODE_OK,
            'This looks like a valid Paynamics payment proof.',
            $hasBrand,
            $hasAmount,
            $hasSuccess,
            $matchedRequestId,
        );
    }

    public static function looksReadable(string $text, ?string $compact = null): bool
    {
        $compact ??= preg_replace('/\s+/', '', self::normalize($text)) ?? '';
        $letters = preg_replace('/[^a-z]/', '', $compact) ?? '';
        $alnum = preg_replace('/[^a-z0-9]/', '', $compact) ?? '';

        return strlen($letters) >= 12 && strlen($alnum) >= 20;
    }

    private static function hasBrand(string $normalized, string $compact): bool
    {
        return str_contains($compact, 'paynamics')
            || str_contains($compact, 'paynamlcs')
            || (bool) preg_match('/pay\s*[-]?namics/', $normalized)
            || str_contains($normalized, 'hosted.paynamics')
            || str_contains($compact, 'paygatepaynamics');
    }

    private static function hasSuccess(string $normalized, string $compact): bool
    {
        $needles = [
            'payment success',
            'payment successful',
            'paid successfully',
            'transaction successful',
            'transaction success',
            'payment complete',
            'payment completed',
            'successfully paid',
            'you have successfully',
            'thank you for your payment',
            'request id',
            'response id',
            'response code',
        ];

        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return (bool) preg_match('/gr0(01|02|33)/', $compact)
            || str_contains($compact, 'paymentsuccess')
            || str_contains($compact, 'requestid');
    }

    private static function isCheckoutPage(string $normalized, string $compact): bool
    {
        return str_contains($normalized, 'how would you like to pay')
            || str_contains($normalized, 'select payment method')
            || str_contains($normalized, 'choose your payment')
            || str_contains($compact, 'continuetopay');
    }

    private static function hasAmount(string $text, string $normalized, float $amount): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $haystack = $normalized . ' ' . strtolower($text);
        $variants = array_unique([
            number_format($amount, 2, '.', ''),
            number_format($amount, 2, '.', ','),
            number_format($amount, 0, '.', ''),
            number_format($amount, 0, '.', ','),
        ]);

        foreach ($variants as $variant) {
            if ($variant === '0' || $variant === '0.00') {
                continue;
            }

            $pattern = '/(?<![0-9])' . preg_quote($variant, '/') . '(?![0-9])/';
            if (preg_match($pattern, $haystack)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $requestIds
     */
    private static function matchedRequestId(string $compact, array $requestIds): ?string
    {
        foreach ($requestIds as $requestId) {
            $id = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $requestId) ?? '');
            if (strlen($id) < 8) {
                continue;
            }

            if (str_contains(strtoupper($compact), $id)) {
                return (string) $requestId;
            }
        }

        return null;
    }

    private static function normalize(string $text): string
    {
        $value = mb_strtolower($text);
        $value = str_replace(['₱', 'php'], ['php ', 'php '], $value);
        $value = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * @return array{valid: bool, code: string, message: string, has_brand: bool, has_amount: bool, has_success: bool, matched_request_id: ?string}
     */
    private static function result(
        bool $valid,
        string $code,
        string $message,
        bool $hasBrand = false,
        bool $hasAmount = false,
        bool $hasSuccess = false,
        ?string $matchedRequestId = null
    ): array {
        return [
            'valid' => $valid,
            'code' => $code,
            'message' => $message,
            'has_brand' => $hasBrand,
            'has_amount' => $hasAmount,
            'has_success' => $hasSuccess,
            'matched_request_id' => $matchedRequestId,
        ];
    }
}
