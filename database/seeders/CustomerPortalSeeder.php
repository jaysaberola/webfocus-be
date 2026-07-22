<?php

namespace Database\Seeders;

use App\Models\CustomerNotification;
use App\Models\CustomerService;
use App\Models\CustomerSupportTicket;
use App\Models\SalesTransaction;
use App\Models\SalesTransactionItem;
use App\Models\User;
use App\Services\CustomerPortalProvisioner;
use App\Services\CustomerPortalNotificationSync;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class CustomerPortalSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::query()->where('email', 'customer@webfocus.ph')->first();
        if (!$customer) {
            return;
        }

        CustomerService::query()->where('customer_id', $customer->id)->delete();
        CustomerNotification::query()->where('customer_id', $customer->id)->delete();
        CustomerSupportTicket::query()->where('customer_id', $customer->id)->delete();

        $transactionIds = SalesTransaction::query()
            ->where('customer_id', $customer->id)
            ->pluck('id');

        SalesTransactionItem::query()->whereIn('sales_transaction_id', $transactionIds)->delete();
        SalesTransaction::query()->where('customer_id', $customer->id)->delete();

        $txnPaidDesign = $this->createTransaction($customer, [
            'transaction_no' => 'WF-JOB-9921',
            'grand_total' => 32000,
            'payment_status' => 'paid',
            'order_status' => 'active',
            'notes' => 'BDO Wire',
            'transacted_at' => Carbon::parse('2026-06-01'),
            'items' => [
                ['name' => 'Custom Web Design', 'item_type' => 'service', 'total_price' => 32000],
            ],
        ]);

        $txnPaidHosting = $this->createTransaction($customer, [
            'transaction_no' => 'WF-JOB-9922',
            'grand_total' => 4500,
            'payment_status' => 'paid',
            'order_status' => 'completed',
            'notes' => 'GCash',
            'transacted_at' => Carbon::parse('2026-06-15'),
            'items' => [
                ['name' => 'Cloud Micro Server (Annual)', 'item_type' => 'service', 'total_price' => 4500],
            ],
        ]);

        $txnPaidDomain = $this->createTransaction($customer, [
            'transaction_no' => 'WF-JOB-9923',
            'grand_total' => 2800,
            'payment_status' => 'paid',
            'order_status' => 'live',
            'notes' => 'Paynamics IPG',
            'transacted_at' => Carbon::parse('2026-06-20'),
            'items' => [
                ['name' => 'Country Level Domain', 'item_type' => 'domain', 'total_price' => 2800],
            ],
        ]);

        $txnPendingDms = $this->createTransaction($customer, [
            'transaction_no' => 'WF-JOB-9924',
            'grand_total' => 18500,
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'notes' => 'Maya',
            'transacted_at' => Carbon::parse('2026-07-02'),
            'items' => [
                ['name' => 'Enterprise Document Management Suite', 'item_type' => 'service', 'total_price' => 18500],
            ],
        ]);

        $txnPendingDedicated = $this->createTransaction($customer, [
            'transaction_no' => 'WF-JOB-9925',
            'grand_total' => 32000,
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'notes' => 'Paynamics',
            'transacted_at' => Carbon::parse('2026-06-07'),
            'items' => [
                ['name' => 'Dedicated_Professional', 'item_type' => 'service', 'total_price' => 32000],
            ],
        ]);

        $renewActive = Carbon::parse('2027-04-20 08:14:00');

        CustomerService::create([
            'customer_id' => $customer->id,
            'sales_transaction_id' => $txnPaidDomain->id,
            'title' => 'Country Level Domain',
            'category' => 'Domains',
            'plan' => 'Country Level Domain',
            'status' => 'Active',
            'renew_label' => 'Renews',
            'renew_at' => $renewActive,
            'renew_note' => CustomerPortalProvisioner::formatRenewNote($renewActive),
        ]);

        CustomerService::create([
            'customer_id' => $customer->id,
            'sales_transaction_id' => $txnPendingDedicated->id,
            'title' => 'Dedicated_Professional',
            'category' => 'Dedicated Server',
            'plan' => 'Dedicated_Professional',
            'status' => 'Provisioning',
            'renew_label' => 'Renewal Schedule',
            'renew_at' => null,
            'renew_note' => 'Your renewal date will appear once this service is live.',
        ]);

        foreach ([
            ['title' => 'Business', 'plan' => 'Business', 'category' => 'Shared Hosting', 'renew_at' => Carbon::parse('2026-04-25 16:07:00')],
            ['title' => 'Dedicated_Corporate', 'plan' => 'Dedicated_Corporate', 'category' => 'Dedicated Server', 'renew_at' => Carbon::parse('2026-04-26 18:41:00')],
            ['title' => 'Dedicated BareMetal_Linux', 'plan' => 'Dedicated BareMetal_Linux', 'category' => 'Dedicated Server', 'renew_at' => Carbon::parse('2026-04-26 21:31:00')],
        ] as $row) {
            CustomerService::create([
                'customer_id' => $customer->id,
                'title' => $row['title'],
                'category' => $row['category'],
                'plan' => $row['plan'],
                'status' => 'Expired',
                'renew_label' => 'Renews',
                'renew_at' => $row['renew_at'],
                'renew_note' => CustomerPortalProvisioner::formatRenewNote($row['renew_at']),
            ]);
        }

        CustomerNotification::create([
            'customer_id' => $customer->id,
            'title' => 'Scheduled Datacenter Upgrade',
            'body' => 'McKinley node maintenance scheduled for July 15, 2026 at 02:00 AM PHT.',
            'type' => 'maintenance',
            'action_url' => '/public/dashboard?tab=notification',
        ]);

        CustomerNotification::create([
            'customer_id' => $customer->id,
            'title' => 'Invoice Generated',
            'body' => 'Invoice INV-WF-JOB-9925 for ₱32,000 has been issued.',
            'read_at' => now()->subDays(10),
            'type' => 'billing',
            'action_url' => '/public/dashboard?tab=billing',
        ]);

        CustomerNotification::create([
            'customer_id' => $customer->id,
            'title' => 'Domain Lock Active',
            'body' => 'Your domain registration for myphilippinebrand.com.ph is secured with DNSSEC.',
            'read_at' => now()->subDays(30),
            'type' => 'general',
            'action_url' => '/public/dashboard?tab=overview',
        ]);

        CustomerSupportTicket::create([
            'customer_id' => $customer->id,
            'ticket_no' => 'TKT-4912',
            'subject' => 'DNSSEC Configuration Assistance',
            'status' => 'Resolved',
            'message' => 'Please enable DNSSEC for my domain.',
            'created_at' => Carbon::parse('2026-06-20'),
        ]);

        CustomerSupportTicket::create([
            'customer_id' => $customer->id,
            'ticket_no' => 'TKT-4820',
            'subject' => 'SSL Certificate Auto-Renewal Verification',
            'status' => 'Open',
            'message' => 'Confirm auto-renewal is enabled for wildcard SSL.',
            'created_at' => Carbon::parse('2026-07-05'),
        ]);

        app(CustomerPortalNotificationSync::class)->syncForCustomer($customer->id);
    }

    private function createTransaction(User $customer, array $payload): SalesTransaction
    {
        $items = $payload['items'];
        unset($payload['items']);

        $transaction = SalesTransaction::create(array_merge([
            'customer_id' => $customer->id,
            'customer_name' => trim($customer->fname . ' ' . $customer->lname),
            'customer_email' => $customer->email,
            'subtotal' => $payload['grand_total'],
            'discount_total' => 0,
            'tax_total' => 0,
            'shipping_total' => 0,
        ], $payload));

        foreach ($items as $item) {
            SalesTransactionItem::create([
                'sales_transaction_id' => $transaction->id,
                'name' => $item['name'],
                'item_type' => $item['item_type'] ?? 'service',
                'price' => $item['total_price'],
                'quantity' => 1,
                'total_price' => $item['total_price'],
            ]);
        }

        return $transaction;
    }
}
