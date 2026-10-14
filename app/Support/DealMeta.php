<?php

namespace App\Support;

use App\Models\DomainCategory;
use App\Models\SalesTransaction;
use Carbon\Carbon;
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

    public static function present(?string $notes): bool
    {
        return self::parse($notes) !== [];
    }

    /**
     * @return array<string, float>
     */
    public static function amountMap(?string $notes, string $key): array
    {
        $raw = self::parse($notes)[$key] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $name => $value) {
            $amount = round((float) $value, 2);
            $norm = self::normalizeName((string) $name);
            if ($norm === '' || $amount <= 0) {
                continue;
            }
            $out[$norm] = $amount;
        }

        return $out;
    }

    /**
     * @param  array<string, float>  $map
     */
    public static function amountFor(array $map, string ...$names): float
    {
        foreach ($names as $name) {
            $norm = self::normalizeName($name);
            if ($norm !== '' && isset($map[$norm])) {
                return $map[$norm];
            }
        }

        return 0.0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, discountTotal: float, taxTotal: float}
     */
    public static function applyLineAdjustments(array $items, SalesTransaction $row): array
    {
        $discounts = self::amountMap($row->notes, 'dealDiscounts');
        $taxes = self::amountMap($row->notes, 'dealTaxes');
        $amounts = self::amountMap($row->notes, 'dealAmounts');
        $next = [];

        foreach ($items as $item) {
            $detail = trim((string) ($item['detail'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            $quoted = self::amountFor($amounts, $detail, $name);
            if ($quoted > 0) {
                $quantity = max(1.0, (float) ($item['quantity'] ?? 1));
                $item['unitPrice'] = $quoted;
                $item['price'] = round($quoted * $quantity, 2);
                $item['total'] = round($quoted * $quantity, 2);
            }
            $item['discount'] = self::amountFor($discounts, $detail, $name);
            $item['tax'] = self::amountFor($taxes, $detail, $name);
            $next[] = $item;
        }

        $discountTotal = round(array_sum(array_map(fn ($item) => (float) ($item['discount'] ?? 0), $next)), 2);
        $taxTotal = round(array_sum(array_map(fn ($item) => (float) ($item['tax'] ?? 0), $next)), 2);
        $headerDiscount = round((float) $row->discount_total, 2);
        $headerTax = round((float) $row->tax_total, 2);

        if ($discountTotal <= 0 && $headerDiscount > 0 && $next !== []) {
            $next[0]['discount'] = $headerDiscount;
            $discountTotal = $headerDiscount;
        }
        if ($taxTotal <= 0 && $headerTax > 0 && $next !== []) {
            $next[0]['tax'] = $headerTax;
            $taxTotal = $headerTax;
        }

        $subtotal = round(array_sum(array_map(
            fn ($item) => (float) ($item['total'] ?? $item['price'] ?? 0),
            $next
        )), 2);

        return [
            'items' => $next,
            'subtotal' => $subtotal,
            'discountTotal' => $discountTotal > 0 ? $discountTotal : $headerDiscount,
            'taxTotal' => $taxTotal > 0 ? $taxTotal : $headerTax,
        ];
    }

    private static function normalizeName(string $name): string
    {
        $norm = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $name) ?? ''));

        return trim(preg_replace('/\s+/', ' ', $norm) ?? $norm);
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
        if (WebDesignMeta::isAttachmentLabel($value)) {
            return '';
        }

        return $value;
    }

    public static function productCategory(?string $notes): string
    {
        $value = trim((string) (self::parse($notes)['productCategory'] ?? ''));
        if ($value === '' || $value === '—') {
            return '';
        }

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
            'discount' => 0,
            'tax' => 0,
        ];
    }

    public static function clientNotes(?string $notes): string
    {
        return trim((string) (self::parse($notes)['clientNotes'] ?? ''));
    }

    public static function salesNotes(?string $notes): string
    {
        return trim((string) (self::parse($notes)['salesNotes'] ?? ''));
    }

    public static function quotedPrice(?string $notes): float
    {
        $value = round((float) (self::parse($notes)['quotedPrice'] ?? 0), 2);

        return $value > 0 ? $value : 0.0;
    }

    /**
     * @param  array{orderNo?: string, items?: iterable<mixed>, subtotal?: float, discount?: float, tax?: float}  $context
     */
    public static function pinQuotedPrice(?string $notes, float $amount, array $context = []): string
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return (string) $notes;
        }

        $current = self::quotedPrice($notes);
        $text = (string) $notes;
        $meta = self::parse($text);
        $meta['quotedPrice'] = $amount;
        $text = self::replaceMeta($text === '' && $meta === [] ? '[DEAL_META]{}' : $text, $meta);
        $summary = self::quoteSummary($amount, $text, $context);
        if (self::latestQuoteText($text) === $summary) {
            return $text;
        }
        if (abs($current - $amount) < 0.01 && empty($context['refreshLines'])) {
            return $text;
        }

        return self::appendSalesNote($text, $summary);
    }

    private static function latestQuoteText(?string $notes): string
    {
        $thread = self::conversation($notes);
        for ($index = count($thread) - 1; $index >= 0; $index--) {
            if (($thread[$index]['from'] ?? '') !== 'sales') {
                continue;
            }
            $existing = trim((string) ($thread[$index]['text'] ?? ''));
            if (stripos($existing, 'A quoted price is ready') !== false && stripos($existing, 'Grand total:') !== false) {
                return $existing;
            }
        }

        return '';
    }

    /**
     * @param  array{orderNo?: string, items?: iterable<mixed>, subtotal?: float, discount?: float, tax?: float}  $context
     */
    public static function quoteSummary(float $amount, ?string $notes = null, array $context = []): string
    {
        $peso = static fn (float $value) => '₱'.number_format($value, 2);
        $orderNo = trim((string) ($context['orderNo'] ?? ''));
        $lines = [
            'A quoted price is ready'.($orderNo !== '' ? ' for '.$orderNo : '').'.',
        ];

        $dealName = trim((string) (self::parse($notes)['dealName'] ?? self::parse($notes)['productName'] ?? ''));
        $category = self::productCategory($notes);
        if ($dealName !== '' || $category !== '') {
            $lines[] = '';
            if ($dealName !== '') {
                $lines[] = 'Deal: '.$dealName;
            }
            if ($category !== '' && strcasecmp($category, $dealName) !== 0) {
                $lines[] = 'Category: '.$category;
            }
        }

        $rows = WebDesignMeta::amountDetailRows($context['items'] ?? [], $notes);
        if ($rows === []) {
            foreach (WebDesignMeta::additionalServices($notes) as $feature) {
                $rows[] = ['label' => 'Included service', 'value' => $feature];
            }
        }
        if ($rows !== []) {
            $lines[] = '';
            $lines[] = 'Services';
            foreach ($rows as $row) {
                $label = trim((string) ($row['label'] ?? 'Items'));
                $value = trim((string) ($row['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $lines[] = $label === 'Included service'
                    ? '• '.$value.(str_contains($value, '₱') ? '' : ' — included')
                    : '• '.$value;
            }
        }

        $subtotal = round((float) ($context['subtotal'] ?? 0), 2);
        $discount = round((float) ($context['discount'] ?? 0), 2);
        $tax = round((float) ($context['tax'] ?? 0), 2);
        $lines[] = '';
        if ($subtotal > 0) {
            $lines[] = 'Subtotal: '.$peso($subtotal);
        }
        if ($discount > 0) {
            $lines[] = 'Discount: '.$peso($discount);
        }
        if ($tax > 0) {
            $lines[] = 'Tax: '.$peso($tax);
        }
        $lines[] = 'Grand total: '.$peso($amount);
        $lines[] = '';
        $lines[] = 'Status is still Pending Quotation. You can review every line here and keep talking about this price. Payment is requested only after the proposal is signed.';

        return trim(implode("\n", $lines));
    }

    public static function withConversation(?string $notes, ?string $clientNotes, ?string $salesNotes): string
    {
        $text = (string) $notes;
        $meta = self::parse($text);
        if ($clientNotes !== null) {
            $value = trim($clientNotes);
            if ($value === '') {
                unset($meta['clientNotes']);
            } else {
                $meta['clientNotes'] = $value;
            }
        }
        if ($salesNotes !== null) {
            $value = trim($salesNotes);
            if ($value === '') {
                unset($meta['salesNotes']);
            } else {
                $meta['salesNotes'] = $value;
            }
        }

        return self::replaceMeta($text, $meta);
    }

    /**
     * @return list<array{from: string, text: string, at?: string, file?: array{name: string, url: string, kind: string}}>
     */
    public static function conversation(?string $notes): array
    {
        $meta = self::parse($notes);
        $raw = $meta['conversation'] ?? null;
        if (is_array($raw) && $raw !== []) {
            return self::normalizeConversation($raw);
        }

        $messages = [];
        $client = self::clientNotes($notes);
        if ($client === '') {
            $client = WebDesignMeta::clientNotes($notes);
        }
        foreach (preg_split('/\n{2,}/', $client) ?: [] as $text) {
            $text = trim((string) $text);
            if ($text !== '') {
                $messages[] = ['from' => 'client', 'text' => $text];
            }
        }
        $sales = self::salesNotes($notes);
        if ($sales === '') {
            $sales = WebDesignMeta::salesNotes($notes);
        }
        foreach (preg_split('/\n{2,}/', $sales) ?: [] as $text) {
            $text = trim((string) $text);
            if ($text !== '') {
                $messages[] = ['from' => 'sales', 'text' => $text];
            }
        }

        return $messages;
    }

    /**
     * @param  array{name?: string, url?: string, kind?: string}|null  $file
     */
    public static function appendClientNote(?string $notes, string $message, ?array $file = null): string
    {
        return self::appendMessage($notes, 'client', $message, $file);
    }

    /**
     * @param  array{name?: string, url?: string, kind?: string}|null  $file
     */
    public static function appendSalesNote(?string $notes, string $message, ?array $file = null): string
    {
        return self::appendMessage($notes, 'sales', $message, $file);
    }

    /**
     * @param  array{name?: string, url?: string, kind?: string}|null  $file
     */
    public static function appendMessage(?string $notes, string $from, string $message, ?array $file = null): string
    {
        $fileMeta = self::normalizeFile($file);
        $message = self::messageText(trim($message), $fileMeta);
        $from = $from === 'sales' ? 'sales' : 'client';
        if ($message === '') {
            return (string) $notes;
        }

        $thread = self::conversation($notes);
        $entry = [
            'from' => $from,
            'text' => $message,
            'at' => date('c'),
        ];
        if ($fileMeta !== null) {
            $entry['file'] = $fileMeta;
        }
        $thread[] = $entry;

        $client = [];
        $sales = [];
        foreach ($thread as $row) {
            if (($row['from'] ?? '') === 'sales') {
                $sales[] = $row['text'];
            } else {
                $client[] = $row['text'];
            }
        }

        $clientText = implode("\n\n", $client);
        $salesText = implode("\n\n", $sales);
        $updated = self::withConversation($notes, $clientText, $salesText);
        $meta = self::parse($updated);
        $meta['conversation'] = $thread;
        $updated = self::replaceMeta($updated, $meta);

        if (
            WebDesignMeta::parse($updated) !== []
            || WebDesignMeta::clientNotes($notes) !== ''
            || WebDesignMeta::salesNotes($notes) !== ''
        ) {
            $updated = WebDesignMeta::withConversation($updated, $clientText, $salesText);
        }

        return $updated;
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return list<array{from: string, text: string, at?: string, file?: array{name: string, url: string, kind: string}}>
     */
    private static function normalizeConversation(array $raw): array
    {
        $messages = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $file = self::normalizeFile($row['file'] ?? null);
            $text = self::messageText(trim((string) ($row['text'] ?? '')), $file);
            if ($text === '') {
                continue;
            }
            $from = ($row['from'] ?? '') === 'sales' ? 'sales' : 'client';
            $message = ['from' => $from, 'text' => $text];
            $at = trim((string) ($row['at'] ?? ''));
            if ($at !== '') {
                $message['at'] = $at;
            }
            if ($file !== null) {
                $message['file'] = $file;
            }
            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>|null  $file
     * @return array{name: string, url: string, path?: string, kind: string}|null
     */
    private static function normalizeFile(mixed $file): ?array
    {
        if (! is_array($file)) {
            return null;
        }
        $name = trim((string) ($file['name'] ?? ''));
        $path = DealConversationFile::filePath($file);
        $url = trim((string) ($file['url'] ?? ''));
        if ($url === '' && $path !== '') {
            $url = StorageUrl::publicAsset($path) ?: '';
        }
        if ($name === '' && $url === '' && $path === '') {
            return null;
        }
        $kind = trim((string) ($file['kind'] ?? 'file'));
        if (! in_array($kind, ['file', 'proposal', 'signed'], true)) {
            $kind = 'file';
        }

        $normalized = [
            'name' => $name !== '' ? $name : 'Attachment',
            'url' => $url,
            'kind' => $kind,
        ];
        if ($path !== '') {
            $normalized['path'] = $path;
        }

        return $normalized;
    }

    /**
     * @param  array{name?: string, url?: string, kind?: string}|null  $file
     */
    private static function messageText(string $message, ?array $file): string
    {
        if ($message !== '') {
            return $message;
        }
        if ($file === null) {
            return '';
        }
        $name = trim((string) ($file['name'] ?? 'file'));
        $kind = (string) ($file['kind'] ?? 'file');

        return match ($kind) {
            'proposal' => 'Uploaded a proposal quotation: '.$name,
            'signed' => 'Uploaded the signed proposal: '.$name,
            default => 'Sent an attachment: '.$name,
        };
    }

    public const REGISTRATION_YEARS = 9;

    public const SSL_WILDCARD_YEARS = 9;

    public const DOMAIN_SUBSCRIPTION_YEARS = 10;

    /**
     * Registration and wildcard SSL expirations are owned here.
     * Domain subscriptions run 10 years from the sales-entered start date.
     *
     * @param  iterable<int, mixed>  $itemNames
     */
    public static function applyManagedDates(?string $notes, iterable $itemNames = []): string
    {
        $text = (string) $notes;
        $meta = self::parse($text);
        if ($meta === []) {
            return $text;
        }

        $names = [];
        foreach ($itemNames as $name) {
            $names[] = (string) $name;
        }
        foreach (['dealName', 'productName', 'productCategory'] as $key) {
            $names[] = (string) ($meta[$key] ?? '');
        }
        if (isset($meta['dealNames']) && is_array($meta['dealNames'])) {
            foreach ($meta['dealNames'] as $name) {
                $names[] = (string) $name;
            }
        }

        unset($meta['statusTriggerDate']);

        $registrationStart = self::dateOrNull($meta['domainRegistrationStartDate'] ?? null)
            ?? self::dateOrNull($meta['expirationStartDate'] ?? null);
        if ($registrationStart) {
            $meta['domainRegistrationExpirationDate'] = $registrationStart
                ->copy()
                ->addYears(self::REGISTRATION_YEARS)
                ->format('Y-m-d');
        }

        if (self::namesIncludeWildcardSsl($names)) {
            $sslStart = self::dateOrNull($meta['expirationStartDate'] ?? null)
                ?? self::dateOrNull($meta['domainRegistrationStartDate'] ?? null)
                ?? self::dateOrNull($meta['subscriptionStartDate'] ?? null);
            if ($sslStart) {
                $meta['sslWildcardExpirationDate'] = $sslStart
                    ->copy()
                    ->addYears(self::SSL_WILDCARD_YEARS)
                    ->format('Y-m-d');
            }
        }

        $domainStart = self::dateOrNull($meta['domainSubscriptionStartDate'] ?? null);
        if ($domainStart) {
            $meta['domainSubscriptionEndDate'] = $domainStart
                ->copy()
                ->addYears(self::DOMAIN_SUBSCRIPTION_YEARS)
                ->format('Y-m-d');
        }

        $subscriptionStart = self::dateOrNull($meta['subscriptionStartDate'] ?? null) ?? $domainStart;
        if ($subscriptionStart && self::usesDomainSubscription($meta)) {
            $meta['subscriptionEndDate'] = $subscriptionStart
                ->copy()
                ->addYears(self::DOMAIN_SUBSCRIPTION_YEARS)
                ->format('Y-m-d');
        } elseif ($subscriptionStart) {
            $months = self::paymentTermMonths((string) ($meta['paymentTerms'] ?? ''));
            if ($months) {
                $meta['subscriptionEndDate'] = $subscriptionStart->copy()->addMonths($months)->format('Y-m-d');
            }
        }

        return self::replaceMeta($text, $meta);
    }

    /**
     * Finance tracks the registration or wildcard expiration, not the client subscription.
     */
    public static function financeExpirationDate(?string $notes): ?Carbon
    {
        $meta = self::parse($notes);
        $dates = array_values(array_filter([
            self::dateOrNull($meta['domainRegistrationExpirationDate'] ?? null),
            self::dateOrNull($meta['sslWildcardExpirationDate'] ?? null),
        ]));

        if ($dates === []) {
            $start = self::dateOrNull($meta['expirationStartDate'] ?? null)
                ?? self::dateOrNull($meta['domainRegistrationStartDate'] ?? null);

            return $start?->copy()->addYears(self::REGISTRATION_YEARS);
        }

        usort($dates, fn (Carbon $left, Carbon $right) => $left->getTimestamp() <=> $right->getTimestamp());

        return $dates[0];
    }

    /**
     * Clients track the subscription period entered by sales.
     */
    public static function clientSubscriptionEnd(?string $notes): ?Carbon
    {
        $meta = self::parse($notes);
        $domainEnd = self::dateOrNull($meta['domainSubscriptionEndDate'] ?? null);
        $dealEnd = self::dateOrNull($meta['subscriptionEndDate'] ?? null);
        if (self::usesDomainSubscription($meta) || self::domainName($notes) !== '') {
            return $domainEnd ?? $dealEnd;
        }

        return $dealEnd ?? $domainEnd;
    }

    /**
     * @param  array<int, string>  $names
     */
    private static function namesIncludeWildcardSsl(array $names): bool
    {
        foreach ($names as $name) {
            if (preg_match('/wildcard\s*ssl/i', $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function usesDomainSubscription(array $meta): bool
    {
        $category = strtolower(trim((string) ($meta['productCategory'] ?? '')));
        if ($category === 'domain registration') {
            return true;
        }

        return self::dateOrNull($meta['domainSubscriptionStartDate'] ?? null) !== null;
    }

    private static function paymentTermMonths(string $terms): ?int
    {
        $text = strtolower($terms);
        if ($text === '') {
            return null;
        }
        if (str_contains($text, 'quinquennial')) {
            return 60;
        }
        if (str_contains($text, 'quadrennial')) {
            return 48;
        }
        if (str_contains($text, 'triennial')) {
            return 36;
        }
        if (str_contains($text, 'biennial')) {
            return 24;
        }
        if (str_contains($text, 'semi-annual') || str_contains($text, 'semiannual')) {
            return 6;
        }
        if (str_contains($text, 'annual')) {
            return 12;
        }
        if (str_contains($text, 'quarter')) {
            return 3;
        }
        if (str_contains($text, 'month')) {
            return 1;
        }

        return null;
    }

    private static function dateOrNull(mixed $value): ?Carbon
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function replaceMeta(string $notes, array $meta): string
    {
        $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $metaLine = self::PREFIX.($encoded === false ? '{}' : $encoded);
        $marker = strpos($notes, self::PREFIX);
        if ($marker === false) {
            return trim($metaLine."\n".$notes);
        }

        $before = trim(substr($notes, 0, $marker));
        $afterMarker = substr($notes, $marker + strlen(self::PREFIX));
        $newline = strpos($afterMarker, "\n");
        $after = $newline === false ? '' : trim(substr($afterMarker, $newline + 1));

        return implode("\n", array_values(array_filter(
            [$before, $metaLine, $after],
            fn ($part) => $part !== ''
        )));
    }
}
