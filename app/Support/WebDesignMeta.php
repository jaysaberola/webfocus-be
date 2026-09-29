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

    /**
     * @return array{Included services: string, Notes: string}
     */
    public static function inboxFields(?string $notes): array
    {
        return [
            'Included services' => implode(', ', self::additionalServices($notes)),
            'Notes' => self::clientNotes($notes),
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
     * Keep extras under the Custom Web Design package so they share one price.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function foldIntoPackage(array $items, ?string $notes): array
    {
        $extras = self::additionalServices($notes);
        $clientNotes = self::clientNotes($notes);
        $folded = [];
        $packageIndex = null;

        foreach ($items as $item) {
            if (self::isAddonItem($item)) {
                $label = trim((string) ($item['detail'] ?? ''));
                if ($label !== '' && strcasecmp($label, 'additional service') !== 0) {
                    $extras[] = $label;
                }
                continue;
            }

            if ($packageIndex === null && self::isWebDesignItem($item)) {
                $packageIndex = count($folded);
            }

            $folded[] = $item;
        }

        $extras = self::uniqueLabels($extras);
        if ($extras === [] && $clientNotes === '') {
            return $folded;
        }

        if ($packageIndex === null && $folded !== []) {
            $packageIndex = 0;
        }

        if ($packageIndex !== null) {
            if ($extras !== []) {
                $folded[$packageIndex]['additionalServices'] = $extras;
            }
            if ($clientNotes !== '') {
                $folded[$packageIndex]['clientNotes'] = $clientNotes;
            }
        }

        return $folded;
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
            '/^(\[WEBDESIGN_META\]|Pricing:|Notify:|Service:|Template:|Additional Services:|Items:|Submitted |Payment |Customer checkout|Web design quotation)/i',
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
