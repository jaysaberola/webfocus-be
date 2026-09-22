<?php

namespace App\Support;

use App\Models\DomainCategory;
use App\Models\SalesTransaction;
use Illuminate\Support\Collection;
use Throwable;

class DealMeta
{
    private const PREFIX = '[DEAL_META]';

    private const FALLBACK_PRICES = [
        'Country Level Domain' => 3456.0,
        'Top Level Domain' => 1728.0,
        'Hybrid Top Level Domain' => 4032.0,
        'Educational Domain' => 5304.0,
        'Government Domain' => 5184.0,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function parse(?string $notes): array
    {
        $text = (string) $notes;
        $marker = strpos($text, self::PREFIX);
        if ($marker === false) {
            return [];
        }

        $jsonLine = strtok(substr($text, $marker + strlen(self::PREFIX)), "\n") ?: '';
        $jsonLine = trim($jsonLine);
        if ($jsonLine === '' || ! str_starts_with($jsonLine, '{')) {
            return [];
        }

        $decoded = json_decode($jsonLine, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function domainName(?string $notes): string
    {
        $meta = self::parse($notes);
        $value = trim((string) ($meta['domainName'] ?? ''));
        if ($value === '' || $value === '—') {
            return '';
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = strtolower(trim(explode('/', $value)[0] ?? $value, '.'));

        return $value;
    }

    public static function domainType(?string $notes, ?string $domainName = null): string
    {
        $meta = self::parse($notes);
        $type = trim((string) ($meta['domainType'] ?? ''));
        if ($type !== '') {
            return $type;
        }

        $host = $domainName ?: self::domainName($notes);
        if ($host === '') {
            return 'Domain Registration';
        }

        if (str_ends_with($host, '.gov.ph')) {
            return 'Government Domain';
        }
        if (str_ends_with($host, '.edu.ph')) {
            return 'Educational Domain';
        }
        if (preg_match('/\.(ph|com\.ph|net\.ph|org\.ph)$/', $host)) {
            return 'Country Level Domain';
        }

        $parts = explode('.', $host);
        $tld = $parts[array_key_last($parts)] ?? '';

        return strlen($tld) === 2 ? 'Country Level Domain' : 'Top Level Domain';
    }

    public static function domainCost(?string $notes, ?string $domainType = null): float
    {
        $type = $domainType ?: self::domainType($notes);
        $catalog = self::FALLBACK_PRICES[$type] ?? 0.0;
        if ($catalog > 0) {
            return round($catalog, 2);
        }

        $meta = self::parse($notes);
        $cost = (float) ($meta['domainRegistrationCost'] ?? 0);

        return $cost > 0 ? round($cost, 2) : 0.0;
    }

    /**
     * @return array{product_id: null, name: string, price: float, item_type: string}|null
     */
    public static function pricedCatalogItem(string $name): ?array
    {
        $type = self::matchType($name);
        if ($type === null) {
            return null;
        }

        $price = self::FALLBACK_PRICES[$type] ?? 0.0;
        try {
            $categories = DomainCategory::query()
                ->where('active', true)
                ->get(['name', 'selling_price']);
            foreach ($categories as $category) {
                if (self::matchType((string) $category->name) !== $type) {
                    continue;
                }
                $selling = (float) $category->selling_price;
                if ($selling > 0) {
                    $price = $selling;
                    break;
                }
            }
        } catch (Throwable) {
            // Keep the fallback selling price when domain categories are unavailable.
        }

        if ($price <= 0) {
            return null;
        }

        return [
            'product_id' => null,
            'name' => $type,
            'price' => round($price, 2),
            'item_type' => 'domain',
        ];
    }

    public static function matchType(string $name): ?string
    {
        $norm = strtolower(trim(preg_replace('/[^a-z0-9]+/', ' ', $name) ?? ''));
        $norm = trim(preg_replace('/\s+/', ' ', $norm) ?? $norm);
        if ($norm === '') {
            return null;
        }

        $aliases = [
            'country level domain' => 'Country Level Domain',
            'country level domains' => 'Country Level Domain',
            'top level domain' => 'Top Level Domain',
            'top level domains' => 'Top Level Domain',
            'hybrid top level domain' => 'Hybrid Top Level Domain',
            'hybrid top level domains' => 'Hybrid Top Level Domain',
            'educational domain' => 'Educational Domain',
            'education domain' => 'Educational Domain',
            'education domains' => 'Educational Domain',
            'government domain' => 'Government Domain',
            'government domains' => 'Government Domain',
        ];

        foreach ($aliases as $alias => $type) {
            if ($norm === $alias || str_starts_with($norm, $alias.' ')) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, mixed>|iterable<int, mixed>|null  $items
     */
    public static function itemsIncludeDomain($items, string $domainName, string $domainType): bool
    {
        $host = strtolower(trim($domainName));
        $type = strtolower(trim($domainType));
        if ($host === '') {
            return false;
        }

        foreach (Collection::make($items ?? []) as $item) {
            $name = strtolower(trim((string) (is_array($item) ? ($item['name'] ?? '') : ($item->name ?? ''))));
            $itemType = strtolower(trim((string) (is_array($item) ? ($item['item_type'] ?? '') : ($item->item_type ?? ''))));
            if ($name === '' && $itemType === '') {
                continue;
            }
            if ($name === $host || $name === $type) {
                return true;
            }
            if ($itemType === 'domain' && ($name === $type || str_contains($name, $host))) {
                return true;
            }
            if (str_contains($name, 'domain') && str_contains($name, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Domain registration amount that is in DEAL_META but not yet a sales item.
     */
    public static function missingAmount(SalesTransaction $row): float
    {
        $domainName = self::domainName($row->notes);
        if ($domainName === '') {
            return 0.0;
        }

        $domainType = self::domainType($row->notes, $domainName);
        if (self::itemsIncludeDomain($row->items ?? collect(), $domainName, $domainType)) {
            return 0.0;
        }

        return self::domainCost($row->notes, $domainType);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function mappedLine(SalesTransaction $row): ?array
    {
        $domainName = self::domainName($row->notes);
        if ($domainName === '') {
            return null;
        }

        $domainType = self::domainType($row->notes, $domainName);
        if (self::itemsIncludeDomain($row->items ?? collect(), $domainName, $domainType)) {
            return null;
        }

        $amount = self::domainCost($row->notes, $domainType);

        return [
            'id' => 'domain-'.$row->id,
            'name' => $domainType.' ('.$domainName.')',
            'detail' => $domainType,
            'itemType' => 'domain',
            'quantity' => 1,
            'unitPrice' => $amount,
            'price' => $amount,
            'total' => $amount,
        ];
    }
}
