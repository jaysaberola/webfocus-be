<?php

namespace Database\Seeders;

use App\Models\CustomerNotification;
use App\Models\CustomerService;
use App\Models\ProvisioningAction;
use App\Models\ProvisioningEvent;
use App\Models\ProvisioningRun;
use App\Models\SalesTransaction;
use App\Models\SalesTransactionItem;
use App\Models\User;
use App\Services\CustomerPortalNotificationSync;
use App\Services\CustomerPortalProvisioner;
use App\Services\ServiceExpirationNotifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Dedicated demo customer for deal expiration, invoice lines, notifications, and client deals UI.
 *
 * Run: php artisan db:seed --class=DealDemoCustomerSeeder
 *
 * Login (customer portal): deals.test@webfocus.ph / password
 */
class DealDemoCustomerSeeder extends Seeder
{
    public const DEMO_EMAIL = 'deals.test@webfocus.ph';

    /** @var list<string> */
    private const DEMO_TRANSACTION_NOS = [
        'WF-DEMO-MULTI',
        'WF-DEMO-EXP90',
        'WF-DEMO-EXP60',
        'WF-DEMO-EXP30',
        'WF-DEMO-RENEW',
        'WF-DEMO-OK',
        'WF-DEMO-PENDING',
    ];

    public function run(): void
    {
        $customerRole = Role::firstOrCreate(
            ['name' => 'customer', 'guard_name' => 'sanctum'],
            ['description' => 'Customer']
        );

        $salesOwner = User::query()->where('email', 'sales@webfocus.ph')->first();
        $admin = User::query()->where('email', 'admin@wsi.com')->first()
            ?? User::query()->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first();

        $customer = User::query()->updateOrCreate(
            ['email' => self::DEMO_EMAIL],
            [
                'fname' => 'Maria',
                'lname' => 'Santos',
                'mname' => 'Demo Deals Testing Corp',
                'contact_person' => 'Maria Santos',
                'mobile' => '+639171112233',
                'phone' => '88112233',
                'address_street' => '123 Test Lane, Ortigas',
                'address_city' => 'Pasig City',
                'address_province' => 'Metro Manila',
                'address_zip' => '1605',
                'password' => Hash::make('password'),
                'is_active' => true,
                'owner_id' => $salesOwner?->id,
                'website' => 'https://demo-deals.webfocus.ph',
            ]
        );
        $customer->syncRoles([$customerRole]);

        DB::transaction(function () use ($customer, $salesOwner, $admin) {
            $this->clearDemoData($customer);

            $today = now()->startOfDay();
            $customerName = trim($customer->fname . ' ' . $customer->lname);

            $multi = $this->createOrder($customer, $customerName, $salesOwner?->id, [
                'transaction_no' => 'WF-DEMO-MULTI',
                'grand_total' => 23300,
                'payment_status' => 'paid',
                'order_status' => 'active',
                'transacted_at' => $today->copy()->subDays(14),
                'notes' => $this->dealNotes([
                    'dealNames' => ['Business Hosting Apex', 'Dashboard', 'Top Level Domain'],
                    'dealAmounts' => [
                        'Business Hosting Apex' => '12000',
                        'Dashboard' => '8500',
                        'Top Level Domain' => '2800',
                    ],
                    'dealStatus' => 'Active',
                    'salesStatus' => 'Closed Won',
                    'domainName' => 'demo-multiline.webfocus.ph',
                    'productCategory' => 'Web Hosting - Shared',
                ], 'Demo: 3 deal lines on one invoice (Invoice ID + Deal Amount per line).'),
                'items' => [
                    ['name' => 'Business Hosting Apex', 'item_type' => 'service', 'total_price' => 12000],
                    ['name' => 'Dashboard', 'item_type' => 'service', 'total_price' => 8500],
                    ['name' => 'Top Level Domain', 'item_type' => 'domain', 'total_price' => 2800],
                ],
            ]);

            $exp90 = $this->createOrder($customer, $customerName, $salesOwner?->id, [
                'transaction_no' => 'WF-DEMO-EXP90',
                'grand_total' => 12000,
                'payment_status' => 'paid',
                'order_status' => 'active',
                'transacted_at' => $today->copy()->subMonths(3),
                'notes' => $this->dealNotes([
                    'dealName' => 'Business Hosting Apex',
                    'dealStatus' => 'Active',
                    'subscriptionEndDate' => $today->copy()->addDays(90)->toDateString(),
                ], 'Demo: expiration ~90 days → 90-day notification.'),
                'items' => [
                    ['name' => 'Business Hosting Apex', 'item_type' => 'service', 'total_price' => 12000],
                ],
            ]);

            $exp60 = $this->createOrder($customer, $customerName, $salesOwner?->id, [
                'transaction_no' => 'WF-DEMO-EXP60',
                'grand_total' => 8500,
                'payment_status' => 'paid',
                'order_status' => 'active',
                'transacted_at' => $today->copy()->subMonths(4),
                'notes' => $this->dealNotes([
                    'dealName' => 'Dashboard',
                    'dealStatus' => 'Active',
                    'subscriptionEndDate' => $today->copy()->addDays(60)->toDateString(),
                ], 'Demo: expiration ~60 days → 60-day notification.'),
                'items' => [
                    ['name' => 'Dashboard', 'item_type' => 'service', 'total_price' => 8500],
                ],
            ]);

            $exp30 = $this->createOrder($customer, $customerName, $salesOwner?->id, [
                'transaction_no' => 'WF-DEMO-EXP30',
                'grand_total' => 2800,
                'payment_status' => 'paid',
                'order_status' => 'active',
                'transacted_at' => $today->copy()->subMonths(5),
                'notes' => $this->dealNotes([
                    'dealName' => 'Top Level Domain',
                    'dealStatus' => 'Active',
                    'subscriptionEndDate' => $today->copy()->addDays(30)->toDateString(),
                ], 'Demo: expiration ~30 days → 30-day notification + countdown styling.'),
                'items' => [
                    ['name' => 'Top Level Domain', 'item_type' => 'domain', 'total_price' => 2800],
                ],
            ]);

            $renew = $this->createOrder($customer, $customerName, $salesOwner?->id, [
                'transaction_no' => 'WF-DEMO-RENEW',
                'grand_total' => 6500,
                'payment_status' => 'paid',
                'order_status' => 'active',
                'transacted_at' => $today->copy()->subYear(),
                'notes' => $this->dealNotes([
                    'dealName' => 'Members Portal',
                    'dealStatus' => 'Active',
                    'subscriptionEndDate' => $today->copy()->subDays(45)->toDateString(),
                ], 'Demo: expired service → Renew status + renewal notifications.'),
                'items' => [
                    ['name' => 'Members Portal', 'item_type' => 'service', 'total_price' => 6500],
                ],
            ]);

            $ok = $this->createOrder($customer, $customerName, $salesOwner?->id, [
                'transaction_no' => 'WF-DEMO-OK',
                'grand_total' => 3200,
                'payment_status' => 'paid',
                'order_status' => 'completed',
                'transacted_at' => $today->copy()->subWeeks(2),
                'notes' => $this->dealNotes([
                    'dealName' => 'Mailing List',
                    'dealStatus' => 'Active',
                    'subscriptionEndDate' => $today->copy()->addDays(180)->toDateString(),
                ], 'Demo: far expiry — no milestone alert today.'),
                'items' => [
                    ['name' => 'Mailing List', 'item_type' => 'service', 'total_price' => 3200],
                ],
            ]);

            $this->createOrder($customer, $customerName, $salesOwner?->id, [
                'transaction_no' => 'WF-DEMO-PENDING',
                'grand_total' => 9900,
                'payment_status' => 'pending',
                'order_status' => 'pending',
                'transacted_at' => $today->copy()->subDays(3),
                'notes' => $this->dealNotes([
                    'dealName' => 'Cloud Micro Server (Annual)',
                    'dealStatus' => 'Pending',
                    'salesStatus' => 'Open',
                ], 'Demo: pending payment — deal status not Renew even if dates pass.'),
                'items' => [
                    ['name' => 'Cloud Micro Server (Annual)', 'item_type' => 'service', 'total_price' => 9900],
                ],
            ]);

            $provisioner = app(CustomerPortalProvisioner::class);
            foreach ([$multi, $exp90, $exp60, $exp30, $renew, $ok] as $txn) {
                $provisioner->refreshServicesFromTransaction($txn->fresh('items'));
            }

            $this->setServiceExpiry($customer->id, 'Business Hosting Apex', $today->copy()->addDays(90), $multi->id);
            $this->setServiceExpiry($customer->id, 'Dashboard', $today->copy()->addDays(60), $multi->id);
            $this->setServiceExpiry($customer->id, 'Top Level Domain', $today->copy()->addDays(30), $multi->id);
            $this->setServiceExpiry($customer->id, 'Business Hosting Apex', $today->copy()->addDays(90), $exp90->id);
            $this->setServiceExpiry($customer->id, 'Dashboard', $today->copy()->addDays(60), $exp60->id);
            $this->setServiceExpiry($customer->id, 'Top Level Domain', $today->copy()->addDays(30), $exp30->id);
            $this->setServiceExpiry($customer->id, 'Members Portal', $today->copy()->subDays(45), $renew->id);
            $this->setServiceExpiry($customer->id, 'Mailing List', $today->copy()->addDays(180), $ok->id);

            if ($admin) {
                $this->seedProvisioningSample($multi, $admin);
            }
        });

        $notifier = app(ServiceExpirationNotifier::class);
        $notifier->processForCustomer((int) $customer->id);
        app(CustomerPortalNotificationSync::class)->syncForCustomer((int) $customer->id);

        $this->printGuide($customer);
    }

