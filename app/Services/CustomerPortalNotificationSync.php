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
            ->with('salesTransaction')
            ->get();

        foreach ($services as $service) {
            $status = CustomerPortalProvisioner::resolveStatusForService($service);
            if ($status !== $service->status) {
                $service->status = $status;
                $service->save();
            }

            if ($status !== CustomerPortalProvisioner::STATUS_PROVISIONING) {
                continue;
            }

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
            $status = CustomerPortalProvisioner::resolveServiceStatus($transaction);
            $key = 'payment:transaction:' . $transaction->id;
            $activeKeys[] = $key;
            $itemNames = $transaction->items->pluck('name')->filter()->take(3)->implode(', ');
            $submitted = $status === CustomerPortalProvisioner::STATUS_AWAITING_APPROVAL;

            $this->upsert($customerId, $key, [
                'title' => $submitted ? 'Payment pending admin approval' : 'Pending Payment',
                'body' => $this->paymentAlertMessage($transaction, $submitted),
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
        $provisioning = $services->filter(
            fn (CustomerService $service) => CustomerPortalProvisioner::resolveStatusForService($service)
                === CustomerPortalProvisioner::STATUS_PROVISIONING
        );
        $unpaid = $this->unpaidTransactions($transactions);

        if ($provisioning->isNotEmpty()) {
            $count = $provisioning->count();
            $alerts[] = [
                'id' => 'alert-provisioning-summary',
                'tone' => 'provisioning',
                'title' => $count === 1
                    ? 'Provisioning Alert: ' . $provisioning->first()->title
                    : 'Provisioning Alerts (' . $count . ')',
                'message' => $count === 1
                    ? $provisioning->first()->title . ' is currently provisioning. We\'ll notify you when it\'s active.'
                    : 'You have ' . $count . ' services currently provisioning. We\'ll notify you when they\'re active.',
                'actionLabel' => 'View Alerts',
                'actionHref' => '/public/dashboard?tab=notification',
                'icon' => 'bell',
            ];
        }

        if ($unpaid->isNotEmpty()) {
            $count = $unpaid->count();
            $first = $unpaid->first();
            $submitted = CustomerPortalProvisioner::resolveServiceStatus($first)
                === CustomerPortalProvisioner::STATUS_AWAITING_APPROVAL;
            $alerts[] = [
                'id' => 'alert-payment-summary',
                'tone' => 'payment',
                'title' => $submitted || $count > 1
                    ? 'Payment pending admin approval'
                    : 'Pending Payment',
                'message' => $count === 1
                    ? $this->paymentAlertMessage($first, $submitted)
                    : 'You have ' . $count . ' orders pending payment. Provisioning begins only after payment is complete.',
                'actionLabel' => 'View Orders',
                'actionHref' => '/public/dashboard?tab=orders',
                'icon' => 'card',
            ];
        }

        return $alerts;
    }

    private function paymentAlertMessage(SalesTransaction $transaction, bool $submitted = false): string
    {
        $itemNames = $transaction->items->pluck('name')->filter()->take(3)->implode(', ');
        $label = $itemNames ?: $transaction->transaction_no;

        if ($submitted) {
            return 'We received your order for ' . $label
                . '. Payment is pending admin approval. Provisioning begins only after payment is complete.';
        }

        return 'We received your order for ' . $label
            . '. Complete payment to start provisioning.';
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
            fn (SalesTransaction $row) => CustomerPortalProvisioner::isUnpaid($row)
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
