<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\CustomerPaymentProof;
use App\Models\CustomerService;
use App\Models\SalesTransaction;
use App\Support\WebDesignQuotation;
use Carbon\Carbon;

class CustomerPortalProvisioner
{
    public const STATUS_ACTIVE = 'Active Live';
    public const STATUS_PROVISIONING = 'Provisioning';
    public const STATUS_AWAITING_APPROVAL = 'Awaiting Approval';
    public const STATUS_PENDING = 'Pending Request';
    public const STATUS_EXPIRED = 'Expired';

    public function provisionFromTransaction(SalesTransaction $transaction): void
    {
        if (!$transaction->customer_id) {
            return;
        }

        $transaction->loadMissing('items');
        $status = self::resolveServiceStatus($transaction);

        foreach ($transaction->items as $item) {
            $category = $this->resolveCategory($item->name, $item->item_type);
            $renewAt = self::isActiveStatus($status)
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
            'body' => "We received your order {$transaction->transaction_no}. " . self::orderReceivedNote($status),
            'type' => in_array($status, [self::STATUS_PENDING, self::STATUS_AWAITING_APPROVAL], true)
                ? 'payment'
                : 'general',
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
        $status = self::resolveServiceStatus($transaction);
        $sync = app(CustomerPortalNotificationSync::class);

        foreach ($transaction->items as $item) {
            $service = CustomerService::query()
                ->where('customer_id', $transaction->customer_id)
                ->where('sales_transaction_id', $transaction->id)
                ->where('title', $item->name)
                ->first();

            $renewAt = self::isActiveStatus($status)
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

                if (!self::isActiveStatus($previousStatus) && self::isActiveStatus($status)) {
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

    public static function resolveServiceStatus(SalesTransaction $transaction): string
    {
        $payment = strtolower((string) $transaction->payment_status);
        $order = strtolower((string) $transaction->order_status);
        $paid = in_array($payment, ['paid', 'completed', 'success'], true);
        $live = in_array($order, ['completed', 'active', 'delivered', 'live'], true);

        if (in_array($order, ['cancelled', 'canceled', 'expired', 'failed'], true)) {
            return self::STATUS_EXPIRED;
        }

        if ($paid && $live) {
            return self::STATUS_ACTIVE;
        }

        if ($paid) {
            return self::STATUS_PROVISIONING;
        }

        if (self::isPaymentSubmitted($transaction)) {
            return self::STATUS_AWAITING_APPROVAL;
        }

        return self::STATUS_PENDING;
    }

    public static function resolveStatusForService(CustomerService $service): string
    {
        $transaction = $service->salesTransaction;
        if ($transaction) {
            return self::resolveServiceStatus($transaction);
        }

        $stored = trim((string) $service->status);
        if ($stored === 'Active') {
            return self::STATUS_ACTIVE;
        }

        return $stored !== '' ? $stored : self::STATUS_PENDING;
    }

    public static function isActiveStatus(?string $status): bool
    {
        return in_array($status, ['Active', self::STATUS_ACTIVE], true);
    }

    public static function isPaymentSubmitted(SalesTransaction $transaction): bool
    {
        $notes = trim((string) $transaction->notes);
        if ($notes !== '' && (
            str_starts_with($notes, 'Invoice payment')
            || str_starts_with($notes, 'Account credit top-up')
        )) {
            return true;
        }

        return CustomerPaymentProof::query()
            ->where(function ($query) use ($transaction) {
                $query->where('sales_transaction_id', $transaction->id)
                    ->orWhere('invoice_id', 'INV-' . $transaction->transaction_no);
            })
            ->whereIn('status', ['Pending Review', 'Verified & Credited'])
            ->exists();
    }

    public static function isUnpaid(SalesTransaction $transaction): bool
    {
        return !in_array(strtolower((string) $transaction->payment_status), ['paid', 'completed', 'success'], true)
            && !WebDesignQuotation::isPendingQuotation($transaction);
    }

    private static function orderReceivedNote(string $status): string
    {
        return match ($status) {
            self::STATUS_AWAITING_APPROVAL => 'Payment proof is pending admin approval. Provisioning begins after payment is complete.',
            self::STATUS_PROVISIONING => 'Payment is confirmed and your services are being provisioned.',
            self::STATUS_ACTIVE => 'Your services are now active.',
            default => 'Provisioning begins after payment is confirmed.',
        };
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
