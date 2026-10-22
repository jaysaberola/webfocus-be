<?php

namespace App\Console\Commands;

use App\Services\ServiceExpirationNotifier;
use Illuminate\Console\Command;

class NotifyExpiringServices extends Command
{
    protected $signature = 'commerce:notify-expiring-services';

    protected $description = 'Send customer and admin notifications for services expiring in 90, 60, or 30 days';

    public function handle(ServiceExpirationNotifier $notifier): int
    {
        $sent = $notifier->processAll();
        $this->info("Expiration reminders sent for {$sent} service milestone(s).");

        return self::SUCCESS;
    }
}
