<?php

namespace App\Services;

use App\Models\SalesTransaction;
use App\Models\User;
use App\Support\WebDesignQuotation;
use Illuminate\Support\Collection;

class ClientOwnerRotator
{
    /**
     * Assign a client owner to a new order.
     * Pass $explicitOwnerId when staff picked someone in the form.
     *
     * Web design / web development orders rotate Client Owner between
     * Myrna Glorioso and Michelle Durian and do not change the customer's
     * account owner. Other orders use the same pair per client and update
     * users.owner_id.
     */
    public function assign(SalesTransaction $transaction, ?int $explicitOwnerId = null): ?User
    {
        $transaction->loadMissing('items');

        if (WebDesignQuotation::isWebDesign($transaction)) {
            return $this->assignSalesStaff($transaction, $explicitOwnerId);
        }

        return $this->assignRotatingOwner($transaction, $explicitOwnerId);
    }

    public function nextOwner(int $customerId): ?User
    {
        $pair = $this->rotatingOwners();
        if ($pair->isEmpty()) {
            return null;
        }

        if ($pair->count() === 1) {
            return $pair->first();
        }

        $lastOwnerId = $this->lastAssignedOwnerId($customerId);
        if ($lastOwnerId && (int) $pair->get(0)?->id === (int) $lastOwnerId) {
            return $pair->get(1, $pair->first());
        }

        return $pair->first();
    }

    public function nextOwnerPayload(int $customerId): ?array
    {
        return $this->userPayload($this->nextOwner($customerId));
    }

    public function nextSalesStaff(?int $exceptTransactionId = null): ?User
    {
        $staff = $this->rotatingSalesStaff();
        if ($staff->isEmpty()) {
            return null;
        }

        if ($staff->count() === 1) {
            return $staff->first();
        }

        $lastOwnerId = $this->lastWebDesignSalesOwnerId($exceptTransactionId);
        $index = $lastOwnerId
            ? $staff->search(fn (User $user) => (int) $user->id === (int) $lastOwnerId)
            : false;
        $nextIndex = $index === false ? 0 : (($index + 1) % $staff->count());

        return $staff->get($nextIndex) ?? $staff->first();
    }

    public function nextSalesStaffPayload(?int $exceptTransactionId = null): ?array
    {
        return $this->userPayload($this->nextSalesStaff($exceptTransactionId));
    }

    /**
     * @return Collection<int, User>
     */
    public function rotatingOwners(): Collection
    {
        return $this->usersByConfiguredEmails(config('commerce.rotating_client_owners', []));
    }

    /**
     * The two rotating sales owners (Myrna / Michelle). Falls back to
     * rotating_client_owners when rotating_sales_staff is empty.
     *
     * @return Collection<int, User>
     */
    public function rotatingSalesStaff(): Collection
    {
        $emails = config('commerce.rotating_sales_staff', []);
        if (! is_array($emails) || $emails === []) {
            $emails = config('commerce.rotating_client_owners', []);
        }

        return $this->usersByConfiguredEmails($emails);
    }

    public function isAllowedSalesAssignee(?User $user): bool
    {
        if (! $user || ! $user->is_active) {
            return false;
        }

        if ($user->hasAnyRole(['sales_staff', 'sales_admin', 'admin'])) {
            return true;
        }

        $ownerEmails = collect(config('commerce.client_owners', []))
            ->pluck('email')
            ->filter()
            ->map(fn ($email) => strtolower(trim((string) $email)));

        return $ownerEmails->contains(strtolower((string) $user->email));
    }

    private function assignSalesStaff(SalesTransaction $transaction, ?int $explicitOwnerId): ?User
    {
        $owner = $this->salesAssigneeFromExplicit($explicitOwnerId)
            ?: $this->nextSalesStaff($transaction->id);

        if (! $owner) {
            return null;
        }

        $transaction->update([
            'client_owner_id' => $owner->id,
            'user_id' => $owner->id,
        ]);

        return $owner;
    }

    private function assignRotatingOwner(SalesTransaction $transaction, ?int $explicitOwnerId): ?User
    {
        $customerId = (int) ($transaction->customer_id ?? 0);
        if ($customerId <= 0) {
            return null;
        }

        $owner = $explicitOwnerId
            ? User::query()->find($explicitOwnerId)
            : $this->nextOwner($customerId);

        if (! $owner) {
            return null;
        }

        $transaction->update(['client_owner_id' => $owner->id]);
        User::query()->whereKey($customerId)->update(['owner_id' => $owner->id]);

        return $owner;
    }

    private function salesAssigneeFromExplicit(?int $explicitOwnerId): ?User
    {
        if (! $explicitOwnerId) {
            return null;
        }

        $explicit = User::query()->with('roles')->find($explicitOwnerId);
        if (! $this->isAllowedSalesAssignee($explicit)) {
            return null;
        }

        return $explicit;
    }

    /**
     * @param  array<int, mixed>  $emails
     * @return Collection<int, User>
     */
    private function usersByConfiguredEmails(array $emails): Collection
    {
        $normalized = collect($emails)
            ->filter()
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->unique()
            ->values();

        if ($normalized->isEmpty()) {
            return collect();
        }

        $users = User::query()
            ->with('roles')
            ->whereIn('email', $normalized)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (User $user) => strtolower((string) $user->email));

        return $normalized
            ->map(fn (string $email) => $users->get($email))
            ->filter()
            ->values();
    }

    private function lastAssignedOwnerId(int $customerId): ?int
    {
        $fromOrder = SalesTransaction::query()
            ->where('customer_id', $customerId)
            ->whereNotNull('client_owner_id')
            ->latest('id')
            ->value('client_owner_id');

        if ($fromOrder) {
            return (int) $fromOrder;
        }

        $fromClient = User::query()->whereKey($customerId)->value('owner_id');

        return $fromClient ? (int) $fromClient : null;
    }

    private function lastWebDesignSalesOwnerId(?int $exceptTransactionId = null): ?int
    {
        $staffIds = $this->rotatingSalesStaff()->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($staffIds === []) {
            return null;
        }

        $recent = SalesTransaction::query()
            ->with('items')
            ->when($exceptTransactionId, fn ($query) => $query->where('id', '!=', $exceptTransactionId))
            ->latest('id')
            ->limit(80)
            ->get();

        foreach ($recent as $row) {
            if (! WebDesignQuotation::isWebDesign($row)) {
                continue;
            }

            $ownerId = (int) ($row->client_owner_id ?: $row->user_id);
            if ($ownerId && in_array($ownerId, $staffIds, true)) {
                return $ownerId;
            }

            return null;
        }

        return null;
    }

    private function userPayload(?User $owner): ?array
    {
        if (! $owner) {
            return null;
        }

        return [
            'id' => $owner->id,
            'name' => trim(($owner->fname ?? '') . ' ' . ($owner->lname ?? '')) ?: ($owner->email ?? 'User'),
            'email' => $owner->email,
        ];
    }
}
