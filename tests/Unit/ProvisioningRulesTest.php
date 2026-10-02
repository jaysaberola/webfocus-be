<?php

namespace Tests\Unit;

use App\Support\ProvisioningRules;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ProvisioningRulesTest extends TestCase
{
    public function test_web_design_orders_wait_for_a_manual_countdown(): void
    {
        $plan = ProvisioningRules::timelineFor([
            ['name' => 'Business Starter Launch', 'item_type' => 'web_design'],
            ['name' => 'Dashboard', 'item_type' => 'web_design_addon'],
        ], true);

        $this->assertSame('webdev', $plan['timeline']);
        $this->assertNull($plan['durationHours']);
        $this->assertSame(24, ProvisioningRules::checkpointFor('webdev'));
    }

    public function test_domain_orders_use_24_hours_and_hosting_uses_48(): void
    {
        $domain = ProvisioningRules::timelineFor([
            ['name' => 'example.ph', 'item_type' => 'domain'],
        ], false);
        $hosting = ProvisioningRules::timelineFor([
            ['name' => 'Business Hosting', 'item_type' => 'service'],
            ['name' => 'example.ph', 'item_type' => 'domain'],
        ], false);

        $this->assertSame('standard', $domain['timeline']);
        $this->assertSame(24, $domain['durationHours']);
        $this->assertSame(48, $hosting['durationHours']);
        $this->assertSame(12, ProvisioningRules::checkpointFor('standard'));
    }

    public function test_done_inside_the_countdown_becomes_completed_then_active_after_the_checkpoint(): void
    {
        $doneAt = Carbon::parse('2026-10-02 08:00:00');
        $dueAt = Carbon::parse('2026-10-04 08:00:00');

        $completed = ProvisioningRules::syncAction('done', $doneAt, null, $dueAt, 12, $doneAt->copy()->addHour());
        $this->assertSame('completed', $completed['status']);
        $this->assertSame(['completed'], $completed['events']);

        $stillCompleted = ProvisioningRules::syncAction(
            'completed',
            $doneAt,
            $doneAt,
            $dueAt,
            12,
            $doneAt->copy()->addHours(11)
        );
        $this->assertSame('completed', $stillCompleted['status']);
        $this->assertSame([], $stillCompleted['events']);

        $active = ProvisioningRules::syncAction(
            'completed',
            $doneAt,
            $doneAt,
            $dueAt,
            12,
            $doneAt->copy()->addHours(12)
        );
        $this->assertSame('active', $active['status']);
        $this->assertSame(['active'], $active['events']);
    }

    public function test_late_done_does_not_become_completed_or_active(): void
    {
        $dueAt = Carbon::parse('2026-10-02 08:00:00');
        $doneAt = $dueAt->copy()->addHour();

        $late = ProvisioningRules::syncAction('done', $doneAt, null, $dueAt, 12, $doneAt->copy()->addHours(24));

        $this->assertSame('done', $late['status']);
        $this->assertSame([], $late['events']);
        $this->assertNull($late['validatedAt']);
    }

    public function test_webdev_checkpoint_waits_24_hours(): void
    {
        $doneAt = Carbon::parse('2026-10-02 08:00:00');
        $dueAt = $doneAt->copy()->addDays(40);

        $early = ProvisioningRules::syncAction('completed', $doneAt, $doneAt, $dueAt, 24, $doneAt->copy()->addHours(23));
        $this->assertSame('completed', $early['status']);

        $active = ProvisioningRules::syncAction('completed', $doneAt, $doneAt, $dueAt, 24, $doneAt->copy()->addHours(24));
        $this->assertSame('active', $active['status']);
    }
}
