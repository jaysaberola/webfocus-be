<?php

namespace Database\Seeders;

use App\Models\CustomerService;
use App\Models\User;
use App\Services\ServiceExpirationNotifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Local test data for deal expiration UI + 90/60/30/expired notifications.
 * Run: php artisan db:seed --class=DealExpirationLocalTestSeeder
 */
class DealExpirationLocalTestSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::query()
            ->role('customer')
            ->where('email', 'customer@webfocus.ph')
            ->first();

        if (! $customer) {
            $customer = User::query()->role('customer')->orderBy('id')->first();
        }

        if (! $customer) {
            $this->command?->error('No customer user found. Create a customer account first.');

            return;
        }

        $customerId = (int) $customer->id;
        $today = now()->startOfDay();

        $scenarios = [
            [
                'suffix' => '[TEST-EXP-90]',
                'title' => 'Business Hosting Apex',
                'plan' => 'Business Hosting Apex',
                'status' => 'Active',
                'expires_at' => $today->copy()->addDays(90),
            ],
            [
                'suffix' => '[TEST-EXP-60]',
                'title' => 'Dashboard',
                'plan' => 'Dashboard',
                'status' => 'Active',
                'expires_at' => $today->copy()->addDays(60),
            ],
            [
                'suffix' => '[TEST-EXP-30]',
                'title' => 'Top Level Domain',
                'plan' => 'Top Level Domain',
                'status' => 'Active',
                'expires_at' => $today->copy()->addDays(30),
            ],
            [
                'suffix' => '[TEST-EXP-RENEW]',
                'title' => 'Members Portal',
                'plan' => 'Members Portal',
                'status' => 'Active',
                'expires_at' => $today->copy()->subDays(45),
            ],
            [
                'suffix' => '[TEST-EXP-OK]',
                'title' => 'Mailing List',
                'plan' => 'Mailing List',
                'status' => 'Active',
                'expires_at' => $today->copy()->addDays(180),
            ],
        ];

        $this->command?->info("Seeding expiration test services for customer #{$customerId} ({$customer->email})");

        foreach ($scenarios as $row) {
            $title = trim($row['title'] . ' ' . $row['suffix']);

            CustomerService::query()->updateOrCreate(
                [
                    'customer_id' => $customerId,
                    'title' => $title,
                ],
                [
                    'plan' => $row['plan'],
                    'category' => 'Hosting',
                    'status' => $row['status'],
                    'renew_at' => null,
                    'expires_at' => Carbon::parse($row['expires_at']),
                    'renew_label' => 'Annual',
                    'renew_note' => 'Local expiration test row — safe to delete.',
                ]
            );

            $this->command?->line(sprintf(
                '  • %s → expires %s (%s)',
                $title,
                $row['expires_at']->toDateString(),
                $row['status']
            ));
        }

        // Align existing deal lines (same product names) so Deals tab picks up dates from services API.
        $alignments = [
            ['match' => 'Business Hosting Apex', 'days' => 90],
            ['match' => 'Dashboard', 'days' => 60],
            ['match' => 'Top Level Domain', 'days' => 30],
            ['match' => 'Members Portal', 'days' => -45],
            ['match' => 'Mailing List', 'days' => 180],
        ];

        foreach ($alignments as $item) {
            $expiry = $today->copy()->addDays($item['days']);
            $updated = CustomerService::query()
                ->where('customer_id', $customerId)
                ->where('title', 'not like', '%[TEST-EXP-%')
                ->where(function ($query) use ($item) {
                    $needle = $item['match'];
                    $query->where('title', 'like', '%' . $needle . '%')
                        ->orWhere('plan', 'like', '%' . $needle . '%');
                })
                ->update([
                    'status' => 'Active',
                    'expires_at' => $expiry,
                    'renew_at' => null,
                ]);

            if ($updated > 0) {
                $this->command?->line(sprintf(
                    '  ↳ Updated %d existing "%s" service(s) → %s',
                    $updated,
                    $item['match'],
                    $expiry->toDateString()
                ));
            }
        }

        $notifier = app(ServiceExpirationNotifier::class);
        $sent = $notifier->processAll();
        $notifier->processForCustomer($customerId);

        $this->command?->info("Notifications processed (milestones + expired): {$sent} service action(s).");
        $this->command?->newLine();
        $this->command?->info('How to verify:');
        $this->command?->line('  Customer: login as ' . $customer->email . ' → Notifications / Services');
        $this->command?->line('  Admin: Commerce Admin → Deals + Notifications');
        $this->command?->line('  Re-run reminders: php artisan commerce:notify-expiring-services');
    }
}
