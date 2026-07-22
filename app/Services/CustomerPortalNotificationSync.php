<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\CustomerService;
use App\Models\SalesTransaction;
use Illuminate\Support\Collection;

class CustomerPortalNotificationSync
{
    public function syncForCustomer(int $customerId): void
    {
        $activeKeys = [];

        $services = CustomerService::query()
            ->where('customer_id', $customerId)
            ->get();

        foreach ($services->where('status', 'Provisioning') as $service) {
            $key = 'provisioning:service:' . $service->id;
            $activeKeys[] = $key;
            $this->upsert($customerId, $key, [
                'title' => 'Provisioning Alert: ' . $service->title,
                'body' => $service->title . ' is currently provisioning. We\'ll notify you when it\'s active.',
                'type' => 'provisioning',
                'action_url' => '/public/dashboard?tab=overview',
            ]);
        }

        $transactions = SalesTransaction::query()
            ->where('customer_id', $customerId)
            ->with('items')
            ->latest('transacted_at')
            ->get();

        foreach ($this->unpaidTransactions($transactions) as $transaction) {
            $key = 'payment:transaction:' . $transaction->id;
            $activeKeys[] = $key;
            $itemNames = $transaction->items->pluck('name')->filter()->take(3)->implode(', ');

            $this->upsert($customerId, $key, [
                'title' => 'Payment pending admin approval',
                'body' => 'We received your order for ' . ($itemNames ?: $transaction->transaction_no)
                    . '. Provisioning begins only after payment is complete.',
                'type' => 'payment',
                'action_url' => '/public/dashboard?tab=orders',
            ]);
        }

        CustomerNotification::query()
            ->where('customer_id', $customerId)
            ->whereNotNull('reference_key')
            ->whereNotIn('reference_key', $activeKeys)
            ->delete();
    }

    public function buildOverviewAlerts(Collection $services, Collection $transactions): array
    {
        $alerts = [];

        foreach ($services->where('status', 'Provisioning') as $service) {
            $alerts[] = [
                'id' => 'alert-provisioning-' . $service->id,
                'tone' => 'provisioning',
                'title' => 'Provisioning Alert: ' . $service->title,
                'message' => $service->title . ' is currently provisioning. We\'ll notify you when it\'s active.',
                'actionLabel' => 'View Alerts',
                'actionHref' => '/public/dashboard?tab=notification',
                'icon' => 'bell',
            ];
        }

        foreach ($this->unpaidTransactions($transactions) as $transaction) {
            $itemNames = $transaction->items->pluck('name')->filter()->take(3)->implode(', ');
            $alerts[] = [
                'id' => 'alert-payment-' . $transaction->id,
                'tone' => 'payment',
                'title' => 'Payment pending admin approval',
                'message' => 'We received your order for ' . ($itemNames ?: $transaction->transaction_no)
                    . '. Provisioning begins only after payment is complete.',
                'actionLabel' => 'View Orders',
                'actionHref' => '/public/dashboard?tab=orders',
                'icon' => 'card',
            ];
        }

        return $alerts;
    }

    public function notifyServiceActivated(CustomerService $service): void
    {
        CustomerNotification::query()
            ->where('customer_id', $service->customer_id)
            ->where('reference_key', 'provisioning:service:' . $service->id)
            ->delete();

        $this->upsert($service->customer_id, 'activated:service:' . $service->id, [
            'title' => 'Service Now Active: ' . $service->title,
            'body' => $service->title . ' is now live on your account.',
            'type' => 'general',
            'action_url' => '/public/dashboard?tab=overview',
        ]);
    }

    private function unpaidTransactions(Collection $transactions): Collection
    {
        return $transactions->filter(
            fn ($row) => !in_array(strtolower((string) $row->payment_status), ['paid', 'completed', 'success'], true)
        );
    }

    private function upsert(int $customerId, string $referenceKey, array $payload): void
    {
        CustomerNotification::updateOrCreate(
            [
                'customer_id' => $customerId,
                'reference_key' => $referenceKey,
            ],
            [
                'title' => $payload['title'],
                'body' => $payload['body'],
                'type' => $payload['type'],
                'action_url' => $payload['action_url'],
            ]
        );
    }
}
