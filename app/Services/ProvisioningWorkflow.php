<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\CustomerService;
use App\Models\ProvisioningAction;
use App\Models\ProvisioningEvent;
use App\Models\ProvisioningRun;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Support\ProvisioningRules;
use App\Support\TransactionLabelResolver;
use App\Support\WebDesignQuotation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ProvisioningWorkflow
{
    public function openFromApproval(SalesTransaction $transaction, User $actor, ?string $proofNo = null): ?ProvisioningRun
    {
        $order = strtolower((string) $transaction->order_status);
        if (in_array($order, ['cancelled', 'canceled'], true)) {
            return null;
        }

        $existing = ProvisioningRun::query()
            ->where('sales_transaction_id', $transaction->id)
            ->first();
        if ($existing) {
            return $existing;
        }

        $transaction->loadMissing('items');
        $plan = ProvisioningRules::timelineFor($transaction->items, WebDesignQuotation::isWebDesign($transaction));
        $now = now();
        $durationHours = (int) ($plan['durationHours'] ?? 0);
        $durationMinutes = (int) ($plan['durationMinutes'] ?? 0);
        $startsNow = $plan['timeline'] !== 'webdev' && ($durationHours > 0 || $durationMinutes > 0);

        return DB::transaction(function () use ($transaction, $actor, $proofNo, $plan, $now, $startsNow, $durationHours, $durationMinutes) {
            $run = ProvisioningRun::create([
                'sales_transaction_id' => $transaction->id,
                'timeline' => $plan['timeline'],
                'duration_hours' => $durationHours > 0 ? $durationHours : null,
                'duration_minutes' => $durationMinutes > 0 && $durationHours <= 0 ? $durationMinutes : null,
                'status' => 'provisioning',
                'approved_at' => $now,
                'approved_by' => $actor->id,
                'countdown_started_at' => $startsNow ? $now : null,
                'countdown_started_by' => $startsNow ? $actor->id : null,
            ]);

            $this->record($run, $actor, 'order_approved', 'Order approved' . ($proofNo ? " via {$proofNo}" : '') . '.', [
                ['label' => 'Order status', 'from' => 'Awaiting Approval', 'to' => 'Provisioning'],
            ]);

            if ($startsNow) {
                $windowLabel = $durationMinutes > 0 && $durationHours <= 0
                    ? "{$durationMinutes} minutes"
                    : "{$durationHours} hours";
                $this->record(
                    $run,
                    $actor,
                    'provisioning_started',
                    "Provisioning started with a {$windowLabel} countdown.",
                    [
                        ['label' => 'Countdown', 'from' => 'Not started', 'to' => $windowLabel],
                    ]
                );
            } elseif ($plan['timeline'] === 'webdev') {
                $this->record(
                    $run,
                    $actor,
                    'provisioning_started',
                    'Provisioning started. The WebDev countdown waits for Sales/Production (30–90 days).',
                    [
                        ['label' => 'WebDev countdown', 'from' => 'Not started', 'to' => 'Waiting for Sales/Production'],
                    ]
                );
            } else {
                $this->record(
                    $run,
                    $actor,
                    'provisioning_started',
                    'Provisioning started. Bare metal orders are excluded from the 48-hour turnaround.',
                    [
                        ['label' => 'Countdown', 'from' => '48 hours', 'to' => 'Not required'],
                    ]
                );
            }

            return $run;
        });
    }

    public function show(SalesTransaction $transaction, User $actor): array
    {
        $transaction->loadMissing(['items', 'customer']);
        $run = $this->runFor($transaction);
        if ($run) {
            $this->sync($run);
            $run->load([
                'actions.assignee:id,fname,lname,email',
                'actions.author:id,fname,lname,email',
                'events.actor:id,fname,lname,email',
                'approver:id,fname,lname,email',
                'webdevStarter:id,fname,lname,email',
            ]);
        }

        return $this->detailPayload($transaction, $run, $actor);
    }

    public function customerView(SalesTransaction $transaction): ?array
    {
        if (! Schema::hasTable('provisioning_runs')) {
            return null;
        }

        $run = $transaction->relationLoaded('provisioningRun')
            ? $transaction->provisioningRun
            : $this->runFor($transaction);

        if (! $run || $run->status === 'cancelled') {
            return null;
        }

        $this->sync($run);
        $transaction->unsetRelation('provisioningRun');
        $transaction->setRelation('provisioningRun', $run->fresh([
            'actions.assignee:id,fname,lname,email',
            'webdevStarter:id,fname,lname,email',
        ]) ?? $run);
        $run = $transaction->provisioningRun;
        $run->loadMissing(['actions.assignee:id,fname,lname,email', 'webdevStarter:id,fname,lname,email']);
        $this->notifyIfTechnicalWorkFinished($run);

        $countdown = $this->countdownPayload($run, false);
        $webdev = $run->timeline === 'webdev' ? $this->countdownPayload($run, true) : null;

        return [
            'status' => $this->orderStatusLabel($run),
            'timeline' => $run->timeline,
            'countdown' => $run->timeline === 'webdev' ? $webdev : $countdown,
            'webdevCountdown' => $run->timeline === 'webdev' ? $webdev : null,
            'tasks' => $run->actions->map(fn (ProvisioningAction $action) => [
                'id' => $action->id,
                'serviceName' => $action->service_name,
                'status' => $this->actionStatusLabel($action->status),
                'doneAt' => optional($action->done_at)?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function listSummary(?ProvisioningRun $run): ?array
    {
        if (! $run || $run->status === 'cancelled') {
            return null;
        }

        $primary = $run->timeline === 'webdev'
            ? $this->countdownPayload($run, true)
            : $this->countdownPayload($run, false);

        return [
            'status' => $this->orderStatusLabel($run),
            'timeline' => $run->timeline,
            'countdown' => $primary,
            'webdevCountdown' => $run->timeline === 'webdev' ? $primary : null,
            'progress' => [
                'settled' => (int) ($run->settled_actions_count ?? 0),
                'total' => (int) ($run->actions_count ?? 0),
            ],
        ];
    }

    /**
     * @return list<ProvisioningAction>
     */
    public function addActions(SalesTransaction $transaction, User $actor, array $input): array
    {
        $names = collect($input['service_names'] ?? [])
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->values();
        if ($names->isEmpty()) {
            $single = trim((string) ($input['service_name'] ?? ''));
            if ($single !== '') {
                $names = collect([$single]);
            }
        }
        if ($names->isEmpty()) {
            throw ValidationException::withMessages([
                'service_names' => 'Choose at least one service on this order.',
            ]);
        }

        $transaction->loadMissing('items');
        $known = $transaction->items->pluck('name')->map(fn ($name) => trim((string) $name))->filter()->values();
        if ($known->isNotEmpty() && $names->contains(fn ($name) => ! $known->contains($name))) {
            throw ValidationException::withMessages([
                'service_names' => 'Choose a service on this order.',
            ]);
        }

        $actions = [];
        foreach ($names as $name) {
            $actions[] = $this->addAction($transaction, $actor, array_merge($input, [
                'service_name' => $name,
            ]));
        }

        return $actions;
    }

    public function addAction(SalesTransaction $transaction, User $actor, array $input): ProvisioningAction
    {
        $this->assertCanManageActions($actor);
        $run = $this->openRunRequired($transaction);
        abort_unless($run->status === 'provisioning', 422, 'Provisioning is already finished for this order.');

        $serviceName = trim((string) ($input['service_name'] ?? ''));
        $transaction->loadMissing('items');
        $known = $transaction->items->pluck('name')->map(fn ($name) => trim((string) $name))->filter()->values();
        if ($serviceName === '' || ($known->isNotEmpty() && ! $known->contains($serviceName))) {
            throw ValidationException::withMessages([
                'service_name' => 'Choose a service on this order.',
            ]);
        }

        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => 'Describe the provisioning action.',
            ]);
        }

        $checkpoint = (int) ($input['checkpoint_hours'] ?? ProvisioningRules::checkpointFor($run->timeline));
        if (! in_array($checkpoint, [ProvisioningRules::CHECKPOINT_STANDARD, ProvisioningRules::CHECKPOINT_WEBDEV], true)) {
            throw ValidationException::withMessages([
                'checkpoint_hours' => 'Checkpoint must be 12 or 24 hours.',
            ]);
        }

        $assignee = $this->resolveAssignee($input['assigned_to'] ?? null);
        $serviceId = CustomerService::query()
            ->where('sales_transaction_id', $transaction->id)
            ->where('title', $serviceName)
            ->value('id');

        $action = DB::transaction(function () use ($run, $transaction, $actor, $serviceName, $description, $checkpoint, $assignee, $serviceId) {
            $action = ProvisioningAction::create([
                'provisioning_run_id' => $run->id,
                'sales_transaction_id' => $transaction->id,
                'customer_service_id' => $serviceId,
                'service_name' => $serviceName,
                'service_kind' => $run->timeline === 'webdev' ? 'webdev' : 'standard',
                'description' => $description,
                'assigned_to' => $assignee?->id,
                'created_by' => $actor->id,
                'status' => 'pending',
                'checkpoint_hours' => $checkpoint,
                'due_at' => $this->actionDueAt($run),
            ]);

            $assigneeName = $assignee ? $this->userName($assignee) : 'Unassigned';
            $this->record($run, $actor, 'action_created', "Action added for {$serviceName}.", [
                ['label' => 'Action', 'from' => '', 'to' => $description],
                ['label' => 'Assigned to', 'from' => '', 'to' => $assigneeName],
                ['label' => 'Checkpoint', 'from' => '', 'to' => "{$checkpoint} hours"],
                ['label' => 'Status', 'from' => '', 'to' => 'Pending'],
            ]);

            return $action;
        });

        $action = $action->fresh(['assignee:id,fname,lname,email', 'author:id,fname,lname,email', 'run']);
        $this->notifyActionAssigned($transaction, $action, $actor, $assignee);

        return $action;
    }

    public function markDone(ProvisioningAction $action, User $actor): ProvisioningAction
    {
        $this->assertCanManageActions($actor);
        $run = $action->run()->firstOrFail();
        abort_unless($run->status === 'provisioning', 422, 'Provisioning is already finished for this order.');
        abort_unless($action->status === 'pending', 422, 'This action is already marked done.');

        $doneAt = now();
        DB::transaction(function () use ($action, $actor, $run, $doneAt) {
            $action->done_at = $doneAt;
            $action->status = 'done';
            $action->save();

            $this->record($run, $actor, 'action_done', "Marked done: {$action->description}.", [
                ['label' => 'Service', 'from' => '', 'to' => $action->service_name],
                ['label' => 'Status', 'from' => 'Pending', 'to' => 'Done'],
                ['label' => 'Completed at', 'from' => '', 'to' => $doneAt->toIso8601String()],
            ]);

            $this->applySync($run, $action->fresh(), $actor);
            $this->maybeCompleteRun($run->fresh('actions'), $actor);
            $this->clearMarkDoneReminder($action);
        });

        return $action->fresh(['assignee:id,fname,lname,email', 'author:id,fname,lname,email']);
    }

    public function startWebDevCountdown(SalesTransaction $transaction, User $actor, int $days): ProvisioningRun
    {
        $this->assertCanStartWebDev($actor);
        if ($days < ProvisioningRules::WEBDEV_MIN_DAYS || $days > ProvisioningRules::WEBDEV_MAX_DAYS) {
            throw ValidationException::withMessages([
                'days' => 'WebDev countdown must be between 30 and 90 days.',
            ]);
        }

        $run = $this->openRunRequired($transaction);
        abort_unless($run->timeline === 'webdev', 422, 'This order uses the standard provisioning countdown.');
        abort_if($run->webdev_started_at !== null, 422, 'The WebDev countdown has already started.');

        $started = now();
        DB::transaction(function () use ($run, $actor, $days, $started) {
            $run->webdev_days = $days;
            $run->webdev_started_at = $started;
            $run->webdev_started_by = $actor->id;
            $run->save();

            $dueAt = $started->copy()->addDays($days);
            ProvisioningAction::query()
                ->where('provisioning_run_id', $run->id)
                ->whereNull('due_at')
                ->update(['due_at' => $dueAt]);

            $this->record($run, $actor, 'countdown_started', "{$this->userName($actor)} started a {$days}-day WebDev countdown.", [
                ['label' => 'WebDev countdown', 'from' => 'Not started', 'to' => "{$days} days"],
                ['label' => 'Started by', 'from' => '', 'to' => $this->userName($actor)],
            ]);

            $this->sync($run->fresh(['actions']));
        });

        $fresh = $run->fresh(['actions.assignee', 'salesTransaction.customer']);
        if ($fresh) {
            $this->notifyPendingAssigneesOfCountdown($fresh, true);
        }

        return $fresh ?? $run->fresh();
    }

    public function sync(ProvisioningRun $run, ?User $actor = null): void
    {
        $run->loadMissing('actions');
        foreach ($run->actions as $action) {
            $this->applySync($run, $action, $actor);
        }
        $run->load('actions');
        $this->maybeCompleteRun($run, $actor);
        $this->notifyIfTechnicalWorkFinished($run);
        $this->notifyPendingAssigneesOfCountdown($run, false);
    }

    public function syncOpenForCustomer(int $customerId): void
    {
        $runs = ProvisioningRun::query()
            ->where('status', 'provisioning')
            ->whereHas('salesTransaction', fn ($query) => $query->where('customer_id', $customerId))
            ->with('actions')
            ->get();

        foreach ($runs as $run) {
            $this->sync($run);
        }
    }

    private function applySync(ProvisioningRun $run, ProvisioningAction $action, ?User $actor): void
    {
        $next = ProvisioningRules::syncAction(
            (string) $action->status,
            $action->done_at,
            $action->completed_at,
            $action->due_at,
            (int) $action->checkpoint_hours,
            now()
        );

        if ($next['status'] === $action->status && $next['events'] === []) {
            return;
        }

        $previous = $this->actionStatusLabel($action->status);
        $action->status = $next['status'];
        $action->completed_at = $next['completedAt'];
        if ($next['validatedAt']) {
            $action->validated_at = $next['validatedAt'];
        }
        $action->save();

        if (in_array('completed', $next['events'], true)) {
            $this->record($run, $actor, 'action_completed', "Action completed within its countdown: {$action->description}.", [
                ['label' => 'Service', 'from' => '', 'to' => $action->service_name],
                ['label' => 'Action status', 'from' => $previous, 'to' => 'Completed'],
            ]);
        }

        if (in_array('active', $next['events'], true)) {
            $hours = (int) $action->checkpoint_hours;
            $this->record(
                $run,
                $actor,
                'action_validated',
                "Action passed the {$hours}-hour checkpoint and is Active: {$action->description}.",
                [
                    ['label' => 'Service', 'from' => '', 'to' => $action->service_name],
                    ['label' => 'Action status', 'from' => 'Completed', 'to' => 'Active'],
                    ['label' => 'Checkpoint', 'from' => '', 'to' => "{$hours} hours"],
                ]
            );
        }
    }

    private function maybeCompleteRun(ProvisioningRun $run, ?User $actor): void
    {
        if ($run->status !== 'provisioning') {
            return;
        }

        $actions = $run->relationLoaded('actions') ? $run->actions : $run->actions()->get();
        if ($actions->isEmpty() || $actions->contains(
            fn (ProvisioningAction $action) => ! ProvisioningRules::isSettledStatus($action->status)
        )) {
            return;
        }

        if (! $this->windowElapsed($run)) {
            return;
        }

        $run->status = 'completed';
        $run->save();
        $this->record($run, $actor, 'provisioning_status_changed', 'Order provisioning finished after the countdown and every action was marked done.', [
            ['label' => 'Provisioning status', 'from' => 'Provisioning', 'to' => 'Active'],
            ['label' => 'Order status', 'from' => 'Provisioning', 'to' => 'Active'],
        ]);

        $transaction = $run->relationLoaded('salesTransaction')
            ? $run->salesTransaction
            : $run->salesTransaction()->first();
        if (! $transaction) {
            return;
        }

        $current = strtolower((string) $transaction->order_status);
        if (! in_array($current, ['cancelled', 'canceled'], true)) {
            $transaction->order_status = 'active';
            $transaction->save();
        }

        app(CustomerPortalProvisioner::class)->refreshServicesFromTransaction($transaction->fresh('items'));
        app(CustomerPortalNotificationSync::class)->notifyTechnicalProvisioningCompleted($transaction->fresh(['items', 'provisioningRun.actions']));
    }

    private function notifyIfTechnicalWorkFinished(ProvisioningRun $run): void
    {
        $actions = $run->relationLoaded('actions') ? $run->actions : $run->actions()->get();
        if ($actions->isEmpty() || $actions->contains(
            fn (ProvisioningAction $action) => ! ProvisioningRules::isSettledStatus($action->status)
        )) {
            return;
        }

        $transaction = $run->relationLoaded('salesTransaction')
            ? $run->salesTransaction
            : $run->salesTransaction()->first();
        if ($transaction) {
            app(CustomerPortalNotificationSync::class)->notifyTechnicalProvisioningCompleted($transaction);
        }
    }

    private function windowElapsed(ProvisioningRun $run): bool
    {
        if ($run->timeline === 'webdev') {
            if (! $run->webdev_started_at || ! $run->webdev_days) {
                return false;
            }

            return now()->greaterThanOrEqualTo($run->webdev_started_at->copy()->addDays((int) $run->webdev_days));
        }

        if (! $run->countdown_started_at) {
            return false;
        }

        $end = ProvisioningRules::countdownEnd(
            $run->countdown_started_at,
            $run->duration_hours,
            $run->duration_minutes
        );

        return $end !== null && now()->greaterThanOrEqualTo($end);
    }

    private function actionDueAt(ProvisioningRun $run): ?Carbon
    {
        if ($run->timeline === 'webdev') {
            if (! $run->webdev_started_at || ! $run->webdev_days) {
                return null;
            }

            return $run->webdev_started_at->copy()->addDays((int) $run->webdev_days);
        }

        if (! $run->countdown_started_at) {
            return null;
        }

        return ProvisioningRules::countdownEnd(
            $run->countdown_started_at,
            $run->duration_hours,
            $run->duration_minutes
        );
    }

    private function countdownPayload(ProvisioningRun $run, bool $webdev): array
    {
        if ($webdev) {
            if (! $run->webdev_started_at || ! $run->webdev_days) {
                return [
                    'started' => false,
                    'startedAt' => null,
                    'endsAt' => null,
                    'durationHours' => null,
                    'durationDays' => null,
                    'startedBy' => null,
                    'label' => 'Waiting for Sales/Production to start a 30–90 day countdown',
                ];
            }

            $days = (int) $run->webdev_days;
            $starter = $run->relationLoaded('webdevStarter') ? $run->webdevStarter : $run->webdevStarter()->first();
            $name = $this->userName($starter);

            return [
                'started' => true,
                'startedAt' => $run->webdev_started_at->toIso8601String(),
                'endsAt' => $run->webdev_started_at->copy()->addDays($days)->toIso8601String(),
                'durationHours' => $days * 24,
                'durationDays' => $days,
                'startedBy' => $name,
                'label' => $name
                    ? "{$days}-day WebDev countdown started by {$name}"
                    : "{$days}-day WebDev countdown",
            ];
        }

        $minutes = (int) $run->duration_minutes;
        $hours = (int) $run->duration_hours;
        if ($minutes <= 0 && $hours <= 0) {
            return [
                'started' => false,
                'startedAt' => null,
                'endsAt' => null,
                'durationHours' => null,
                'durationMinutes' => null,
                'durationDays' => null,
                'startedBy' => null,
                'label' => 'Bare metal orders are excluded from the 48-hour turnaround',
            ];
        }

        $usesMinutes = $minutes > 0 && $hours <= 0;
        $windowLabel = $usesMinutes ? "{$minutes}-minute" : "{$hours}-hour";
        if (! $run->countdown_started_at) {
            return [
                'started' => false,
                'startedAt' => null,
                'endsAt' => null,
                'durationHours' => $usesMinutes ? null : $hours,
                'durationMinutes' => $usesMinutes ? $minutes : null,
                'durationDays' => null,
                'startedBy' => null,
                'label' => "{$windowLabel} provisioning window has not started",
            ];
        }

        $endsAt = ProvisioningRules::countdownEnd($run->countdown_started_at, $hours, $minutes);

        return [
            'started' => true,
            'startedAt' => $run->countdown_started_at->toIso8601String(),
            'endsAt' => $endsAt?->toIso8601String(),
            'durationHours' => $usesMinutes ? null : $hours,
            'durationMinutes' => $usesMinutes ? $minutes : null,
            'durationDays' => null,
            'startedBy' => null,
            'label' => "{$windowLabel} provisioning window",
        ];
    }

    private function detailPayload(SalesTransaction $transaction, ?ProvisioningRun $run, User $actor): array
    {
        $timeline = $run?->timeline
            ?? ProvisioningRules::timelineFor($transaction->items, WebDesignQuotation::isWebDesign($transaction))['timeline'];
        $services = $transaction->items
            ->map(fn ($item) => trim((string) $item->name))
            ->filter()
            ->unique()
            ->values()
            ->map(function (string $name) use ($run, $timeline) {
                $actions = $run
                    ? $run->actions->where('service_name', $name)->values()
                    : collect();

                return [
                    'name' => $name,
                    'kind' => $timeline === 'webdev' ? 'webdev' : 'standard',
                    'checkpointHours' => ProvisioningRules::checkpointFor($timeline),
                    'actions' => $actions->map(fn (ProvisioningAction $action) => $this->actionPayload($action))->values()->all(),
                ];
            })
            ->values()
            ->all();

        $countdown = $run ? $this->countdownPayload($run, false) : null;
        $webdev = $run && $run->timeline === 'webdev' ? $this->countdownPayload($run, true) : null;
        $customer = $transaction->customer;
        $firstItem = $transaction->items->first();
        $clientName = trim((string) ($customer?->full_name ?: $transaction->customer_name));

        return [
            'salesTransactionId' => $transaction->id,
            'transactionNo' => $transaction->transaction_no,
            'orderStatus' => $run?->status ?? 'not_started',
            'displayStatus' => $run ? $this->orderStatusLabel($run) : 'Pending Review',
            'timeline' => $timeline,
            'countdown' => $timeline === 'webdev' ? $webdev : $countdown,
            'webdevCountdown' => $webdev,
            'order' => [
                'transactionNo' => $transaction->transaction_no,
                'invoiceId' => 'INV-' . $transaction->transaction_no,
                'client' => $clientName !== '' ? $clientName : 'Customer',
                'email' => $customer?->email ?: $transaction->customer_email,
                'plan' => TransactionLabelResolver::planLabel($transaction->items, $firstItem?->name),
                'amount' => WebDesignQuotation::displayAmount($transaction),
                'paymentStatus' => ucfirst((string) ($transaction->payment_status ?: 'unpaid')),
                'issuedDate' => TransactionLabelResolver::issuedDateFrom($transaction->transacted_at),
                'dueDate' => TransactionLabelResolver::dueDateFrom($transaction->transacted_at),
                'approvedAt' => optional($run?->approved_at)?->toIso8601String(),
            ],
            'canManageActions' => $run && $run->status === 'provisioning' && $this->canManageActions($actor),
            'canStartWebdev' => $run
                && $run->status === 'provisioning'
                && $run->timeline === 'webdev'
                && $run->webdev_started_at === null
                && $this->canStartWebDev($actor),
            'services' => $services,
            'events' => $run
                ? $run->events->sortBy('created_at')->values()->map(fn (ProvisioningEvent $event) => [
                    'id' => $event->id,
                    'event' => $event->event,
                    'summary' => $event->summary,
                    'actor' => $this->userName($event->actor),
                    'createdAt' => optional($event->created_at)?->toIso8601String(),
                    'changes' => $event->changes ?? [],
                ])->all()
                : [],
        ];
    }

    private function actionPayload(ProvisioningAction $action): array
    {
        return [
            'id' => $action->id,
            'serviceName' => $action->service_name,
            'description' => $action->description,
            'status' => $this->actionStatusLabel($action->status),
            'statusKey' => $action->status,
            'checkpointHours' => (int) $action->checkpoint_hours,
            'assignee' => $this->userName($action->assignee),
            'createdBy' => $this->userName($action->author),
            'dueAt' => optional($action->due_at)?->toIso8601String(),
            'doneAt' => optional($action->done_at)?->toIso8601String(),
            'completedAt' => optional($action->completed_at)?->toIso8601String(),
            'validatedAt' => optional($action->validated_at)?->toIso8601String(),
        ];
    }

    private function record(ProvisioningRun $run, ?User $actor, string $event, string $summary, array $changes = []): void
    {
        ProvisioningEvent::create([
            'provisioning_run_id' => $run->id,
            'sales_transaction_id' => $run->sales_transaction_id,
            'user_id' => $actor?->id,
            'event' => $event,
            'summary' => $summary,
            'changes' => $changes,
        ]);
    }

    private function runFor(SalesTransaction $transaction): ?ProvisioningRun
    {
        return ProvisioningRun::query()
            ->where('sales_transaction_id', $transaction->id)
            ->first();
    }

    private function openRunRequired(SalesTransaction $transaction): ProvisioningRun
    {
        $run = $this->runFor($transaction);
        abort_unless($run, 422, 'Approve the order before provisioning actions.');

        return $run;
    }

    private function notifyActionAssigned(SalesTransaction $transaction, ProvisioningAction $action, User $actor, ?User $assignee): void
    {
        $transaction->loadMissing('customer:id,fname,lname,mname,email');
        $run = $action->relationLoaded('run') ? $action->run : $action->run()->first();
        $client = $this->clientLabel($transaction);
        $orderNo = (string) ($transaction->transaction_no ?: ('Order ' . $transaction->id));
        $actorName = $this->userName($actor) ?: 'Staff';
        $assigneeName = $assignee ? ($this->userName($assignee) ?: 'Technical Support') : null;
        $countdown = $run ? $this->countdownMarkDoneNotice($run, $action) : 'Mark this done before the provisioning countdown ends.';
        $referenceKey = 'admin:provisioning-action:' . $action->id;
        $actionUrl = '/public/commerce-admin?tab=approvals';

        $recipientIds = User::role('technical_support')
            ->where('is_active', true)
            ->pluck('id');
        if ($assignee) {
            $recipientIds->push($assignee->id);
        }

        $recipientIds
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->each(function (int $staffId) use ($action, $assignee, $assigneeName, $actorName, $client, $orderNo, $countdown, $referenceKey, $actionUrl) {
                $forAssignee = $assignee && $staffId === (int) $assignee->id;
                $title = $forAssignee
                    ? 'Mark provisioning done before the countdown ends'
                    : ($assigneeName
                        ? 'Provisioning action assigned'
                        : 'Provisioning action needs Technical Support');
                $body = $forAssignee
                    ? "{$actorName} assigned you \"{$action->description}\" for {$action->service_name} on {$orderNo} ({$client}). {$countdown}"
                    : ($assigneeName
                        ? "{$actorName} assigned {$assigneeName} to \"{$action->description}\" for {$action->service_name} on {$orderNo} ({$client}). {$countdown}"
                        : "{$actorName} added \"{$action->description}\" for {$action->service_name} on {$orderNo} ({$client}). Assign Technical Support and mark it done. {$countdown}");

                CustomerNotification::query()->updateOrCreate(
                    [
                        'customer_id' => $staffId,
                        'reference_key' => $referenceKey,
                    ],
                    [
                        'title' => $title,
                        'body' => $body,
                        'type' => 'provisioning_action',
                        'action_url' => $actionUrl,
                        'read_at' => null,
                    ]
                );
            });

        if ($assignee && $run) {
            $this->notifyMarkDoneReminder($transaction, $action, $run, true);
        }
    }

    private function notifyPendingAssigneesOfCountdown(ProvisioningRun $run, bool $forceUnread): void
    {
        if ($run->status !== 'provisioning') {
            return;
        }

        $run->loadMissing(['actions.assignee', 'salesTransaction.customer']);
        $transaction = $run->relationLoaded('salesTransaction')
            ? $run->salesTransaction
            : $run->salesTransaction()->first();
        if (! $transaction) {
            return;
        }

        foreach ($run->actions as $action) {
            if ($action->status !== 'pending' || ! $action->assigned_to) {
                continue;
            }

            $this->notifyMarkDoneReminder($transaction, $action, $run, $forceUnread);
        }
    }

    private function notifyMarkDoneReminder(
        SalesTransaction $transaction,
        ProvisioningAction $action,
        ProvisioningRun $run,
        bool $forceUnread,
    ): void {
        $assigneeId = (int) $action->assigned_to;
        if ($assigneeId < 1) {
            return;
        }

        $dueAt = $action->due_at ?? $this->actionDueAt($run);
        $countdown = $this->countdownMarkDoneNotice($run, $action);
        $remainingHours = $dueAt ? now()->diffInHours($dueAt, false) : null;
        $urgentWindow = $run->timeline === 'webdev' ? 24 : 6;
        $urgent = $remainingHours !== null && $remainingHours >= 0 && $remainingHours <= $urgentWindow;
        $ended = $dueAt !== null && now()->greaterThanOrEqualTo($dueAt);
        $referenceKey = 'admin:provisioning-countdown:' . $action->id;
        $existing = CustomerNotification::query()
            ->where('customer_id', $assigneeId)
            ->where('reference_key', $referenceKey)
            ->first();

        $shouldUnread = $forceUnread
            || ! $existing
            || (($ended || $urgent) && ($existing->updated_at === null || $existing->updated_at->lt(now()->subHours(3))));

        $client = $this->clientLabel($transaction);
        $orderNo = (string) ($transaction->transaction_no ?: ('Order ' . $transaction->id));
        $title = $ended
            ? 'Countdown ended — mark provisioning done'
            : ($urgent
                ? 'Countdown ending — mark provisioning done'
                : 'Mark provisioning done before the countdown ends');

        CustomerNotification::query()->updateOrCreate(
            [
                'customer_id' => $assigneeId,
                'reference_key' => $referenceKey,
            ],
            [
                'title' => $title,
                'body' => "Finish \"{$action->description}\" for {$action->service_name} on {$orderNo} ({$client}). {$countdown}",
                'type' => 'provisioning_action',
                'action_url' => '/public/commerce-admin?tab=approvals',
                'read_at' => $shouldUnread ? null : $existing?->read_at,
            ]
        );
    }

    private function clearMarkDoneReminder(ProvisioningAction $action): void
    {
        CustomerNotification::query()
            ->where('reference_key', 'admin:provisioning-countdown:' . $action->id)
            ->delete();
    }

    private function countdownMarkDoneNotice(ProvisioningRun $run, ?ProvisioningAction $action = null): string
    {
        $dueAt = $action?->due_at ?? $this->actionDueAt($run);
        if ($dueAt === null) {
            return $run->timeline === 'webdev'
                ? 'A WebDev countdown has not started yet. Mark this done after Sales/Production starts it.'
                : 'Mark this done before the provisioning countdown ends.';
        }

        $dueLabel = $dueAt->timezone(config('app.timezone', 'Asia/Manila'))->format('M j, Y g:i A');
        if (now()->greaterThanOrEqualTo($dueAt)) {
            return "The countdown has ended (due {$dueLabel}). Mark this done now so the order can become Active.";
        }

        return "Mark this done before the countdown ends. {$this->remainingLabel($dueAt)} left (due {$dueLabel}).";
    }

    private function remainingLabel(Carbon $dueAt): string
    {
        $now = now();
        $hours = (int) $now->diffInHours($dueAt, false);
        if ($hours >= 48) {
            $days = (int) floor($hours / 24);
            $left = $hours % 24;

            return $left > 0 ? "{$days} days {$left} hours" : "{$days} days";
        }
        if ($hours >= 1) {
            $minutes = (int) $now->copy()->addHours($hours)->diffInMinutes($dueAt, false);

            return $minutes > 0 ? "{$hours} hours {$minutes} minutes" : "{$hours} hours";
        }

        $minutes = max(1, (int) $now->diffInMinutes($dueAt, false));

        return "{$minutes} minutes";
    }

    private function clientLabel(SalesTransaction $transaction): string
    {
        $client = trim((string) ($transaction->customer_name ?: ''));
        if ($client === '' && $transaction->customer) {
            $client = trim(($transaction->customer->fname ?? '') . ' ' . ($transaction->customer->lname ?? ''));
        }
        if ($client === '') {
            $client = (string) ($transaction->customer_email ?: $transaction->customer?->email ?: 'Client');
        }

        return $client;
    }

    private function resolveAssignee(mixed $userId): ?User
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        $user = User::query()->find((int) $userId);
        if (! $user || $user->hasRole('customer')) {
            throw ValidationException::withMessages([
                'assigned_to' => 'Assign a staff member.',
            ]);
        }

        return $user;
    }

    private function canManageActions(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'technical_support']);
    }

    private function canStartWebDev(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'sales_admin', 'sales_staff']);
    }

    private function assertCanManageActions(User $user): void
    {
        abort_unless($this->canManageActions($user), 403, 'Only Admin and Technical Support can update provisioning actions.');
    }

    private function assertCanStartWebDev(User $user): void
    {
        abort_unless($this->canStartWebDev($user), 403, 'Only Sales or Production can start the WebDev countdown.');
    }

    private function orderStatusLabel(ProvisioningRun $run): string
    {
        return $run->status === 'completed' ? 'Active' : 'Provisioning';
    }

    private function actionStatusLabel(string $status): string
    {
        return match (strtolower($status)) {
            'done' => 'Done',
            'completed' => 'Completed',
            'active' => 'Active',
            default => 'Pending',
        };
    }

    private function userName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $name = trim(($user->fname ?? '') . ' ' . ($user->lname ?? ''));

        return $name !== '' ? $name : ($user->email ?: null);
    }
}
