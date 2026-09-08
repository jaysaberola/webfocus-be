<?php

namespace App\Support;

use Illuminate\Support\Collection;

class TransactionLabelResolver
{
    private const WEB_DESIGN_PLANS = [
        'business starter launch',
        'custom professional corporate',
        'high-concurrency e-commerce plus',
    ];

    public static function serviceCategory(?string $name, ?string $itemType = null): string
    {
        $haystack = strtolower(trim(($name ?? '') . ' ' . ($itemType ?? '')));

        if ($haystack === '') {
            return 'Service';
        }

        if (str_contains($haystack, 'domain') || self::looksLikeDomain($name)) {
            return 'Secure Domain';
        }
        if (str_contains($haystack, 'dms') || str_contains($haystack, 'document')) {
            return 'DMS';
        }
        if (self::isWebDesignPlan($name, $itemType)) {
            return 'Custom Web Design';
        }
        if (str_contains($haystack, 'credit')) {
            return 'Account Credit';
        }
        if (
            str_contains($haystack, 'hosting')
            || str_contains($haystack, 'cloud')
            || str_contains($haystack, 'server')
            || str_contains($haystack, 'shared')
            || str_contains($haystack, 'micro')
            || str_contains($haystack, 'dedicated')
        ) {
            return 'Hosting';
        }

        return 'Hosting';
    }

    public static function serviceCategoryFromItems(?Collection $items): string
    {
        if (!$items || $items->isEmpty()) {
            return 'Service';
        }

        foreach ($items as $item) {
            if (self::isWebDesignPlan($item->name, $item->item_type)) {
                return 'Custom Web Design';
            }
        }

        $first = $items->first();

        return self::serviceCategory($first?->name, $first?->item_type);
    }

    public static function isWebDesignPlan(?string $name, ?string $itemType = null): bool
    {
        $haystack = strtolower(trim(($name ?? '') . ' ' . ($itemType ?? '')));
        if ($haystack === '') {
            return false;
        }

        if (
            str_contains($haystack, 'design')
            || str_contains($haystack, 'canvas')
            || str_contains($haystack, 'web design')
            || str_contains($haystack, 'web custom')
            || str_contains($haystack, 'agency web')
            || str_contains($haystack, 'figma')
        ) {
            return true;
        }

        $normalizedName = strtolower(trim($name ?? ''));
        if ($normalizedName !== '' && in_array($normalizedName, self::WEB_DESIGN_PLANS, true)) {
            return true;
        }

        if ($normalizedName !== '') {
            if (preg_match(
                '/business starter|professional corporate|e-?commerce plus|starter launch|website template|web design/i',
                $name
            )) {
                return true;
            }
        }

        return in_array(strtolower(trim($itemType ?? '')), ['webdesign', 'web_design', 'design'], true);
    }

    public static function planLabel(?Collection $items, ?string $fallback = null): ?string
    {
        if (!$items || $items->isEmpty()) {
            return $fallback;
        }

        $label = $items
            ->pluck('name')
            ->filter(fn ($name) => filled($name))
            ->implode(' + ');

        return $label !== '' ? $label : $fallback;
    }

    public static function issuedDateFrom(?\DateTimeInterface $transactedAt): ?string
    {
        if (!$transactedAt) {
            return null;
        }

        return \Carbon\Carbon::parse($transactedAt)->format('Y-m-d');
    }

    public static function dueDateFrom(?\DateTimeInterface $transactedAt, int $days = 30): ?string
    {
        if (!$transactedAt) {
            return null;
        }

        $due = \Carbon\Carbon::parse($transactedAt)->copy()->addDays($days);

        return $due->format('Y-m-d');
    }

    public static function looksLikeDomain(?string $name): bool
    {
        if (!$name) {
            return false;
        }

        return (bool) preg_match(
            '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/i',
            trim($name)
        );
    }
}
