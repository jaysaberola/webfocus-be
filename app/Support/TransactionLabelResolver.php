<?php

namespace App\Support;

use Illuminate\Support\Collection;

class TransactionLabelResolver
{
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
        if (
            str_contains($haystack, 'design')
            || str_contains($haystack, 'canvas')
            || str_contains($haystack, 'web design')
            || str_contains($haystack, 'web custom')
        ) {
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
