<?php

namespace App\Support;

use App\Models\PaynamicsPaymentReference;
use App\Models\SalesTransaction;
use Carbon\Carbon;
use Throwable;

class RelatedPaymentSync
{
    public static function apply(SalesTransaction $transaction, string $paymentMode, ?Carbon $paidAt = null): void
    {
        $mode = trim($paymentMode);
        $date = $paidAt?->toDateString();
        $notes = (string) $transaction->notes;

        if ($mode !== '') {
            $notes = self::upsertLine($notes, 'Payment mode', $mode);
            $notes = self::upsertLine($notes, 'Payment method', 'Paynamics ('.$mode.')');
        }

        if ($date) {
            $notes = self::upsertLine($notes, 'Payment date', $date);
        }

        $dealPatch = array_filter([
            'paymentMode' => $mode !== '' ? $mode : null,
            'paymentDate' => $date,
            'paymentStatus' => $date ? 'Paid' : null,
        ], fn ($value) => is_string($value) && $value !== '');

        $invoicePatch = array_filter([
            'paymentMode' => $mode !== '' ? $mode : null,
            'paymentDate' => $date,
            'status' => $date ? 'Paid' : null,
        ], fn ($value) => is_string($value) && $value !== '');

        if ($dealPatch !== []) {
            $notes = self::patchPrefixedJson($notes, '[DEAL_META]', $dealPatch);
        }
        if ($invoicePatch !== []) {
            $notes = self::patchPrefixedJson($notes, '[INVOICE_META]', $invoicePatch);
        }

        $transaction->notes = $notes;
        $transaction->save();
    }

    public static function dateFrom(SalesTransaction $transaction): ?string
    {
        $paid = self::paidReference($transaction);
        if ($paid?->paid_at) {
            return $paid->paid_at->toDateString();
        }

        $fromMeta = self::metaValue((string) $transaction->notes, 'paymentDate');
        if ($fromMeta) {
            return self::toDateString($fromMeta);
        }

        if (preg_match('/Payment date:\s*([^\n]+)/i', (string) $transaction->notes, $matches)) {
            return self::toDateString(trim($matches[1]));
        }

        return null;
    }

    public static function modeFrom(SalesTransaction $transaction): ?string
    {
        $paid = self::paidReference($transaction);
        $stored = trim((string) ($paid?->payment_method ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $fromMeta = self::metaValue((string) $transaction->notes, 'paymentMode');
        if ($fromMeta) {
            return $fromMeta;
        }

        if (preg_match('/Payment mode:\s*([^\n]+)/i', (string) $transaction->notes, $matches)) {
            $mode = trim($matches[1]);
            return $mode !== '' ? $mode : null;
        }

        if (preg_match('/Payment method:\s*Paynamics\s*\(([^)]+)\)/i', (string) $transaction->notes, $matches)) {
            $mode = trim($matches[1]);
            return $mode !== '' && strcasecmp($mode, 'Paynamics') !== 0 ? $mode : null;
        }

        return null;
    }

    private static function paidReference(SalesTransaction $transaction): ?PaynamicsPaymentReference
    {
        $references = $transaction->relationLoaded('paynamicsPaymentReferences')
            ? $transaction->paynamicsPaymentReferences
            : $transaction->paynamicsPaymentReferences()->get();

        return $references
            ->filter(fn ($reference) => strtolower((string) $reference->status) === 'paid' || filled($reference->paid_at))
            ->sortByDesc(fn ($reference) => $reference->paid_at?->timestamp ?? $reference->id)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function patchPrefixedJson(string $notes, string $prefix, array $fields): string
    {
        $marker = strpos($notes, $prefix);
        if ($marker === false) {
            return $notes;
        }

        $jsonStart = $marker + strlen($prefix);
        $line = strtok(substr($notes, $jsonStart), "\n") ?: '';
        if ($line === '' || ! str_starts_with(ltrim($line), '{')) {
            return $notes;
        }

        $decoded = json_decode($line, true);
        if (! is_array($decoded)) {
            return $notes;
        }

        $encoded = json_encode(array_merge($decoded, $fields), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            return $notes;
        }

        $pattern = '/'.preg_quote($prefix, '/').'\{.*\}/u';
        $replaced = preg_replace($pattern, $prefix.$encoded, $notes, 1);

        return is_string($replaced) ? $replaced : $notes;
    }

    private static function upsertLine(string $notes, string $label, string $value): string
    {
        $line = $label.': '.$value;
        $pattern = '/^'.preg_quote($label, '/').':\s*.+$/mi';
        if (preg_match($pattern, $notes)) {
            $replaced = preg_replace($pattern, $line, $notes, 1);

            return is_string($replaced) ? $replaced : $notes;
        }

        return trim($notes) === '' ? $line : $line."\n".$notes;
    }

    private static function metaValue(string $notes, string $key): ?string
    {
        foreach (['[DEAL_META]', '[INVOICE_META]'] as $prefix) {
            $marker = strpos($notes, $prefix);
            if ($marker === false) {
                continue;
            }

            $line = strtok(substr($notes, $marker + strlen($prefix)), "\n") ?: '';
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }

            $value = trim((string) ($decoded[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function toDateString(string $value): ?string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
