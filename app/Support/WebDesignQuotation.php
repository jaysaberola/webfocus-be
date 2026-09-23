<?php

namespace App\Support;

use App\Models\SalesTransaction;
use App\Support\DealMeta;

class WebDesignQuotation
{
    public const PENDING_MARKER = 'Pricing: Pending Quotation';
    public const PRICE_SET_MARKER = 'Pricing: Set by Sales';
    public const PROPOSAL_SUBMITTED = 'Proposal: Submitted';
    public const PROPOSAL_SIGNED = 'Proposal: Signed';
    public const PAYMENT_REQUESTED = 'Payment: Requested';

    public static function notesOf(SalesTransaction $row): string
    {
        return (string) ($row->notes ?? '');
    }

    public static function hasMarker(SalesTransaction $row, string $marker): bool
    {
        return str_contains(strtolower(self::notesOf($row)), strtolower($marker));
    }

    public static function isWebDesign(SalesTransaction $row): bool
    {
        $items = $row->items ?? collect();
        $isWeb = $items->contains(function ($item) {
            $type = strtolower((string) ($item->item_type ?? ''));
            $name = strtolower((string) ($item->name ?? ''));

            return str_contains($type, 'web_design')
                || str_contains($type, 'webdesign')
                || str_contains($type, 'web_development')
                || str_contains($type, 'webdevelopment')
                || str_contains($name, 'web design')
                || str_contains($name, 'web development')
                || str_contains($name, 'web-dev')
                || str_contains($name, 'starter launch')
                || str_contains($name, 'professional corporate')
                || str_contains($name, 'e-commerce')
                || str_contains($name, 'website template');
        });

        if ($isWeb) {
            return true;
        }

        // Items are present and none are web design — do not treat a sibling
        // "pending quotation" note as making this priced invoice a quotation.
        if ($items->isNotEmpty()) {
            return false;
        }

        $notes = strtolower(self::notesOf($row));

        return str_contains($notes, strtolower(self::PENDING_MARKER))
            || str_contains($notes, 'agency web design')
            || str_contains($notes, 'custom web design')
            || str_contains($notes, 'web development');
    }

    public static function isPaid(SalesTransaction $row): bool
    {
        return in_array(strtolower((string) $row->payment_status), ['paid', 'completed', 'success'], true);
    }

    public static function isPaymentRequested(SalesTransaction $row): bool
    {
        return self::hasMarker($row, self::PAYMENT_REQUESTED);
    }

    public static function isPendingQuotation(SalesTransaction $row): bool
    {
        if (! self::isWebDesign($row) || self::isPaid($row)) {
            return false;
        }

        return ! self::isPaymentRequested($row);
    }

    public static function displayAmount(SalesTransaction $row): float
    {
        $stored = (float) $row->grand_total;
        $fromItems = 0.0;
        if ($row->relationLoaded('items') && $row->items && $row->items->isNotEmpty()) {
            $fromItems = (float) $row->items->sum(function ($item) {
                $total = (float) ($item->total_price ?? 0);
                if ($total > 0) {
                    return $total;
                }

                return (float) ($item->price ?? 0) * max(1, (float) ($item->quantity ?? 1));
            });
        }

        $computed = round(max($stored, $fromItems + DealMeta::missingAmount($row)), 2);
        if (self::isPendingQuotation($row) && $computed <= 0) {
            return 0.0;
        }

        return $computed;
    }

    public static function appendMarker(?string $notes, string $marker): string
    {
        $current = trim((string) $notes);
        if ($current !== '' && str_contains(strtolower($current), strtolower($marker))) {
            return $current;
        }

        return $current === '' ? $marker : $marker . "\n" . $current;
    }
}
