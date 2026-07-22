<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\CustomerService;
use App\Models\SalesTransaction;
use Carbon\Carbon;

class CustomerPortalProvisioner
{
    public function provisionFromTransaction(SalesTransaction $transaction): void
    {
        if (!$transaction->customer_id) {
            return;
        }

        $transaction->loadMissing('items');
        $status = $this->resolveServiceStatus($transaction);

        foreach ($transaction->items as $item) {
            $category = $this->resolveCategory($item->name, $item->item_type);
            $renewAt = $status === 'Active'
                ? ($transaction->transacted_at?->copy()->addYear() ?? now()->addYear())
                : null;

            CustomerService::create([
                'customer_id' => $transaction->customer_id,
                'sales_transaction_id' => $transaction->id,
                'title' => $item->name,
                'category' => $category,
                'plan' => $item->name,
                'status' => $status,
                'renew_label' => $renewAt ? 'Renews' : 'Renewal Schedule',
                'renew_at' => $renewAt,
                'renew_note' => CustomerPortalProvisioner::formatRenewNote($renewAt),
            ]);
        }

        CustomerNotification::create([
            'customer_id' => $transaction->customer_id,
            'title' => 'Order Received',
            'body' => "We received your order {$transaction->transaction_no}. "
                . ($status === 'Provisioning'
                    ? 'Provisioning begins after payment is confirmed.'
                    : 'Your services are being activated.'),
            'type' => $status === 'Provisioning' ? 'payment' : 'general',
            'action_url' => '/public/dashboard?tab=orders',
        ]);

        app(CustomerPortalNotificationSync::class)->syncForCustomer($transaction->customer_id);
    }

    public function refreshServicesFromTransaction(SalesTransaction $transaction): void
    {
        if (!$transaction->customer_id) {
            return;
        }

        $transaction->loadMissing('items');
        $status = $this->resolveServiceStatus($transaction);
        $sync = app(CustomerPortalNotificationSync::class);

        foreach ($transaction->items as $item) {
            $service = CustomerService::query()
                ->where('customer_id', $transaction->customer_id)
                ->where('sales_transaction_id', $transaction->id)
                ->where('title', $item->name)
                ->first();

            $renewAt = $status === 'Active'
                ? ($transaction->transacted_at?->copy()->addYear() ?? now()->addYear())
                : null;

            $payload = [
                'category' => $this->resolveCategory($item->name, $item->item_type),
                'plan' => $item->name,
                'status' => $status,
                'renew_label' => $renewAt ? 'Renews' : 'Renewal Schedule',
                'renew_at' => $renewAt,
                'renew_note' => self::formatRenewNote($renewAt),
            ];

            if ($service) {
                $previousStatus = $service->status;
                $service->update($payload);

                if ($previousStatus !== 'Active' && $status === 'Active') {
                    $sync->notifyServiceActivated($service->fresh());
                }
            } else {
                CustomerService::create(array_merge($payload, [
                    'customer_id' => $transaction->customer_id,
                    'sales_transaction_id' => $transaction->id,
                    'title' => $item->name,
                ]));
            }
        }

        $sync->syncForCustomer($transaction->customer_id);
    }

    private function resolveCategory(string $name, ?string $itemType): string
    {
        $haystack = strtolower(trim($name . ' ' . ($itemType ?? '')));

        if (str_contains($haystack, 'domain')) {
            return 'Domains';
        }
        if (str_contains($haystack, 'dedicated') || str_contains($haystack, 'baremetal')) {
            return 'Dedicated Server';
        }
        if (str_contains($haystack, 'hosting') || str_contains($haystack, 'cloud') || str_contains($haystack, 'server')) {
            return 'Shared Hosting';
        }
        if (str_contains($haystack, 'dms') || str_contains($haystack, 'document')) {
            return 'Hosting';
        }
        if (str_contains($haystack, 'design') || str_contains($haystack, 'web')) {
            return 'Shared Hosting';
        }

        return 'Hosting';
    }

    private function resolveServiceStatus(SalesTransaction $transaction): string
    {
        $payment = strtolower((string) $transaction->payment_status);
        $order = strtolower((string) $transaction->order_status);

        if (in_array($payment, ['paid', 'completed', 'success'], true)
            && in_array($order, ['completed', 'active', 'delivered', 'live'], true)) {
            return 'Active';
        }

        if (in_array($order, ['cancelled', 'expired', 'failed'], true)) {
            return 'Expired';
        }

        return 'Provisioning';
    }

    public static function formatRenewDate(?Carbon $renewAt): ?string
    {
        return $renewAt?->format('M j, Y, g:i A');
    }

    public static function formatRenewNote(?Carbon $renewAt): string
    {
        if (!$renewAt) {
            return 'Your renewal date will appear once this service is live.';
        }

        $days = now()->startOfDay()->diffInDays($renewAt->copy()->startOfDay(), false);

        if ($days < 0) {
            return 'Expired ' . abs($days) . ' days ago';
        }

        return $days . ' days left';
    }
}
