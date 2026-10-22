<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\CustomerService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ServiceExpirationNotifier
{
    /** @var array<int, int> */
    public const MILESTONES = [90, 60, 30];

    public function processAll(): int
    {
        $sent = 0;
        foreach (self::MILESTONES as $days) {
            $sent += $this->processMilestone($days);
        }
        $sent += $this->processExpiredRenewals();

        return $sent;
    }

    public function processForCustomer(int $customerId): int
    {
        $sent = 0;
        $services = CustomerService::query()
            ->where('customer_id', $customerId)
            ->where('status', '!=', 'Expired')
            ->get();

        foreach (self::MILESTONES as $days) {
            foreach ($services as $service) {
                if ($this->daysUntilExpiry($service) === $days) {
                    $sent += $this->notifyService($service, $days) ? 1 : 0;
                }
            }
        }

        $sent += $this->syncExpiredRenewalsForCustomer($customerId);
        $this->purgeStaleRenewalNotices($customerId);

        return $sent;
    }

    public function purgeStaleRenewalNotices(int $customerId): void
    {
        $services = CustomerService::query()
            ->where('customer_id', $customerId)
            ->get()
            ->keyBy('id');

        CustomerNotification::query()
            ->where('customer_id', $customerId)
            ->where('reference_key', 'like', 'service-renewal:%')
            ->get()
            ->each(function (CustomerNotification $row) use ($services) {
                if (! preg_match('/^service-renewal:(\d+)$/', (string) $row->reference_key, $match)) {
                    return;
                }
                $service = $services->get((int) $match[1]);
                if (! $service || ! $this->isExpired($service)) {
                    $row->delete();
                }
            });
    }

    public function processExpiredRenewals(): int
    {
        $sent = 0;
        foreach ($this->expiredServices() as $service) {
            $sent += $this->notifyExpiredRenewal($service) ? 1 : 0;
        }

        return $sent;
    }

    public function syncExpiredRenewalsForCustomer(int $customerId): int
    {
        $sent = 0;
        $services = CustomerService::query()
            ->with(['customer:id,fname,lname,email,owner_id'])
            ->where('customer_id', $customerId)
            ->get()
            ->filter(fn (CustomerService $service) => $this->isExpired($service));

        foreach ($services as $service) {
            $sent += $this->notifyExpiredRenewal($service) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * @return Collection<int, CustomerService>
     */
    public function expiredServices(): Collection
    {
        return CustomerService::query()
            ->with(['customer:id,fname,lname,email,owner_id'])
            ->where('status', '!=', 'Expired')
            ->get()
            ->filter(fn (CustomerService $service) => $this->isExpired($service))
            ->values();
    }

    public function isExpired(CustomerService $service): bool
    {
        $days = $this->daysUntilExpiry($service);

        return $days !== null && $days < 0;
    }

    public function processMilestone(int $days): int
    {
        if (! in_array($days, self::MILESTONES, true)) {
            return 0;
        }

        $sent = 0;
        foreach ($this->servicesExpiringInDays($days) as $service) {
            $sent += $this->notifyService($service, $days) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * @return Collection<int, CustomerService>
     */
    public function servicesExpiringInDays(int $days): Collection
    {
        return CustomerService::query()
            ->with(['customer:id,fname,lname,email,owner_id'])
            ->where('status', '!=', 'Expired')
            ->get()
            ->filter(fn (CustomerService $service) => $this->daysUntilExpiry($service) === $days)
            ->values();
    }

    public function daysUntilExpiry(CustomerService $service): ?int
    {
        $expiry = $this->expiryAt($service);
        if (! $expiry) {
            return null;
        }

        $today = now()->startOfDay();
        $end = $expiry->copy()->startOfDay();

        return (int) $today->diffInDays($end, false);
    }

    public function expiryAt(CustomerService $service): ?Carbon
    {
        $raw = $service->expires_at ?? $service->renew_at;
        if (! $raw) {
            return null;
        }

        return Carbon::parse($raw);
    }

    private function notifyService(CustomerService $service, int $days): bool
    {
        $customerId = (int) $service->customer_id;
        if ($customerId <= 0) {
            return false;
        }

        $expiry = $this->expiryAt($service);
        if (! $expiry) {
            return false;
        }

        $title = $service->title ?: 'Service';
        $expiryLabel = $expiry->format('M j, Y');
        $customerBody = "{$title} will expire on {$expiryLabel} ({$days} days remaining). Renew early to avoid interruption.";
        $clientName = trim(collect([
            $service->customer?->fname,
            $service->customer?->lname,
        ])->filter()->implode(' ')) ?: ($service->customer?->email ?: 'Customer');

        $customerKey = 'service-expiry:' . $service->id . ':' . $days;
        CustomerNotification::query()->updateOrCreate(
            [
                'customer_id' => $customerId,
                'reference_key' => $customerKey,
            ],
            [
                'title' => $days === 30 ? 'Service expiring soon' : "Service renewal reminder ({$days} days)",
                'body' => $customerBody,
                'type' => 'service_expiry',
                'action_url' => '/public/dashboard?tab=orders&renew=1',
                'read_at' => null,
            ]
        );

        $adminKey = 'admin:service-expiry:' . $service->id . ':' . $days;
        app(CommerceStaffNotifier::class)->notifyOwnerAndRoles(
            $customerId,
            ['customer_care', 'sales_admin', 'sales_staff', 'finance_admin'],
            $adminKey,
            "Service expiring in {$days} days",
            "{$clientName}'s {$title} expires on {$expiryLabel} ({$days} days left).",
            'service_expiry',
            '/public/commerce-admin?tab=clients&queue=expiring',
        );

        return true;
    }

    private function notifyExpiredRenewal(CustomerService $service): bool
    {
        $customerId = (int) $service->customer_id;
        if ($customerId <= 0) {
            return false;
        }

        $expiry = $this->expiryAt($service);
        if (! $expiry || ! $this->isExpired($service)) {
            return false;
        }

        $title = $service->title ?: 'Service';
        $expiryLabel = $expiry->format('M j, Y');
        $clientName = trim(collect([
            $service->customer?->fname,
            $service->customer?->lname,
        ])->filter()->implode(' ')) ?: ($service->customer?->email ?: 'Customer');

        $customerKey = 'service-renewal:' . $service->id;
        CustomerNotification::query()->updateOrCreate(
            [
                'customer_id' => $customerId,
                'reference_key' => $customerKey,
            ],
            [
                'title' => 'Renew your expired service',
                'body' => "{$title} expired on {$expiryLabel}. Renew or extend this deal to restore active service.",
                'type' => 'service_renewal',
                'action_url' => '/public/dashboard?tab=orders&renew=1',
                'read_at' => null,
            ]
        );

        $adminKey = 'admin:service-renewal:' . $service->id;
        app(CommerceStaffNotifier::class)->notifyOwnerAndRoles(
            $customerId,
            ['customer_care', 'sales_admin', 'sales_staff', 'finance_admin'],
            $adminKey,
            'Expired service — renewal needed',
            "{$clientName}'s {$title} expired on {$expiryLabel}. Contact them to renew and reactivate the deal.",
            'service_renewal',
            '/public/commerce-admin?tab=deals',
        );

        return true;
    }
}
