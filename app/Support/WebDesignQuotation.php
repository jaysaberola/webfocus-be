<?php

namespace App\Support;

use App\Models\SalesTransaction;

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
                || str_contains($name, 'web design')
                || str_contains($name, 'starter launch')
                || str_contains($name, 'professional corporate')
                || str_contains($name, 'e-commerce')
                || str_contains($name, 'website template');
        });

        if ($isWeb) {
            return true;
        }

        $notes = strtolower(self::notesOf($row));

        return str_contains($notes, 'pending quotation')
            || str_contains($notes, 'agency web design')
            || str_contains($notes, 'custom web design');
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
        return self::isPendingQuotation($row) ? 0.0 : (float) $row->grand_total;
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
