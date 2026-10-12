<?php

namespace App\Support;

use Carbon\Carbon;

class ProvisioningRules
{
    public const STANDARD_HOURS = 48;

    public const DOMAIN_HOURS = 24;

    public const WEBDEV_MIN_DAYS = 30;

    public const WEBDEV_MAX_DAYS = 90;

    public const CHECKPOINT_STANDARD = 12;

    public const CHECKPOINT_WEBDEV = 24;

    public static function isWebDevItem(string $name, ?string $itemType = null): bool
    {
        $type = strtolower(trim((string) $itemType));
        $label = strtolower(trim($name));

        return str_contains($type, 'web_design')
            || str_contains($type, 'webdesign')
            || str_contains($type, 'web_development')
            || str_contains($type, 'webdevelopment')
            || str_contains($label, 'web design')
            || str_contains($label, 'web development')
            || str_contains($label, 'web-dev')
            || str_contains($label, 'starter launch')
            || str_contains($label, 'professional corporate')
            || str_contains($label, 'e-commerce')
            || str_contains($label, 'website template');
    }

    public static function standardHours(string $name, ?string $itemType = null): int
    {
        $haystack = strtolower(trim($name . ' ' . (string) $itemType));

        if (str_contains($haystack, 'domain')) {
            return self::DOMAIN_HOURS;
        }

        return self::STANDARD_HOURS;
    }

    /**
     * @param  iterable<int, object|array<string, mixed>>  $items
     * @return array{timeline: string, durationHours: int|null}
     */
    public static function timelineFor(iterable $items, bool $orderIsWebDesign): array
    {
        if ($orderIsWebDesign) {
            return [
                'timeline' => 'webdev',
                'durationHours' => null,
            ];
        }

        $hours = 0;
        foreach ($items as $item) {
            $name = is_array($item) ? (string) ($item['name'] ?? '') : (string) ($item->name ?? '');
            $type = is_array($item) ? ($item['item_type'] ?? null) : ($item->item_type ?? null);
            $hours = max($hours, self::standardHours($name, is_string($type) ? $type : null));
        }

        return [
            'timeline' => 'standard',
            'durationHours' => $hours > 0 ? $hours : self::STANDARD_HOURS,
        ];
    }

    public static function checkpointFor(string $timeline): int
    {
        return $timeline === 'webdev' ? self::CHECKPOINT_WEBDEV : self::CHECKPOINT_STANDARD;
    }

    /**
     * Latest moment a completed action on this order becomes Active.
     */
    public static function latestCheckpointEnd(iterable $actions): ?Carbon
    {
        $latest = null;
        foreach ($actions as $action) {
            $status = strtolower(trim((string) ($action->status ?? '')));
            if ($status !== 'completed') {
                continue;
            }

            $completedAt = $action->completed_at ?? $action->done_at ?? null;
            if ($completedAt === null) {
                continue;
            }

            $hours = (int) ($action->checkpoint_hours ?? self::CHECKPOINT_STANDARD);
            if (! in_array($hours, [self::CHECKPOINT_STANDARD, self::CHECKPOINT_WEBDEV], true)) {
                $hours = self::CHECKPOINT_STANDARD;
            }

            $end = Carbon::parse($completedAt)->copy()->addHours($hours);
            if ($latest === null || $end->greaterThan($latest)) {
                $latest = $end;
            }
        }

        return $latest;
    }

    public static function isSettledStatus(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['done', 'completed', 'active'], true);
    }

    /**
     * Promote an action without touching the order's provisioning status.
     *
     * Done recorded by support becomes Completed.
     * Active follows either the 12h/24h checkpoint or the finished countdown.
     *
     * @return array{status: string, completedAt: ?Carbon, validatedAt: ?Carbon, events: array<int, string>}
     */
    public static function syncAction(
        string $status,
        ?Carbon $doneAt,
        ?Carbon $completedAt,
        ?Carbon $dueAt,
        int $checkpointHours,
        Carbon $now,
    ): array {
        $events = [];
        $validatedAt = null;
        $status = strtolower(trim($status));

        if ($status === 'active' || $doneAt === null) {
            return [
                'status' => $status === '' ? 'pending' : $status,
                'completedAt' => $completedAt,
                'validatedAt' => null,
                'events' => [],
            ];
        }

        if (in_array($status, ['done', 'pending'], true)) {
            $status = 'completed';
            $completedAt = $completedAt ?? $doneAt->copy();
            $events[] = 'completed';
        }

        $checkpointHours = in_array($checkpointHours, [self::CHECKPOINT_STANDARD, self::CHECKPOINT_WEBDEV], true)
            ? $checkpointHours
            : self::CHECKPOINT_STANDARD;

        $countdownElapsed = $dueAt !== null && $now->greaterThanOrEqualTo($dueAt);
        $checkpointReached = $completedAt !== null
            && $now->greaterThanOrEqualTo($completedAt->copy()->addHours($checkpointHours));

        if ($status === 'completed' && $completedAt !== null && ($countdownElapsed || $checkpointReached)) {
            $status = 'active';
            $validatedAt = $now->copy();
            $events[] = 'active';
        }

        return [
            'status' => $status,
            'completedAt' => $completedAt,
            'validatedAt' => $validatedAt,
            'events' => $events,
        ];
    }
}
