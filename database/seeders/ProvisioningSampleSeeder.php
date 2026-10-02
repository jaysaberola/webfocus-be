<?php

namespace Database\Seeders;

use App\Models\CustomerPaymentProof;
use App\Models\CustomerService;
use App\Models\ProvisioningAction;
use App\Models\ProvisioningEvent;
use App\Models\ProvisioningRun;
use App\Models\SalesTransaction;
use App\Models\SalesTransactionItem;
use App\Models\User;
use App\Services\CustomerPortalProvisioner;
use App\Services\ProvisioningWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProvisioningSampleSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'customer'))
            ->orderBy('id')
            ->first();
        $admin = User::query()->where('email', 'admin@wsi.com')->first()
            ?? User::query()->whereHas('roles', fn ($query) => $query->where('name', 'admin'))->first();
        $technical = User::query()->where('email', 'support@webfocus.ph')->first() ?? $admin;
        $sales = User::query()->where('email', 'sales@webfocus.ph')->first() ?? $admin;

        if (! $customer || ! $admin) {
            $this->command?->error('Need a customer account and an admin account before seeding provisioning samples.');

            return;
        }

        $customerName = trim(($customer->fname ?? '') . ' ' . ($customer->lname ?? '')) ?: 'Sample Customer';

        DB::transaction(function () use ($customer, $admin, $technical, $sales, $customerName) {
            $this->clearSamples();

            $pending = $this->order($customer, $customerName, 'ST-SAMPLE-APPROVAL-PENDING', 'unpaid', 'pending', [
                ['name' => 'Business Hosting', 'item_type' => 'service', 'price' => 2490],
            ], 'Sample pending approval. Use Approve Order to start the 48-hour countdown.');
            $this->proof($customer, $pending, 'PP-SAMPLE-PENDING', 'Pending Review');

            $hosting = $this->order($customer, $customerName, 'ST-SAMPLE-APPROVAL-HOST', 'paid', 'processing', [
                ['name' => 'Business Hosting', 'item_type' => 'service', 'price' => 2490],
                ['name' => 'sample-hosting.ph', 'item_type' => 'domain', 'price' => 890],
            ], "Sample provisioning order.\n[APPROVED_AT:" . now()->subHours(6)->toIso8601String() . ']');
            $this->proof($customer, $hosting, 'PP-SAMPLE-HOST', 'Verified & Credited');
            app(CustomerPortalProvisioner::class)->refreshServicesFromTransaction($hosting->fresh('items'));
            $hostRun = app(ProvisioningWorkflow::class)->openFromApproval($hosting, $admin, 'PP-SAMPLE-HOST');
            $hostRun->update([
                'approved_at' => now()->subHours(6),
                'countdown_started_at' => now()->subHours(6),
            ]);
            ProvisioningAction::query()
                ->where('provisioning_run_id', $hostRun->id)
                ->update(['due_at' => now()->subHours(6)->addHours(48)]);
            $this->action($hostRun, $hosting, $admin, $technical, 'Business Hosting', 'Create the hosting account and send the login details.', 'pending', 12);
            $done = $this->action($hostRun, $hosting, $admin, $technical, 'sample-hosting.ph', 'Point the domain nameservers to the new host.', 'done', 12);
            $done->update([
                'done_at' => now()->subHours(2),
                'due_at' => now()->subHours(6)->addHours(48),
            ]);
            app(ProvisioningWorkflow::class)->sync($hostRun->fresh());

            $web = $this->order($customer, $customerName, 'ST-SAMPLE-APPROVAL-WEB', 'paid', 'processing', [
                ['name' => 'Business Starter Launch', 'item_type' => 'web_design', 'price' => 35000],
                ['name' => 'Dashboard', 'item_type' => 'web_design_addon', 'price' => 0],
            ], "Sample WebDev order. Sales starts the 30–90 day countdown.\n[APPROVED_AT:" . now()->subDay()->toIso8601String() . ']');
            $this->proof($customer, $web, 'PP-SAMPLE-WEB', 'Verified & Credited');
            app(CustomerPortalProvisioner::class)->refreshServicesFromTransaction($web->fresh('items'));
            $webRun = app(ProvisioningWorkflow::class)->openFromApproval($web, $admin, 'PP-SAMPLE-WEB');
            $webRun->update(['approved_at' => now()->subDay()]);
            $this->action($webRun, $web, $sales, $technical, 'Business Starter Launch', 'Collect the brand assets and sitemap.', 'pending', 24);
        });

        $this->command?->info('Sample approvals are ready: PP-SAMPLE-PENDING, PP-SAMPLE-HOST, PP-SAMPLE-WEB for ' . $customer->email . '.');
    }

    private function clearSamples(): void
    {
        $ids = SalesTransaction::withTrashed()
            ->whereIn('transaction_no', [
                'ST-SAMPLE-APPROVAL-PENDING',
                'ST-SAMPLE-APPROVAL-HOST',
                'ST-SAMPLE-APPROVAL-WEB',
            ])
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        CustomerPaymentProof::query()->whereIn('sales_transaction_id', $ids)->orWhereIn('proof_no', [
            'PP-SAMPLE-PENDING',
            'PP-SAMPLE-HOST',
            'PP-SAMPLE-WEB',
        ])->delete();
        ProvisioningEvent::query()->whereIn('sales_transaction_id', $ids)->delete();
        ProvisioningAction::query()->whereIn('sales_transaction_id', $ids)->delete();
        ProvisioningRun::query()->whereIn('sales_transaction_id', $ids)->delete();
        CustomerService::query()->whereIn('sales_transaction_id', $ids)->delete();
        SalesTransactionItem::query()->whereIn('sales_transaction_id', $ids)->delete();
        SalesTransaction::withTrashed()->whereIn('id', $ids)->forceDelete();
    }

    private function order(User $customer, string $customerName, string $number, string $payment, string $orderStatus, array $items, string $notes): SalesTransaction
    {
        $total = array_sum(array_column($items, 'price'));
        $transaction = SalesTransaction::create([
            'transaction_no' => $number,
            'customer_id' => $customer->id,
            'customer_name' => $customerName,
            'customer_email' => $customer->email,
            'subtotal' => $total,
            'grand_total' => $total,
            'payment_status' => $payment,
            'order_status' => $orderStatus,
            'notes' => $notes,
            'transacted_at' => now()->subDays(2),
            'user_id' => $customer->id,
        ]);

        foreach ($items as $item) {
            SalesTransactionItem::create([
                'sales_transaction_id' => $transaction->id,
                'name' => $item['name'],
                'item_type' => $item['item_type'],
                'price' => $item['price'],
                'quantity' => 1,
                'total_price' => $item['price'],
            ]);
        }

        return $transaction->fresh('items');
    }

    private function proof(User $customer, SalesTransaction $transaction, string $proofNo, string $status): void
    {
        CustomerPaymentProof::create([
            'customer_id' => $customer->id,
            'sales_transaction_id' => $transaction->id,
            'proof_no' => $proofNo,
            'invoice_id' => 'INV-' . $transaction->transaction_no,
            'file_path' => 'payment-proofs/sample-receipt.txt',
            'file_name' => 'sample-receipt.txt',
            'status' => $status,
            'notes' => 'Sample payment proof for provisioning tests.',
        ]);
    }

    private function action(
        ProvisioningRun $run,
        SalesTransaction $transaction,
        User $author,
        ?User $assignee,
        string $serviceName,
        string $description,
        string $status,
        int $checkpoint,
    ): ProvisioningAction {
        $serviceId = CustomerService::query()
            ->where('sales_transaction_id', $transaction->id)
            ->where('title', $serviceName)
            ->value('id');

        $action = ProvisioningAction::create([
            'provisioning_run_id' => $run->id,
            'sales_transaction_id' => $transaction->id,
            'customer_service_id' => $serviceId,
            'service_name' => $serviceName,
            'service_kind' => $run->timeline === 'webdev' ? 'webdev' : 'standard',
            'description' => $description,
            'assigned_to' => $assignee?->id,
            'created_by' => $author->id,
            'status' => $status,
            'checkpoint_hours' => $checkpoint,
            'due_at' => $run->timeline === 'webdev' ? null : $run->countdown_started_at?->copy()->addHours((int) $run->duration_hours),
        ]);

        ProvisioningEvent::create([
            'provisioning_run_id' => $run->id,
            'sales_transaction_id' => $transaction->id,
            'user_id' => $author->id,
            'event' => 'action_created',
            'summary' => "Action added for {$serviceName}.",
            'changes' => [
                ['label' => 'Action', 'from' => '', 'to' => $description],
                ['label' => 'Assigned to', 'from' => '', 'to' => trim(($assignee->fname ?? '') . ' ' . ($assignee->lname ?? '')) ?: 'Unassigned'],
                ['label' => 'Status', 'from' => '', 'to' => ucfirst($status)],
            ],
        ]);

        return $action;
    }
}
