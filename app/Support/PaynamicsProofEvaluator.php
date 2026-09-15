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
    public const CODE_WRONG_INVOICE = 'wrong_invoice';
    public const CODE_REQUEST_ID_MISMATCH = 'request_id_mismatch';

    /**
     * @param  array<int, string>  $requestIds
     * @param  array<int, string>  $foreignRequestIds
     * @return array{valid: bool, code: string, message: string, has_brand: bool, has_amount: bool, has_success: bool, matched_request_id: ?string}
     */
    public static function evaluate(
        string $text,
        float $amount,
        array $requestIds = [],
        array $foreignRequestIds = []
    ): array {
        $normalized = self::normalize($text);
        $compact = preg_replace('/\s+/', '', $normalized) ?? '';

        if (! self::looksReadable($text, $compact)) {
            return self::result(
                false,
                self::CODE_UNREADABLE,
                'We could not read this file. Upload a clearer PNG, JPG, or PDF of the Paynamics payment success page.',
            );
        }

        $hasBrand = self::hasBrand($normalized, $compact) || self::hasHostedSuccessPage($normalized, $compact);
        $matchedRequestId = self::matchedRequestId($compact, $requestIds);
        $extractedRequestIds = self::extractRequestIds($compact);
        $hasAmount = self::hasAmount($text, $normalized, $amount);
        $hasSuccess = self::hasSuccess($normalized, $compact);
        $isCheckout = self::isCheckoutPage($normalized, $compact) && ! $hasSuccess && $matchedRequestId === null;
        $expectedIds = self::normalizeIdList($requestIds);
        $foreignIds = self::normalizeIdList($foreignRequestIds);

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

        $foreignHit = self::firstLookalike($extractedRequestIds, $foreignIds);
        if ($matchedRequestId === null && $foreignHit !== null) {
            return self::result(
                false,
                self::CODE_WRONG_INVOICE,
                'This Paynamics receipt belongs to a different payment. Upload the Payment Success page for this invoice.',
                $hasBrand,
                $hasAmount,
                $hasSuccess,
            );
        }

        if ($expectedIds !== [] && $matchedRequestId === null) {
            if ($extractedRequestIds !== []) {
                return self::result(
                    false,
                    self::CODE_WRONG_INVOICE,
                    'This Paynamics Request ID does not match this invoice. Upload the Payment Success screenshot for this payment.',
                    $hasBrand,
                    $hasAmount,
                    $hasSuccess,
                );
            }

            return self::result(
                false,
                self::CODE_REQUEST_ID_MISMATCH,
                'We could not match the Paynamics Request ID on this receipt to this invoice. Upload a clearer Payment Success screenshot for this payment.',
                $hasBrand,
                $hasAmount,
                $hasSuccess,
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

    /**
     * Hosted Paynamics success receipts often show the merchant name and
     * Request ID instead of the word "Paynamics".
     */
    private static function hasHostedSuccessPage(string $normalized, string $compact): bool
    {
        $hasSuccessTitle = str_contains($normalized, 'payment success')
            || str_contains($compact, 'paymentsuccess');
        $hasMerchant = str_contains($compact, 'webfocus')
            || str_contains($compact, 'gobacktomerchant')
            || str_contains($normalized, 'have concerns on payment');
        $hasHostedFields = str_contains($compact, 'requestid')
            || str_contains($normalized, 'payment channel')
            || str_contains($normalized, 'payment method')
            || (bool) preg_match('/wf[0-9]{12}[a-z0-9]{8}/', $compact);

        return $hasSuccessTitle && $hasMerchant && $hasHostedFields;
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
        $haystack = strtoupper($compact);

        foreach ($requestIds as $requestId) {
            $id = self::normalizeId((string) $requestId);
            if (strlen($id) < 8) {
                continue;
            }

            if (str_contains($haystack, $id)) {
                return (string) $requestId;
            }

            foreach (self::extractRequestIds($compact) as $extracted) {
                if (self::idsLookAlike($id, $extracted)) {
                    return (string) $requestId;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function extractRequestIds(string $compact): array
    {
        if (! preg_match_all('/wf[0-9]{12}[a-z0-9]{8}/i', $compact, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn (string $value) => self::normalizeId($value),
            $matches[0]
        )));
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    private static function normalizeIdList(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $value = self::normalizeId((string) $id);
            if (strlen($value) >= 8) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function normalizeId(string $requestId): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($requestId)) ?? '';
    }

    /**
     * @param  array<int, string>  $left
     * @param  array<int, string>  $right
     */
    private static function firstLookalike(array $left, array $right): ?string
    {
        foreach ($left as $id) {
            foreach ($right as $other) {
                if (self::idsLookAlike($id, $other)) {
                    return $id;
                }
            }
        }

        return null;
    }

    private static function idsLookAlike(string $expected, string $found): bool
    {
        if ($expected === '' || $found === '') {
            return false;
        }

        if ($expected === $found || str_contains($found, $expected) || str_contains($expected, $found)) {
            return true;
        }

        $maxLen = max(strlen($expected), strlen($found));
        if ($maxLen < 12 || abs(strlen($expected) - strlen($found)) > 2) {
            return false;
        }

        return levenshtein($expected, $found) <= 2;
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
