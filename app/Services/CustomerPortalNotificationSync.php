<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\CustomerPaymentProof;
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

        foreach ($this->paidTransactionsNeedingProof($transactions, $customerId) as $transaction) {
            $invoiceId = 'INV-' . $transaction->transaction_no;
            $key = 'paynamics-proof:' . $transaction->id;
            $activeKeys[] = $key;
            $this->upsert($customerId, $key, [
                'title' => 'Submit your Paynamics receipt',
                'body' => "Your payment for {$invoiceId} went through. Screenshot or download the Paynamics Payment Success page, then upload it in Billing so we can confirm your receipt.",
                'type' => 'billing',
                'action_url' => '/public/dashboard?tab=billing&submit_proof=1',
            ]);
        }

        CustomerNotification::query()
            ->where('customer_id', $customerId)
            ->where(function ($query) {
                $query->where('reference_key', 'like', 'provisioning:%')
                    ->orWhere('reference_key', 'like', 'payment:%')
                    ->orWhere('reference_key', 'like', 'paynamics-proof:%');
            })
            ->whereNotIn('reference_key', $activeKeys ?: ['__none__'])
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

        $proofNeeded = $this->paidTransactionsNeedingProof($transactions, (int) ($services->first()?->customer_id ?? $transactions->first()?->customer_id ?? 0));
        if ($proofNeeded->isNotEmpty()) {
            $first = $proofNeeded->first();
            $invoiceId = 'INV-' . $first->transaction_no;
            $count = $proofNeeded->count();
            $alerts[] = [
                'id' => 'alert-paynamics-proof',
                'tone' => 'billing',
                'title' => 'Submit your Paynamics receipt',
                'message' => $count === 1
                    ? "Screenshot or download the Paynamics Payment Success page for {$invoiceId}, then upload it in Billing."
                    : "You have {$count} paid invoices waiting for a Paynamics Payment Success screenshot.",
                'actionLabel' => 'Upload Receipt',
                'actionHref' => '/public/dashboard?tab=billing&submit_proof=1',
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

    /**
     * @param  Collection<int, SalesTransaction>  $transactions
     * @return Collection<int, SalesTransaction>
     */
    private function paidTransactionsNeedingProof(Collection $transactions, int $customerId): Collection
    {
        if ($customerId < 1) {
            return collect();
        }

        $paid = $transactions->filter(function (SalesTransaction $row) {
            return in_array(strtolower((string) $row->payment_status), ['paid', 'completed', 'success'], true);
        });

        if ($paid->isEmpty()) {
            return collect();
        }

        $invoiceIds = $paid->map(fn (SalesTransaction $row) => 'INV-' . $row->transaction_no)->all();
        $covered = CustomerPaymentProof::query()
            ->where('customer_id', $customerId)
            ->where(function ($query) use ($paid, $invoiceIds) {
                $query->whereIn('sales_transaction_id', $paid->pluck('id')->all())
                    ->orWhereIn('invoice_id', $invoiceIds);
            })
            ->get();

        return $paid
            ->reject(function (SalesTransaction $row) use ($covered) {
                $invoiceId = 'INV-' . $row->transaction_no;

                return $covered->contains(function (CustomerPaymentProof $proof) use ($row, $invoiceId) {
                    return (int) $proof->sales_transaction_id === (int) $row->id
                        || $proof->invoice_id === $invoiceId;
                });
            })
            ->values();
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
