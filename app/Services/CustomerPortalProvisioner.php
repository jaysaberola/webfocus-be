<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\CustomerPaymentProof;
use App\Models\CustomerService;
use App\Models\ProvisioningRun;
use App\Models\SalesTransaction;
use App\Support\DealMeta;
use App\Support\ProvisioningRules;
use App\Support\WebDesignQuotation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class CustomerPortalProvisioner
{
    public const STATUS_ACTIVE = 'Active Live';
    public const STATUS_PROVISIONING = 'Provisioning';
    public const STATUS_AWAITING_APPROVAL = 'Awaiting Approval';
    public const STATUS_PENDING = 'Pending Request';
    public const STATUS_PENDING_PAYMENT = 'Pending Payment';
    public const STATUS_EXPIRED = 'Expired';
    public const STATUS_CANCELLED = 'Cancelled';

    public function provisionFromTransaction(SalesTransaction $transaction): void
    {
        if (!$transaction->customer_id) {
            return;
        }

        $transaction->loadMissing('items');
        $status = self::resolveServiceStatus($transaction);

        foreach ($transaction->items as $item) {
            $category = $this->resolveCategory($item->name, $item->item_type);
            $renewAt = self::clientRenewAt($transaction, $status);
            $expiresAt = DealMeta::financeExpirationDate($transaction->notes);

            CustomerService::create([
                'customer_id' => $transaction->customer_id,
                'sales_transaction_id' => $transaction->id,
                'title' => $item->name,
                'category' => $category,
                'plan' => $item->name,
                'status' => $status,
                'renew_label' => $renewAt ? 'Renews' : 'Renewal Schedule',
                'renew_at' => $renewAt,
                'expires_at' => $expiresAt,
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

            $renewAt = self::clientRenewAt($transaction, $status);
            $expiresAt = DealMeta::financeExpirationDate($transaction->notes);

            $payload = [
                'category' => $this->resolveCategory($item->name, $item->item_type),
                'plan' => $item->name,
                'status' => $status,
                'renew_label' => $renewAt ? 'Renews' : 'Renewal Schedule',
                'renew_at' => $renewAt,
                'expires_at' => $expiresAt,
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

        if (in_array($order, ['cancelled', 'canceled'], true) || in_array($payment, ['cancelled', 'canceled'], true)) {
            return self::STATUS_CANCELLED;
        }

        if (in_array($order, ['expired', 'failed'], true)) {
            return self::STATUS_EXPIRED;
        }

        if ($paid && ($live || self::isProvisioningFinished($transaction))) {
            return self::STATUS_ACTIVE;
        }

        if (self::hasUnverifiedPaymentProof($transaction)) {
            return self::STATUS_AWAITING_APPROVAL;
        }

        if ($paid && self::hasVerifiedPaymentProof($transaction)) {
            return self::STATUS_PROVISIONING;
        }

        if ($paid) {
            return self::STATUS_PENDING_PAYMENT;
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

    public function activateReadyForCustomer(int $customerId): void
    {
        $transactions = SalesTransaction::query()
            ->where('customer_id', $customerId)
            ->with(['items', 'provisioningRun', 'paymentProofs'])
            ->get();

        foreach ($transactions as $transaction) {
            $this->activateIfReady($transaction);
        }
    }

    public function activateIfReady(SalesTransaction $transaction): void
    {
        if (self::resolveServiceStatus($transaction) !== self::STATUS_ACTIVE) {
            return;
        }

        $current = strtolower((string) $transaction->order_status);
        if (in_array($current, ['active', 'completed', 'delivered', 'live', 'cancelled', 'canceled'], true)) {
            return;
        }

        $transaction->order_status = 'active';
        $transaction->save();
        $this->refreshServicesFromTransaction($transaction->fresh('items'));
    }

    public static function isProvisioningFinished(SalesTransaction $transaction): bool
    {
        $run = self::provisioningRunFor($transaction);
        if ($run) {
            if ($run->status === 'completed') {
                return true;
            }
            if ($run->status === 'cancelled') {
                return false;
            }

            $run->loadMissing('actions');
            if ($run->actions->isNotEmpty()
                && $run->actions->contains(fn ($action) => ! ProvisioningRules::isSettledStatus($action->status))) {
                return false;
            }

            return self::runWindowElapsed($run);
        }

        $approvedAt = self::approvedAt($transaction);
        if ($approvedAt === null) {
            return false;
        }

        $transaction->loadMissing('items');
        if (WebDesignQuotation::isWebDesign($transaction)) {
            return false;
        }

        $plan = ProvisioningRules::timelineFor($transaction->items, false);
        $hours = (int) ($plan['durationHours'] ?: ProvisioningRules::STANDARD_HOURS);

        return now()->greaterThanOrEqualTo(Carbon::parse($approvedAt)->addHours($hours));
    }

    private static function provisioningRunFor(SalesTransaction $transaction): ?ProvisioningRun
    {
        if ($transaction->relationLoaded('provisioningRun')) {
            return $transaction->provisioningRun;
        }

        if (! Schema::hasTable('provisioning_runs')) {
            return null;
        }

        return $transaction->provisioningRun()->first();
    }

    private static function runWindowElapsed(ProvisioningRun $run): bool
    {
        if ($run->timeline === 'webdev') {
            if (! $run->webdev_started_at || ! $run->webdev_days) {
                return false;
            }

            return now()->greaterThanOrEqualTo($run->webdev_started_at->copy()->addDays((int) $run->webdev_days));
        }

        if (! $run->countdown_started_at || ! $run->duration_hours) {
            return false;
        }

        return now()->greaterThanOrEqualTo($run->countdown_started_at->copy()->addHours((int) $run->duration_hours));
    }

    public static function hasUnverifiedPaymentProof(SalesTransaction $transaction): bool
    {
        return CustomerPaymentProof::query()
            ->where(function ($query) use ($transaction) {
                $query->where('sales_transaction_id', $transaction->id)
                    ->orWhere('invoice_id', 'INV-' . $transaction->transaction_no);
            })
            ->where('status', 'Pending Review')
            ->exists();
    }

    public static function hasVerifiedPaymentProof(SalesTransaction $transaction): bool
    {
        return CustomerPaymentProof::query()
            ->where(function ($query) use ($transaction) {
                $query->where('sales_transaction_id', $transaction->id)
                    ->orWhere('invoice_id', 'INV-' . $transaction->transaction_no);
            })
            ->where('status', 'Verified & Credited')
            ->exists();
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

    public static function approvedAt(SalesTransaction $transaction): ?string
    {
        if (preg_match('/\[APPROVED_AT:([^\]]+)\]/', (string) $transaction->notes, $match)) {
            try {
                return Carbon::parse(trim($match[1]))->toIso8601String();
            } catch (\Throwable) {
                // Fall through to the verified proof timestamp.
            }
        }

        $proofs = $transaction->relationLoaded('paymentProofs')
            ? $transaction->paymentProofs
            : CustomerPaymentProof::query()
                ->where(function ($query) use ($transaction) {
                    $query->where('sales_transaction_id', $transaction->id)
                        ->orWhere('invoice_id', 'INV-' . $transaction->transaction_no);
                })
                ->get();

        $verified = $proofs
            ->where('status', 'Verified & Credited')
            ->sortByDesc(fn (CustomerPaymentProof $proof) => optional($proof->updated_at)?->timestamp ?? 0)
            ->first();

        return optional($verified?->updated_at)?->toIso8601String();
    }

    public static function appendApprovalStamp(string $notes, string $proofNo, ?Carbon $approvedAt = null): string
    {
        $stamp = ($approvedAt ?? now())->toIso8601String();
        $next = trim($notes);
        $next = trim($next . "\nPayment verified via proof {$proofNo}.");
        if (! preg_match('/\[APPROVED_AT:[^\]]+\]/', $next)) {
            $next .= "\n[APPROVED_AT:{$stamp}]";
        }

        return trim($next);
    }

    private static function orderReceivedNote(string $status): string
    {
        return match ($status) {
            self::STATUS_AWAITING_APPROVAL => 'Payment proof is pending admin approval. Provisioning begins after payment is complete.',
            self::STATUS_PENDING_PAYMENT => 'Upload your payment receipt so billing can confirm it. Provisioning starts after the receipt is approved.',
            self::STATUS_PROVISIONING => 'Payment is confirmed and your services are being provisioned.',
            self::STATUS_ACTIVE => 'Your services are now active.',
            default => 'Provisioning begins after payment is confirmed.',
        };
    }

    public static function clientRenewAt(SalesTransaction $transaction, ?string $status = null): ?Carbon
    {
        $resolved = $status ?? self::resolveServiceStatus($transaction);
        if (! self::isActiveStatus($resolved)) {
            return null;
        }

        return DealMeta::clientSubscriptionEnd($transaction->notes)
            ?? ($transaction->transacted_at?->copy()->addYear() ?? now()->addYear());
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
