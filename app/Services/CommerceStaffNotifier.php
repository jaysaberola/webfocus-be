<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\User;
use Illuminate\Support\Collection;

class CommerceStaffNotifier
{
    /**
     * Notify the client's assigned owner (if any) plus staff with the given roles.
     *
     * @param  array<int, string>  $roles
     */
    public function notifyOwnerAndRoles(
        ?int $customerId,
        array $roles,
        string $referenceKey,
        string $title,
        string $body,
        string $type,
        string $actionUrl,
        bool $includeOwner = true,
    ): void {
        $recipientIds = $this->resolveRecipientIds($customerId, $roles, $includeOwner);
        if ($recipientIds->isEmpty()) {
            return;
        }

        foreach ($recipientIds as $staffId) {
            CustomerNotification::query()->updateOrCreate(
                [
                    'customer_id' => $staffId,
                    'reference_key' => $referenceKey,
                ],
                [
                    'title' => $title,
                    'body' => $body,
                    'type' => $type,
                    'action_url' => $actionUrl,
                    'read_at' => null,
                ]
            );
        }
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function resolveRecipientIds(?int $customerId, array $roles, bool $includeOwner = true): Collection
    {
        $ids = collect();

        if ($includeOwner && $customerId) {
            $ownerId = User::query()->whereKey($customerId)->value('owner_id');
            if ($ownerId) {
                $ids->push((int) $ownerId);
            }
        }

        if ($roles !== []) {
            $roleIds = User::role($roles)
                ->where('is_active', true)
                ->pluck('id');
            $ids = $ids->merge($roleIds);
        }

        return $ids
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();
    }
}
