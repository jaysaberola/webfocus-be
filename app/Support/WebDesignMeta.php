<?php

namespace App\Support;

class WebDesignMeta
{
    public const PREFIX = '[WEBDESIGN_META]';

    /**
     * @return array<string, mixed>
     */
    public static function parse(?string $notes): array
    {
        $text = (string) $notes;
        $marker = strpos($text, self::PREFIX);
        if ($marker !== false) {
            $jsonLine = trim(strtok(substr($text, $marker + strlen(self::PREFIX)), "\n") ?: '');
            if ($jsonLine !== '' && str_starts_with($jsonLine, '{')) {
                $decoded = json_decode($jsonLine, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    /**
     * Extra website features chosen in "Would you like to add another service?".
     *
     * @return list<string>
     */
    public static function additionalServices(?string $notes): array
    {
        $meta = self::parse($notes);
        $features = [];
        if (isset($meta['serviceFeatures']) && is_array($meta['serviceFeatures'])) {
            $features = $meta['serviceFeatures'];
        } elseif (preg_match('/Additional Services:\s*(.+)/i', (string) $notes, $match)) {
            $features = explode(',', $match[1]);
        }

        return self::uniqueLabels($features);
    }

    public static function clientNotes(?string $notes): string
    {
        $fromMeta = trim((string) (self::parse($notes)['clientNotes'] ?? ''));
        if ($fromMeta !== '') {
            return $fromMeta;
        }

        $text = (string) $notes;
        if (! preg_match('/^Notes:\s*(.*)$/im', $text, $match, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $collected = [];
        $inline = trim((string) ($match[1][0] ?? ''));
        if ($inline !== '') {
            $collected[] = $inline;
        }

        $start = (int) $match[0][1] + strlen($match[0][0]);
        $rest = ltrim(substr($text, $start), "\r\n");
        foreach (preg_split("/\r\n|\n|\r/", $rest) ?: [] as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '') {
                if ($collected !== []) {
                    break;
                }
                continue;
            }
            if (self::isNotesTerminator($trimmed)) {
                break;
            }
            $collected[] = $trimmed;
        }

        return trim(implode("\n", $collected));
    }

    public static function salesNotes(?string $notes): string
    {
        return trim((string) (self::parse($notes)['salesNotes'] ?? ''));
    }

    /**
     * @return array{Included services: string, Notes: string, Sales reply: string}
     */
    public static function inboxFields(?string $notes): array
    {
        return [
            'Included services' => implode(', ', self::additionalServices($notes)),
            'Notes' => self::clientNotes($notes),
            'Sales reply' => self::salesNotes($notes),
        ];
    }

    public static function inboxDetailSuffix(?string $notes): string
    {
        $fields = self::inboxFields($notes);
        $bits = [];
        if ($fields['Included services'] !== '') {
            $bits[] = 'Included services: '.$fields['Included services'];
        }
        if ($fields['Notes'] !== '') {
            $bits[] = 'Notes: '.$fields['Notes'];
        }
        if (($fields['Sales reply'] ?? '') !== '') {
            $bits[] = 'Sales reply: '.$fields['Sales reply'];
        }

        return $bits === [] ? '' : ' '.implode('. ', $bits).'.';
    }

    public static function inboxPreviewSuffix(?string $notes): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', self::clientNotes($notes)));
        if ($text === '') {
            return '';
        }
        if (strlen($text) > 120) {
            $text = rtrim(substr($text, 0, 117)).'...';
        }

        return ' Notes: '.$text;
    }

    /**
     * Package names only — extra website features stay off the Items line.
     *
     * @param  iterable<mixed>|null  $items
     * @return list<string>
     */
    public static function packageItemLabels($items, ?string $notes = null): array
    {
        $extras = [];
        foreach (self::additionalServices($notes) as $feature) {
            $extras[strtolower($feature)] = true;
        }

        $labels = [];
        $seen = [];
        foreach ($items ?? [] as $item) {
            $row = self::normalizeItem($item);
            if (self::isAddonItem($row)) {
                continue;
            }

            $detail = trim((string) ($row['detail'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $candidates = array_values(array_filter(
                $detail !== '' ? [$detail, $name] : [$name],
                fn ($value) => $value !== ''
            ));

            $label = '';
            foreach ($candidates as $candidate) {
                if (isset($extras[strtolower((string) $candidate)])) {
                    continue;
                }
                $label = (string) $candidate;
                break;
            }
            if ($label === '') {
                continue;
            }

            $key = strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $labels[] = $label;
        }

        return $labels;
    }

    /**
     * @param  iterable<mixed>|null  $items
     */
    public static function packageItemLine($items, ?string $notes = null, int $limit = 3): string
    {
        return implode(', ', array_slice(self::packageItemLabels($items, $notes), 0, $limit));
    }

    /**
     * Keep extras under the Custom Web Design package as their own priced rows.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function foldIntoPackage(array $items, ?string $notes): array
    {
        $extras = self::additionalServices($notes);
        $clientNotes = self::clientNotes($notes);
        $salesNotes = self::salesNotes($notes);
        $folded = [];
        $packageIndex = null;
        $addonByName = [];

        foreach ($items as $item) {
            if (self::isAddonItem($item)) {
                $label = trim((string) ($item['detail'] ?? ''));
                if ($label === '' || strcasecmp($label, 'additional service') === 0) {
                    $label = trim((string) ($item['name'] ?? ''));
                }
                if ($label !== '' && strcasecmp($label, 'additional service') !== 0) {
                    $extras[] = $label;
                    $addonByName[strtolower($label)] = $item;
                }
                continue;
            }

            if ($packageIndex === null && self::isWebDesignItem($item)) {
                $packageIndex = count($folded);
            }

            $folded[] = $item;
        }

        $extras = self::uniqueLabels($extras);
        $extraLookup = [];
        foreach ($extras as $label) {
            $key = strtolower($label);
            if ($key !== '') {
                $extraLookup[$key] = $label;
            }
        }

        if ($extraLookup !== []) {
            $kept = [];
            foreach ($folded as $item) {
                if (self::isWebDesignItem($item)) {
                    $kept[] = $item;
                    continue;
                }
                $detailKey = strtolower(trim((string) ($item['detail'] ?? '')));
                $nameKey = strtolower(trim((string) ($item['name'] ?? '')));
                $matched = $extraLookup[$detailKey] ?? $extraLookup[$nameKey] ?? null;
                if ($matched !== null) {
                    $addonByName[strtolower($matched)] ??= $item;
                    continue;
                }
                $kept[] = $item;
            }
            $folded = array_values($kept);
        }

        $packageIndex = null;
        foreach ($folded as $index => $item) {
            if (self::isWebDesignItem($item)) {
                $packageIndex = $index;
                break;
            }
        }
        if ($packageIndex === null && $folded !== []) {
            $packageIndex = 0;
        }

        if ($packageIndex !== null && $clientNotes !== '') {
            $folded[$packageIndex]['clientNotes'] = $clientNotes;
        }
        if ($packageIndex !== null && $salesNotes !== '') {
            $folded[$packageIndex]['salesNotes'] = $salesNotes;
        }

        if ($extras === [] || $packageIndex === null) {
            return $folded;
        }

        $parent = $folded[$packageIndex];
        $children = [];
        foreach ($extras as $index => $label) {
            $existing = $addonByName[strtolower($label)] ?? [];
            $quantity = max(1.0, (float) ($existing['quantity'] ?? 1));
            $amount = (float) ($existing['total'] ?? $existing['price'] ?? 0);
            $unit = (float) ($existing['unitPrice'] ?? 0);
            if ($unit <= 0 && $quantity > 0 && $amount > 0) {
                $unit = round($amount / $quantity, 2);
            }
            if ($amount <= 0 && $unit > 0) {
                $amount = round($unit * $quantity, 2);
            }

            $children[] = [
                'id' => $existing['id'] ?? ('web-addon-'.$index),
                'name' => $label,
                'detail' => 'Included service',
                'itemType' => 'web_design_addon',
                'quantity' => $quantity,
                'unitPrice' => $unit,
                'price' => $amount,
                'total' => $amount,
                'discount' => (float) ($existing['discount'] ?? 0),
                'tax' => (float) ($existing['tax'] ?? 0),
                'included' => true,
                'parentId' => $parent['id'] ?? null,
            ];
        }

        array_splice($folded, $packageIndex + 1, 0, $children);

        return $folded;
    }

    /**
     * @param  iterable<mixed>|null  $items
     * @return list<array{label: string, value: string}>
     */
    public static function amountDetailRows($items, ?string $notes): array
    {
        $raw = [];
        foreach ($items ?? [] as $item) {
            if (is_object($item)) {
                $name = trim((string) ($item->name ?? ''));
                $total = (float) ($item->total_price ?? 0);
                if ($total <= 0) {
                    $total = (float) ($item->price ?? 0) * max(1, (float) ($item->quantity ?? 1));
                }
                $raw[] = [
                    'name' => $name,
                    'detail' => $name,
                    'itemType' => (string) ($item->item_type ?? ''),
                    'quantity' => max(1.0, (float) ($item->quantity ?? 1)),
                    'price' => $total,
                    'total' => $total,
                ];
                continue;
            }
            if (is_array($item)) {
                $raw[] = $item;
            }
        }

        $folded = self::foldIntoPackage($raw, $notes);
        $amounts = DealMeta::amountMap($notes, 'dealAmounts');
        $rows = [];

        foreach ($folded as $item) {
            $name = trim((string) ($item['name'] ?? $item['detail'] ?? ''));
            if ($name === '') {
                continue;
            }
            $included = ! empty($item['included']) || self::isAddonItem($item);
            $amount = (float) ($item['total'] ?? $item['price'] ?? 0);
            if ($amount <= 0) {
                $amount = DealMeta::amountFor($amounts, $name, (string) ($item['detail'] ?? ''));
            }
            $rows[] = [
                'label' => $included ? 'Included service' : 'Items',
                'value' => $name.' — ₱'.number_format($amount, 2),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $labels
     * @return list<string>
     */
    private static function uniqueLabels(array $labels): array
    {
        $seen = [];
        $unique = [];
        foreach ($labels as $feature) {
            $label = trim((string) $feature);
            if ($label === '' || strcasecmp($label, 'none selected') === 0) {
                continue;
            }
            $key = strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $label;
        }

        return $unique;
    }

    private static function isNotesTerminator(string $line): bool
    {
        return (bool) preg_match(
            '/^(\[WEBDESIGN_META\]|Pricing:|Notify:|Service:|Template:|Additional Services:|Items:|Submitted |Payment |Customer checkout|Web design quotation|Sales reply:)/i',
            $line
        );
    }

    /**
     * @param  mixed  $item
     * @return array<string, mixed>
     */
    private static function normalizeItem(mixed $item): array
    {
        if (is_array($item)) {
            return [
                'name' => $item['name'] ?? '',
                'detail' => $item['detail'] ?? '',
                'itemType' => $item['itemType'] ?? $item['item_type'] ?? '',
            ];
        }

        return [
            'name' => $item->name ?? '',
            'detail' => $item->detail ?? '',
            'itemType' => $item->item_type ?? $item->itemType ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function isAddonItem(array $item): bool
    {
        $type = strtolower((string) ($item['itemType'] ?? ''));
        $name = strtolower((string) ($item['name'] ?? ''));

        return str_contains($type, 'web_design_addon')
            || str_contains($type, 'webdesign_addon')
            || $name === 'additional service';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function isWebDesignItem(array $item): bool
    {
        $haystack = strtolower(trim(
            (string) ($item['name'] ?? '').' '.(string) ($item['detail'] ?? '').' '.(string) ($item['itemType'] ?? '')
        ));

        return str_contains($haystack, 'web design')
            || str_contains($haystack, 'web_design')
            || str_contains($haystack, 'starter launch')
            || str_contains($haystack, 'professional corporate')
            || str_contains($haystack, 'e-commerce');
    }
}
