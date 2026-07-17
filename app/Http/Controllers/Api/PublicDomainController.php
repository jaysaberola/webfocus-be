<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PublicDomainController extends Controller
{
    private const DEFAULT_TLDS = [
        '.com',
        '.ph',
        '.co',
        '.net',
        '.org',
        '.shop',
        '.ai',
        '.com.ph',
        '.net.ph',
        '.org.ph',
        '.biz',
        '.info',
        '.online',
        '.io',
    ];

    private const TLD_PRICES = [
        '.com' => 899,
        '.ph' => 1499,
        '.co' => 1199,
        '.net' => 999,
        '.org' => 999,
        '.shop' => 799,
        '.ai' => 4999,
        '.com.ph' => 899,
        '.net.ph' => 899,
        '.org.ph' => 899,
        '.biz' => 799,
        '.info' => 699,
        '.online' => 699,
        '.io' => 3999,
    ];

    public function check(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i'],
            'tlds' => ['nullable', 'array'],
            'tlds.*' => ['string', 'max:20'],
        ]);

        $name = strtolower(trim($validated['name']));
        $tlds = collect($validated['tlds'] ?? self::DEFAULT_TLDS)
            ->map(fn ($tld) => $this->normalizeTld((string) $tld))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($tlds)) {
            $tlds = self::DEFAULT_TLDS;
        }

        $results = collect($tlds)->map(function (string $tld) use ($name) {
            $domain = $name . $tld;

            return [
                'domain' => $domain,
                'tld' => $tld,
                'available' => $this->isLikelyAvailable($domain),
                'price' => self::TLD_PRICES[$tld] ?? 999,
                'currency' => 'PHP',
            ];
        })->values();

        return response()->json([
            'query' => $name,
            'results' => $results,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    private function normalizeTld(string $tld): ?string
    {
        $value = trim(strtolower($tld));
        if ($value === '') {
            return null;
        }

        return str_starts_with($value, '.') ? $value : '.' . $value;
    }

    private function isLikelyAvailable(string $domain): bool
    {
        if (@checkdnsrr($domain, 'A')) {
            return false;
        }

        if (@checkdnsrr($domain, 'AAAA')) {
            return false;
        }

        if (@checkdnsrr($domain, 'NS')) {
            return false;
        }

        if (@checkdnsrr($domain, 'MX')) {
            return false;
        }

        return true;
    }
}
