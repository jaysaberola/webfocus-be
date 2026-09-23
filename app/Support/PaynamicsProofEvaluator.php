<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

class PaynamicsProofEvaluator
{
    public const CODE_OK = 'ok';
    public const CODE_UNREADABLE = 'unreadable';
    public const CODE_NOT_PAYNAMICS = 'not_paynamics';
    public const CODE_AMOUNT_MISMATCH = 'amount_mismatch';
    public const CODE_DATE_MISMATCH = 'date_mismatch';
    public const CODE_NOT_SUCCESS = 'not_success';
    public const CODE_CHECKOUT_PAGE = 'checkout_page';
    public const CODE_WRONG_INVOICE = 'wrong_invoice';
    public const CODE_REQUEST_ID_MISMATCH = 'request_id_mismatch';

    private const TIME_WINDOW_MINUTES = 120;

    /**
     * @param  array<int, string>  $requestIds
     * @param  array<int, string>  $foreignRequestIds
     * @param  array<int, CarbonInterface|string|int>  $expectedPaidAts
     * @return array{valid: bool, code: string, message: string, has_brand: bool, has_amount: bool, has_success: bool, has_date: bool, matched_request_id: ?string}
     */
    public static function evaluate(
        string $text,
        float $amount,
        array $requestIds = [],
        array $foreignRequestIds = [],
        array $expectedPaidAts = []
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
        $receiptTimes = self::extractReceiptDateTimes($text, $normalized);
        $hasDate = $receiptTimes !== [];
        $isCheckout = self::isCheckoutPage($normalized, $compact) && ! $hasSuccess && $matchedRequestId === null;
        $expectedIds = self::normalizeIdList($requestIds);
        $foreignIds = self::normalizeIdList($foreignRequestIds);
        $expectedTimes = self::normalizeExpectedPaidAts($expectedPaidAts, $requestIds);

        if ($isCheckout) {
            return self::result(
                false,
                self::CODE_CHECKOUT_PAGE,
                'This looks like the Paynamics checkout page, not a completed payment. Upload the Payment Success receipt.',
                $hasBrand,
                $hasAmount,
                $hasSuccess,
                $matchedRequestId,
                $hasDate,
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
                null,
                $hasDate,
            );
        }

        if ($expectedIds !== [] && $matchedRequestId === null) {
            $alignedId = self::alignedExpectedId(
                $extractedRequestIds,
                $requestIds,
                $receiptTimes,
                $expectedTimes,
                $hasBrand,
                $hasAmount,
                $hasSuccess
            );
            if ($alignedId !== null) {
                $matchedRequestId = $alignedId;
            } elseif ($extractedRequestIds !== []) {
                return self::result(
                    false,
                    self::CODE_WRONG_INVOICE,
                    'This Paynamics Request ID does not match this invoice. Upload the Payment Success screenshot for this payment.',
                    $hasBrand,
                    $hasAmount,
                    $hasSuccess,
                    null,
                    $hasDate,
                );
            } else {
                return self::result(
                    false,
                    self::CODE_REQUEST_ID_MISMATCH,
                    'We could not match the Paynamics Request ID on this receipt to this invoice. Upload a clearer Payment Success screenshot for this payment.',
                    $hasBrand,
                    $hasAmount,
                    $hasSuccess,
                    null,
                    $hasDate,
                );
            }
        }

        if (! $hasBrand && $matchedRequestId === null) {
            return self::result(
                false,
                self::CODE_NOT_PAYNAMICS,
                'This does not look like a Paynamics payment proof. Upload the Paynamics Payment Success screenshot or receipt.',
                $hasBrand,
                $hasAmount,
                $hasSuccess,
                $matchedRequestId,
                $hasDate,
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
                $matchedRequestId,
                $hasDate,
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
                $matchedRequestId,
                $hasDate,
            );
        }

        if ($hasDate && $expectedTimes !== [] && ! self::receiptDateMatches($receiptTimes, $expectedTimes)) {
            return self::result(
                false,
                self::CODE_DATE_MISMATCH,
                'The date and time on this receipt do not match this Paynamics payment. Upload the Payment Success receipt for this invoice.',
                $hasBrand,
                $hasAmount,
                $hasSuccess,
                $matchedRequestId,
                true,
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
            $hasDate,
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
            || (bool) preg_match('/payment[a-z]{0,4}success/', $compact);
        $hasMerchant = str_contains($compact, 'webfocus')
            || str_contains($compact, 'gobacktomerchant')
            || str_contains($normalized, 'have concerns on payment')
            || str_contains($compact, 'haveconcernsonpayment');
        $hasHostedFields = str_contains($compact, 'requestid')
            || (bool) preg_match('/request[a-z]{0,4}id/', $compact)
            || str_contains($normalized, 'payment channel')
            || (bool) preg_match('/payment[a-z]{0,4}channel/', $compact)
            || str_contains($normalized, 'payment method')
            || (bool) preg_match('/payment[a-z]{0,4}method/', $compact)
            || self::extractRequestIds($compact) !== [];

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
            || (bool) preg_match('/payment[a-z]{0,4}success/', $compact)
            || (bool) preg_match('/request[a-z]{0,4}id/', $compact);
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

        $compactDigits = preg_replace('/[^0-9]/', '', $haystack) ?? '';
        $amountDigits = preg_replace('/[^0-9]/', '', number_format($amount, 2, '.', '')) ?? '';
        $wholeDigits = preg_replace('/[^0-9]/', '', number_format($amount, 0, '.', '')) ?? '';
        if (strlen($wholeDigits) >= 4 && (
            str_contains($compactDigits, $amountDigits) ||
            str_contains($compactDigits, $wholeDigits)
        )) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<int, CarbonInterface|string|int>  $expectedPaidAts
     * @param  array<int, string>  $requestIds
     * @return array<int, Carbon>
     */
    private static function normalizeExpectedPaidAts(array $expectedPaidAts, array $requestIds): array
    {
        $times = [];

        foreach ($expectedPaidAts as $value) {
            $parsed = self::toCarbon($value);
            if ($parsed) {
                $times[] = $parsed;
            }
        }

        foreach ($requestIds as $requestId) {
            $fromId = self::paidAtFromRequestId((string) $requestId);
            if ($fromId) {
                $times[] = $fromId;
            }
        }

        return $times;
    }

    /**
     * @return array<int, Carbon>
     */
    private static function extractReceiptDateTimes(string $text, string $normalized): array
    {
        $haystack = strtolower($text . ' ' . $normalized);
        $haystack = preg_replace('/\s+/', ' ', $haystack) ?? $haystack;
        $found = [];

        $patterns = [
            '/(?:payment\s+date|paid\s+(?:on|at)|transaction\s+date|date(?:\s*\/\s*time)?|txn\s+time)\s*[:\-]?\s*([a-z]{3,9}\.?\s+\d{1,2},?\s+\d{4}(?:\s+\d{1,2}:\d{2}(?::\d{2})?\s*(?:am|pm)?)?)/i',
            '/\b((?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?\s+\d{1,2},?\s+\d{4}(?:\s+\d{1,2}:\d{2}(?::\d{2})?\s*(?:am|pm)?)?)\b/i',
            '/\b(\d{4}-\d{2}-\d{2}(?:[ t]\d{1,2}:\d{2}(?::\d{2})?)?)\b/',
            '/\b(\d{1,2}\/\d{1,2}\/\d{4}(?:\s+\d{1,2}:\d{2}(?::\d{2})?\s*(?:am|pm)?)?)\b/',
            '/\b(\d{1,2}-\d{1,2}-\d{4}(?:\s+\d{1,2}:\d{2}(?::\d{2})?\s*(?:am|pm)?)?)\b/',
            '/\b((?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?\s*\d{1,2},?\s*\d{4}\s*\d{1,2}:\d{2}(?::\d{2})?\s*(?:am|pm))\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $haystack, $matches)) {
                continue;
            }

            foreach ($matches[1] as $raw) {
                $parsed = self::toCarbon($raw);
                if ($parsed) {
                    $found[] = $parsed;
                }
            }
        }

        if ($found === []) {
            $compact = preg_replace('/\s+/', '', $haystack) ?? '';
            if (preg_match(
                '/(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*(\d{1,2}),?(\d{4})(\d{1,2}):(\d{2})(?::\d{2})?(am|pm)/i',
                $compact,
                $match
            )) {
                $parsed = self::toCarbon(
                    $match[1] . ' ' . $match[2] . ', ' . $match[3] . ' ' . $match[4] . ':' . $match[5] . ' ' . $match[6]
                );
                if ($parsed) {
                    $found[] = $parsed;
                }
            }
        }

        return $found;
    }

    /**
     * @param  array<int, Carbon>  $receiptTimes
     * @param  array<int, Carbon>  $expectedTimes
     */
    private static function receiptDateMatches(array $receiptTimes, array $expectedTimes): bool
    {
        foreach ($receiptTimes as $found) {
            foreach ($expectedTimes as $expected) {
                if (! $found->isSameDay($expected)) {
                    continue;
                }

                if (! self::hasClockTime($found)) {
                    return true;
                }

                if (abs($found->diffInMinutes($expected)) <= self::TIME_WINDOW_MINUTES) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hasClockTime(Carbon $value): bool
    {
        return $value->hour !== 0 || $value->minute !== 0 || $value->second !== 0;
    }

    private static function paidAtFromRequestId(string $requestId): ?Carbon
    {
        $id = self::foldOcr(self::normalizeId($requestId));
        if (! preg_match('/^WF(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $id, $match)) {
            return null;
        }

        try {
            return Carbon::create(
                2000 + (int) $match[1],
                (int) $match[2],
                (int) $match[3],
                (int) $match[4],
                (int) $match[5],
                (int) $match[6],
                self::timezone()
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->timezone(self::timezone());
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value) && strlen($value) >= 10)) {
            try {
                return Carbon::createFromTimestamp((int) $value, self::timezone());
            } catch (Throwable) {
                return null;
            }
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw, self::timezone());
        } catch (Throwable) {
            return null;
        }
    }

    private static function timezone(): string
    {
        try {
            if (function_exists('config')) {
                $zone = (string) config('app.timezone');
                if ($zone !== '') {
                    return $zone;
                }
            }
        } catch (Throwable) {
            // Unit tests and bare autoload have no app container.
        }

        return 'Asia/Manila';
    }

    /**
     * @param  array<int, string>  $requestIds
     */
    private static function matchedRequestId(string $compact, array $requestIds): ?string
    {
        $haystack = strtoupper($compact);
        $foldedHaystack = self::foldOcr($haystack);

        foreach ($requestIds as $requestId) {
            $id = self::normalizeId((string) $requestId);
            if (strlen($id) < 8) {
                continue;
            }

            if (str_contains($haystack, $id) || str_contains($foldedHaystack, self::foldOcr($id))) {
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
        $patterns = [
            '/wf[0-9]{12}[a-z0-9]{8}/i',
            '/wf[0-9oOlLiI]{12}[a-z0-9]{6,10}/i',
            '/wf[a-z0-9]{18,24}/i',
        ];
        $found = [];

        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $compact, $matches)) {
                continue;
            }
            foreach ($matches[0] as $value) {
                $found[] = self::normalizeId($value);
            }
        }

        return array_values(array_unique(array_filter(
            $found,
            fn (string $value) => strlen($value) >= 18
        )));
    }

    /**
     * @param  array<int, string>  $extractedRequestIds
     * @param  array<int, string>  $requestIds
     * @param  array<int, Carbon>  $receiptTimes
     * @param  array<int, Carbon>  $expectedTimes
     */
    private static function alignedExpectedId(
        array $extractedRequestIds,
        array $requestIds,
        array $receiptTimes,
        array $expectedTimes,
        bool $hasBrand,
        bool $hasAmount,
        bool $hasSuccess
    ): ?string {
        if (! $hasAmount || (! $hasBrand && ! $hasSuccess) || $requestIds === []) {
            return null;
        }

        $dateMatches = $receiptTimes !== []
            && $expectedTimes !== []
            && self::receiptDateMatches($receiptTimes, $expectedTimes);

        foreach ($extractedRequestIds as $extracted) {
            $fromExtracted = self::paidAtFromRequestId(self::foldOcr($extracted));
            if ($fromExtracted && $expectedTimes !== [] && self::receiptDateMatches([$fromExtracted], $expectedTimes)) {
                return self::closestRequestId($requestIds, $fromExtracted);
            }
        }

        if ($extractedRequestIds === [] && $dateMatches) {
            return self::closestRequestId($requestIds, $receiptTimes[0] ?? null);
        }

        return null;
    }

    /**
     * @param  array<int, string>  $requestIds
     */
    private static function closestRequestId(array $requestIds, ?Carbon $anchor): string
    {
        $best = (string) ($requestIds[0] ?? '');
        if (! $anchor) {
            return $best;
        }

        $bestDiff = null;
        foreach ($requestIds as $requestId) {
            $fromId = self::paidAtFromRequestId((string) $requestId);
            if (! $fromId) {
                continue;
            }
            $diff = abs($fromId->diffInMinutes($anchor));
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best = (string) $requestId;
            }
        }

        return $best;
    }

    private static function foldOcr(string $value): string
    {
        return strtr(strtoupper($value), [
            'O' => '0',
            'Q' => '0',
            'D' => '0',
            'I' => '1',
            'L' => '1',
            'S' => '5',
            'Z' => '2',
            'B' => '8',
            'G' => '6',
        ]);
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

        $foldedExpected = self::foldOcr($expected);
        $foldedFound = self::foldOcr($found);
        if ($foldedExpected === $foldedFound || str_contains($foldedFound, $foldedExpected) || str_contains($foldedExpected, $foldedFound)) {
            return true;
        }

        $maxLen = max(strlen($expected), strlen($found));
        if ($maxLen < 12 || abs(strlen($expected) - strlen($found)) > 2) {
            return false;
        }

        return levenshtein($expected, $found) <= 2
            || levenshtein($foldedExpected, $foldedFound) <= 2;
    }

    private static function normalize(string $text): string
    {
        $value = mb_strtolower($text);
        $value = str_replace(['₱', 'php'], ['php ', 'php '], $value);
        $value = preg_replace('/[^\p{L}\p{N}\p{P}\p{Z}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * @return array{valid: bool, code: string, message: string, has_brand: bool, has_amount: bool, has_success: bool, has_date: bool, matched_request_id: ?string}
     */
    private static function result(
        bool $valid,
        string $code,
        string $message,
        bool $hasBrand = false,
        bool $hasAmount = false,
        bool $hasSuccess = false,
        ?string $matchedRequestId = null,
        bool $hasDate = false
    ): array {
        return [
            'valid' => $valid,
            'code' => $code,
            'message' => $message,
            'has_brand' => $hasBrand,
            'has_amount' => $hasAmount,
            'has_success' => $hasSuccess,
            'has_date' => $hasDate,
            'matched_request_id' => $matchedRequestId,
        ];
    }
}