    private function clearDemoData(User $customer): void
    {
        $ids = SalesTransaction::withTrashed()
            ->where('customer_id', $customer->id)
            ->whereIn('transaction_no', self::DEMO_TRANSACTION_NOS)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        ProvisioningEvent::query()->whereIn('sales_transaction_id', $ids)->delete();
        ProvisioningAction::query()->whereIn('sales_transaction_id', $ids)->delete();
        ProvisioningRun::query()->whereIn('sales_transaction_id', $ids)->delete();
        CustomerService::query()->whereIn('sales_transaction_id', $ids)->delete();
        SalesTransactionItem::query()->whereIn('sales_transaction_id', $ids)->delete();
        SalesTransaction::withTrashed()->whereIn('id', $ids)->forceDelete();

        CustomerNotification::query()
            ->where('customer_id', $customer->id)
            ->where(function ($query) {
                $query->where('body', 'like', '%WF-DEMO-%')
                    ->orWhere('reference_key', 'like', 'service-expiry:%')
                    ->orWhere('reference_key', 'like', 'service-renewal:%');
            })
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createOrder(User $customer, string $customerName, ?int $ownerId, array $payload): SalesTransaction
    {
        $items = $payload['items'];
        unset($payload['items']);

        /** @var SalesTransaction $transaction */
        $transaction = SalesTransaction::query()->create(array_merge([
            'customer_id' => $customer->id,
            'customer_name' => $customerName,
            'customer_email' => $customer->email,
            'subtotal' => $payload['grand_total'],
            'discount_total' => 0,
            'tax_total' => 0,
            'shipping_total' => 0,
            'user_id' => $customer->id,
            'client_owner_id' => $ownerId,
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

        return $transaction->fresh('items');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function dealNotes(array $meta, string $humanNote = ''): string
    {
        $line = '[DEAL_META]' . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return trim($humanNote . "\n" . $line);
    }

    private function setServiceExpiry(int $customerId, string $title, Carbon $expiresAt, int $transactionId): void
    {
        CustomerService::query()
            ->where('customer_id', $customerId)
            ->where('sales_transaction_id', $transactionId)
            ->where('title', $title)
            ->update([
                'status' => 'Active',
                'expires_at' => $expiresAt,
                'renew_at' => null,
            ]);
    }

    private function seedProvisioningSample(SalesTransaction $transaction, User $admin): void
    {
        $approvedAt = now()->subDays(10);

        $run = ProvisioningRun::create([
            'sales_transaction_id' => $transaction->id,
            'timeline' => 'standard',
            'duration_hours' => 48,
            'status' => 'in_progress',
            'approved_at' => $approvedAt,
            'approved_by' => $admin->id,
            'countdown_started_at' => $approvedAt,
            'countdown_started_by' => $admin->id,
        ]);

        foreach (['Business Hosting Apex', 'Dashboard', 'Top Level Domain'] as $index => $serviceName) {
            $serviceId = CustomerService::query()
                ->where('sales_transaction_id', $transaction->id)
                ->where('title', $serviceName)
                ->value('id');

            ProvisioningAction::create([
                'provisioning_run_id' => $run->id,
                'sales_transaction_id' => $transaction->id,
                'customer_service_id' => $serviceId,
                'service_name' => $serviceName,
                'service_kind' => 'standard',
                'description' => 'Demo approval checkpoint for ' . $serviceName,
                'assigned_to' => $admin->id,
                'created_by' => $admin->id,
                'status' => 'done',
                'checkpoint_hours' => 48,
                'due_at' => $approvedAt->copy()->addHours(48),
                'done_at' => $approvedAt->copy()->addDays(2 + $index),
            ]);
        }
    }

    private function printGuide(User $customer): void
    {
        $this->command?->newLine();
        $this->command?->info('=== Deal demo customer ready ===');
        $this->command?->line('  Email:    ' . self::DEMO_EMAIL);
        $this->command?->line('  Password: password');
        $this->command?->line('  User ID:  #' . $customer->id);
        $this->command?->newLine();
        $this->command?->info('Test one-by-one (Commerce Admin → Clients → open Maria Santos / Demo Deals Testing Corp):');
        $this->command?->table(
            ['Invoice', 'What to check'],
            [
                ['WF-DEMO-MULTI', 'Invoice ID: 3 lines badge + ↳ 2/3; Deal Amount per line; Approved Date per line'],
                ['WF-DEMO-EXP90', 'Deal Expiration countdown; notification 90 days before expiry'],
                ['WF-DEMO-EXP60', '60-day milestone notification'],
                ['WF-DEMO-EXP30', '30-day milestone + “soon” styling'],
                ['WF-DEMO-RENEW', 'Deal Status Renew; expired renewal toast/inbox; Orders ?renew=1'],
                ['WF-DEMO-OK', 'Normal active deal (~180 days left)'],
                ['WF-DEMO-PENDING', 'Pending — no Renew override from expiry logic'],
            ]
        );
        $this->command?->newLine();
        $this->command?->line('Customer portal: login as ' . self::DEMO_EMAIL . ' → Notifications / Services / Orders.');
        $this->command?->line('Admin inbox: Commerce Admin bell → service_expiry / service_renewal items.');
        $this->command?->line('Re-run reminders: php artisan commerce:notify-expiring-services');
        $this->command?->line('Re-seed anytime: php artisan db:seed --class=DealDemoCustomerSeeder');
    }
}
