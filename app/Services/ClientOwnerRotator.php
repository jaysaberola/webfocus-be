<?php

namespace App\Services;

use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Support\Collection;

class ClientOwnerRotator
{
    /**
     * Attach a Client Owner / Sales Staff to a new order.
     *
     * Default owner assignment happens only on new customer registration
     * (currently Sales Admin Glenn Narrido via config).
     * Later orders reuse that customer's existing owner — they do not reassign.
     * Pass $explicitOwnerId when staff picked someone in the form.
     */
    public function assign(SalesTransaction $transaction, ?int $explicitOwnerId = null): ?User
    {
        $transaction->loadMissing('items');

        $owner = $this->salesAssigneeFromExplicit($explicitOwnerId)
            ?: $this->existingCustomerOwner((int) ($transaction->customer_id ?? 0))
            ?: $this->assignOwnerToCustomerIfMissing((int) ($transaction->customer_id ?? 0));

        if (! $owner) {
            return null;
        }

        $transaction->update([
            'client_owner_id' => $owner->id,
            'user_id' => $owner->id,
        ]);

        return $owner;
    }

    /**
     * When CRM updates a customer's owner, align all of their deals/invoices.
     */
    public function syncDealsForCustomer(User $customer): void
    {
        $customerId = (int) $customer->id;
        if ($customerId <= 0) {
            return;
        }

        $ownerId = $customer->owner_id ? (int) $customer->owner_id : null;

        SalesTransaction::query()
            ->where('customer_id', $customerId)
            ->update(['client_owner_id' => $ownerId]);
    }

    public function nextOwner(int $customerId): ?User
    {
        return $this->existingCustomerOwner($customerId) ?: $this->nextSalesStaff();
    }

    public function nextOwnerPayload(int $customerId): ?array
    {
        return $this->userPayload($this->nextOwner($customerId));
    }

    /**
     * Next default sales assignee for a brand-new registration only.
     * With one configured email this always returns that Sales Admin.
     * With multiple emails it still alternates across recent registrations.
     */
    public function nextSalesStaff(?int $exceptTransactionId = null): ?User
    {
        $staff = $this->rotatingSalesStaff();
        if ($staff->isEmpty()) {
            return null;
        }

        if ($staff->count() === 1) {
            return $staff->first();
        }

        $lastOwnerId = $this->lastRegisteredCustomerOwnerId();
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
        return $this->rotatingSalesStaff();
    }

    /**
     * Configured default sales owners for new portal registrations.
     * Falls back to rotating_client_owners when rotating_sales_staff is empty.
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

        $staffEmails = $this->rotatingSalesStaff()
            ->map(fn (User $staff) => strtolower((string) $staff->email))
            ->filter()
            ->values();

        return $staffEmails->contains(strtolower((string) $user->email));
    }

    /**
     * @return Collection<int, string>
     */
    public function salesAssigneeEmails(): Collection
    {
        return collect(config('commerce.sales_assignees', []))
            ->map(function ($row) {
                if (is_string($row)) {
                    return strtolower(trim($row));
                }

                return strtolower(trim((string) ($row['email'] ?? '')));
            })
            ->merge(collect(config('commerce.rotating_sales_staff', []))->map(
                fn ($email) => strtolower(trim((string) $email))
            ))
            ->filter()
            ->unique()
            ->values();
    }

    public function isLeadSalesAssignee(?User $user): bool
    {
        if (! $user || ! $user->is_active || $user->hasRole('customer')) {
            return false;
        }

        if ($user->hasAnyRole(['sales_staff', 'sales_admin'])) {
            return true;
        }

        $email = strtolower(trim((string) $user->email));

        return $email !== '' && $this->salesAssigneeEmails()->contains($email);
    }

    /**
     * Every active Sales Staff / Sales Admin (and configured sales team) for lead assignment.
     *
     * @return Collection<int, User>
     */
    public function assignableSalesUsers(): Collection
    {
        $emails = $this->salesAssigneeEmails();

        $users = User::query()
            ->with('roles')
            ->where('is_active', true)
            ->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'customer');
            })
            ->where(function ($query) use ($emails) {
                $query->whereHas('roles', function ($roleQuery) {
                    $roleQuery->whereIn('name', ['sales_staff', 'sales_admin']);
                });

                if ($emails->isNotEmpty()) {
                    $query->orWhere(function ($emailQuery) use ($emails) {
                        foreach ($emails as $email) {
                            $emailQuery->orWhereRaw('LOWER(email) = ?', [$email]);
                        }
                    });
                }
            })
            ->get();

        return $users
            ->unique('id')
            ->sortBy(function (User $user) {
                $label = strtolower(trim($user->full_name ?: (string) $user->email));
                if ($user->hasRole('sales_admin')) {
                    return '0-' . $label;
                }
                if ($user->hasRole('sales_staff')) {
                    return '1-' . $label;
                }

                return '2-' . $label;
            })
            ->values();
    }

    /**
     * Assign the default Sales Admin / Client Owner when a customer first registers.
     * Skips customers that already have an owner (existing assignments stay unchanged).
     */
    public function assignOwnerToNewCustomer(User $customer): ?User
    {
        if (! $customer->hasRole('customer')) {
            return null;
        }

        if ((int) ($customer->owner_id ?? 0) > 0) {
            return User::query()->find($customer->owner_id);
        }

        $owner = $this->nextSalesStaff();
        if (! $owner) {
            return null;
        }

        $customer->update(['owner_id' => $owner->id]);

        return $owner;
    }

    private function existingCustomerOwner(int $customerId): ?User
    {
        if ($customerId <= 0) {
            return null;
        }

        $ownerId = (int) (User::query()->whereKey($customerId)->value('owner_id') ?? 0);
        if ($ownerId <= 0) {
            return null;
        }

        $owner = User::query()->with('roles')->find($ownerId);
        if (! $owner || ! $owner->is_active) {
            return null;
        }

        return $owner;
    }

    /**
     * Fallback for older customers with no owner yet: assign once via the
     * default Sales Admin, then keep that owner for future orders.
     */
    private function assignOwnerToCustomerIfMissing(int $customerId): ?User
    {
        if ($customerId <= 0) {
            return null;
        }

        $customer = User::query()->with('roles')->find($customerId);
        if (! $customer || ! $customer->hasRole('customer')) {
            return null;
        }

        return $this->assignOwnerToNewCustomer($customer);
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

    /**
     * Last default sales assignee used on customer registration (for multi-email rotation).
     */
    private function lastRegisteredCustomerOwnerId(): ?int
    {
        $staffIds = $this->rotatingSalesStaff()->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($staffIds === []) {
            return null;
        }

        $fromRegistration = User::query()
            ->role('customer')
            ->whereIn('owner_id', $staffIds)
            ->latest('id')
            ->value('owner_id');

        return $fromRegistration ? (int) $fromRegistration : null;
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
