<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProvisioningAction;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\ProvisioningWorkflow;
use Illuminate\Http\Request;

class ProvisioningController extends Controller
{
    public function __construct(private ProvisioningWorkflow $workflow)
    {
    }

    public function show(Request $request, SalesTransaction $salesTransaction)
    {
        $staff = $this->resolveStaff($request);

        return response()->json([
            'data' => $this->workflow->show($salesTransaction, $staff),
        ]);
    }

    public function storeAction(Request $request, SalesTransaction $salesTransaction)
    {
        $staff = $this->resolveStaff($request);
        $payload = $request->validate([
            'service_name' => ['required_without:service_names', 'string', 'max:255'],
            'service_names' => ['required_without:service_name', 'array', 'min:1'],
            'service_names.*' => ['string', 'max:255'],
            'description' => ['required', 'string', 'max:500'],
            'assigned_to' => ['nullable', 'integer'],
            'checkpoint_hours' => ['nullable', 'integer', 'in:12,24'],
        ]);

        $actions = $this->workflow->addActions($salesTransaction, $staff, $payload);

        return response()->json([
            'message' => count($actions) === 1
                ? 'Provisioning action added.'
                : 'Provisioning actions added.',
            'data' => $this->workflow->show($salesTransaction, $staff),
            'actionId' => $actions[0]->id ?? null,
        ]);
    }

    public function markDone(Request $request, ProvisioningAction $provisioningAction)
    {
        $staff = $this->resolveStaff($request);
        $this->workflow->markDone($provisioningAction, $staff);
        $transaction = SalesTransaction::query()->findOrFail($provisioningAction->sales_transaction_id);

        return response()->json([
            'message' => 'Provisioning action marked done.',
            'data' => $this->workflow->show($transaction, $staff),
        ]);
    }

    public function startWebDev(Request $request, SalesTransaction $salesTransaction)
    {
        $staff = $this->resolveStaff($request);
        $days = (int) $request->validate([
            'days' => ['required', 'integer', 'min:30', 'max:90'],
        ])['days'];

        $this->workflow->startWebDevCountdown($salesTransaction, $staff, $days);

        return response()->json([
            'message' => "WebDev countdown started for {$days} days.",
            'data' => $this->workflow->show($salesTransaction, $staff),
        ]);
    }

    private function resolveStaff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_if($user->hasRole('customer'), 403, 'Customer accounts cannot access provisioning.');

        return $user;
    }
}
