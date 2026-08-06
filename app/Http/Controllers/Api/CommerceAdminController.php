<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Models\CustomerPaymentProof;
use App\Models\CustomerProfileChangeRequest;
use App\Models\CustomerService;
use App\Models\CustomerSupportTicket;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Support\TransactionLabelResolver;
use App\Support\StorageUrl;
use App\Services\CustomerPortalNotificationSync;
use App\Services\CustomerPortalProvisioner;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CommerceAdminController extends Controller
{
    private function resolveStaff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_if($user->hasRole('customer'), 403, 'Customer accounts cannot access commerce admin APIs.');

        return $user;
    }

    public function assignableUsers(Request $request)
    {
        $this->resolveStaff($request);

        $users = User::query()
            ->with('roles')
            ->where('is_active', true)
            ->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'customer');
            })
            ->orderBy('fname')
            ->orderBy('lname')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => trim(($user->fname ?? '') . ' ' . ($user->lname ?? '')) ?: ($user->email ?? 'User'),
                'email' => $user->email,
                'role' => $user->getRoleNames()->first(),
            ])
            ->values();

        return response()->json(['data' => $users]);
    }

    public function assignSalesTransaction(Request $request, SalesTransaction $salesTransaction)
    {
        $staff = $this->resolveStaff($request);
        abort_unless(
            $staff->hasAnyRole(['customer_care', 'admin']),
            403,
            'Only Customer Care can assign transactions.'
        );

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $assignee = User::query()->with('roles')->findOrFail($validated['user_id']);
        abort_unless((bool) $assignee->is_active, 422, 'Selected user is not active.');
        abort_if($assignee->hasRole('customer'), 422, 'Customer accounts cannot be assigned to transactions.');

        $salesTransaction->update(['user_id' => $assignee->id]);

        $fresh = $salesTransaction->fresh()->load([
            'customer:id,fname,lname,email',
            'user:id,fname,lname,email',
            'items',
        ]);

        return response()->json([
            'message' => 'Transaction assigned successfully.',
            'data' => $fresh,
        ]);
    }

    public function dashboard(Request $request)
    {
        $this->resolveStaff($request);

        $newOrders = SalesTransaction::query()
            ->with(['customer:id,fname,lname,email', 'items'])
            ->whereIn('order_status', ['new', 'pending', 'processing'])
            ->latest('transacted_at')
            ->limit(8)
            ->get()
            ->map(fn (SalesTransaction $row) => $this->mapQueueOrder($row));

        $expiringServices = CustomerService::query()
            ->with('customer:id,fname,lname,email')
            ->whereNotNull('renew_at')
            ->where('renew_at', '<=', now()->addDays(30))
            ->where('status', '!=', 'Expired')
            ->orderBy('renew_at')
            ->limit(8)
            ->get()
            ->map(fn (CustomerService $row) => $this->mapExpiringService($row));

        $overdueInvoices = SalesTransaction::query()
            ->with(['customer:id,fname,lname,email', 'items'])
            ->where('payment_status', '!=', 'paid')
            ->whereDate('transacted_at', '<=', now()->subDays(14))
            ->latest('transacted_at')
            ->limit(8)
            ->get()
            ->map(fn (SalesTransaction $row) => $this->mapOverdueInvoice($row));

        $pendingProofs = $this->pendingPaymentProofCount();
        $pendingProfileChanges = CustomerProfileChangeRequest::query()
            ->where('status', 'Pending Review')
            ->count();
        $pendingQuotations = $this->pendingWebDesignQuotationsQuery()->count();
        $openTickets = CustomerSupportTicket::query()->whereIn('status', ['Open', 'In Progress'])->count();
        $activeClients = User::role('customer')->where('is_active', true)->count();
        $activeServices = CustomerService::query()->where('status', 'Active')->count();

        return response()->json([
            'data' => [
                'counts' => [
                    'pendingApprovals' => $pendingProofs + $pendingProfileChanges,
                    'pendingQuotations' => $pendingQuotations,
                    'openTickets' => $openTickets,
                    'activeClients' => $activeClients,
                    'activeServices' => $activeServices,
                ],
                'newOrders' => $newOrders,
                'expiringServices' => $expiringServices,
                'overdueInvoices' => $overdueInvoices,
            ],
        ]);
    }

    public function approvals(Request $request)
    {
        $this->resolveStaff($request);

        $status = $request->input('status', 'Pending Review');
        $this->collapseDuplicatePendingPaymentProofs();

        $proofRows = CustomerPaymentProof::query()
            ->with(['customer:id,fname,lname,email', 'salesTransaction.items'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()
            ->get()
            ->unique(function (CustomerPaymentProof $proof) {
                if ($proof->status !== 'Pending Review') {
                    return 'id:' . $proof->id;
                }

                return 'pending:' . ($proof->sales_transaction_id ?: $proof->invoice_id);
            })
            ->values()
            ->map(fn (CustomerPaymentProof $proof) => array_merge(
                $this->mapAdminPaymentProof($proof),
                ['kind' => 'payment_proof']
            ));

        $profileRows = CustomerProfileChangeRequest::query()
            ->with('customer:id,fname,lname,email,mname')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()
            ->get()
            ->map(fn (CustomerProfileChangeRequest $row) => $this->mapAdminProfileChangeRequest($row));

        $merged = $proofRows
            ->concat($profileRows)
            ->sortByDesc(fn (array $row) => $row['submittedAt'] ?? '')
            ->values();

        return response()->json([
            'data' => $merged,
        ]);
    }

    public function paymentProofs(Request $request)
    {
        $this->resolveStaff($request);

        $status = $request->input('status', 'Pending Review');

        $rows = CustomerPaymentProof::query()
            ->with(['customer:id,fname,lname,email', 'salesTransaction.items'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $rows->through(fn (CustomerPaymentProof $proof) => array_merge(
                $this->mapAdminPaymentProof($proof),
                ['kind' => 'payment_proof']
            )),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function verifyPaymentProof(Request $request, CustomerPaymentProof $paymentProof)
    {
        $this->resolveStaff($request);
        abort_unless($paymentProof->status === 'Pending Review', 422, 'Only pending proofs can be verified.');

        $paymentProof->update(['status' => 'Verified & Credited']);

        if ($paymentProof->salesTransaction) {
            $paymentProof->salesTransaction->update([
                'payment_status' => 'paid',
                'order_status' => $paymentProof->salesTransaction->order_status === 'cancelled'
                    ? 'cancelled'
                    : 'active',
                'notes' => trim(($paymentProof->salesTransaction->notes ?? '') . "\nPayment verified via proof {$paymentProof->proof_no}."),
            ]);

            if ($paymentProof->customer_id) {
                app(CustomerPortalProvisioner::class)->refreshServicesFromTransaction(
                    $paymentProof->salesTransaction->fresh(['items'])
                );
            }
        }

        if ($paymentProof->customer_id) {
            CustomerNotification::create([
                'customer_id' => $paymentProof->customer_id,
                'title' => 'Payment Proof Verified',
                'body' => "Your payment proof {$paymentProof->proof_no} for {$paymentProof->invoice_id} has been verified and credited.",
                'type' => 'billing',
                'action_url' => '/public/dashboard?tab=billing',
            ]);

            app(CustomerPortalNotificationSync::class)->syncForCustomer($paymentProof->customer_id);
        }

        return response()->json([
            'message' => 'Payment proof verified and invoice credited.',
            'data' => $this->mapAdminPaymentProof($paymentProof->fresh(['customer', 'salesTransaction.items'])),
        ]);
    }

    public function approveProfileChange(Request $request, CustomerProfileChangeRequest $profileChangeRequest)
    {
        $this->resolveStaff($request);
        abort_unless(
            $profileChangeRequest->status === 'Pending Review',
            422,
            'Only pending profile change requests can be approved.'
        );

        $customer = $profileChangeRequest->customer;
        abort_unless($customer, 422, 'Customer record not found for this profile change request.');

        $payload = $profileChangeRequest->requested_payload ?? [];

        $updates = [
            'fname' => $payload['fname'] ?? $customer->fname,
            'lname' => $payload['lname'] ?? $customer->lname,
            'mobile' => $payload['mobile'] ?? $customer->mobile,
            'mname' => $payload['mname'] ?? $customer->mname,
            'address_street' => $payload['address_street'] ?? $customer->address_street,
        ];

        if (!empty($payload['avatar_path'])) {
            $updates['avatar'] = $this->resolveApprovedProfileAvatarPath(
                $customer,
                $payload['avatar_path']
            );
        }

        $customer->update($updates);

        $profileChangeRequest->update([
            'status' => 'Approved',
            'reviewed_at' => now(),
        ]);

        CustomerNotification::create([
            'customer_id' => $customer->id,
            'title' => 'Profile Changes Approved',
            'body' => 'Your profile update request ' . $profileChangeRequest->request_no . ' has been approved and applied.',
            'type' => 'account',
            'action_url' => '/public/dashboard?tab=account',
        ]);

        app(CustomerPortalNotificationSync::class)->syncForCustomer($customer->id);

        return response()->json([
            'message' => 'Profile change approved and applied.',
            'data' => $this->mapAdminProfileChangeRequest($profileChangeRequest->fresh('customer')),
        ]);
    }

    public function rejectProfileChange(Request $request, CustomerProfileChangeRequest $profileChangeRequest)
    {
        $this->resolveStaff($request);
        abort_unless(
            $profileChangeRequest->status === 'Pending Review',
            422,
            'Only pending profile change requests can be rejected.'
        );

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->cleanupPendingProfileAvatar($profileChangeRequest->requested_payload ?? []);

        $profileChangeRequest->update([
            'status' => 'Rejected',
            'reviewed_at' => now(),
            'notes' => trim(($profileChangeRequest->notes ?? '') . ($validated['reason'] ?? '' ? "\nRejected: {$validated['reason']}" : '')),
        ]);

        if ($profileChangeRequest->customer_id) {
            CustomerNotification::create([
                'customer_id' => $profileChangeRequest->customer_id,
                'title' => 'Profile Changes Need Review',
                'body' => 'Your profile update request ' . $profileChangeRequest->request_no . ' could not be approved. Please review your details or contact support.',
                'type' => 'account',
                'action_url' => '/public/dashboard?tab=account',
            ]);

            app(CustomerPortalNotificationSync::class)->syncForCustomer($profileChangeRequest->customer_id);
        }

        return response()->json([
            'message' => 'Profile change rejected.',
            'data' => $this->mapAdminProfileChangeRequest($profileChangeRequest->fresh('customer')),
        ]);
    }

    public function rejectPaymentProof(Request $request, CustomerPaymentProof $paymentProof)
    {
        $this->resolveStaff($request);
        abort_unless($paymentProof->status === 'Pending Review', 422, 'Only pending proofs can be rejected.');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $paymentProof->update([
            'status' => 'Rejected',
            'notes' => trim(($paymentProof->notes ?? '') . ($validated['reason'] ?? '' ? "\nRejected: {$validated['reason']}" : '')),
        ]);

        if ($paymentProof->customer_id) {
            CustomerNotification::create([
                'customer_id' => $paymentProof->customer_id,
                'title' => 'Payment Proof Needs Review',
                'body' => "Your payment proof {$paymentProof->proof_no} could not be verified. Please upload a clearer receipt or contact billing support.",
                'type' => 'billing',
                'action_url' => '/public/dashboard?tab=billing',
            ]);
        }

        return response()->json([
            'message' => 'Payment proof rejected.',
            'data' => $this->mapAdminPaymentProof($paymentProof->fresh(['customer', 'salesTransaction.items'])),
        ]);
    }

    public function tickets(Request $request)
    {
        $this->resolveStaff($request);

        $rows = CustomerSupportTicket::query()
            ->with('customer:id,fname,lname,email')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $rows->through(fn (CustomerSupportTicket $ticket) => $this->mapAdminTicket($ticket)),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function updateTicket(Request $request, CustomerSupportTicket $ticket)
    {
        $this->resolveStaff($request);

        $validated = $request->validate([
            'status' => ['required', 'string', 'max:50'],
        ]);

        $ticket->update(['status' => $validated['status']]);

        if ($ticket->customer_id) {
            CustomerNotification::create([
                'customer_id' => $ticket->customer_id,
                'title' => 'Support Ticket Updated',
                'body' => "Ticket {$ticket->ticket_no} is now {$validated['status']}.",
                'type' => 'support',
                'action_url' => '/public/dashboard?tab=help',
            ]);
        }

        return response()->json([
            'message' => 'Ticket updated.',
            'data' => $this->mapAdminTicket($ticket->fresh('customer')),
        ]);
    }

    public function services(Request $request)
    {
        $this->resolveStaff($request);

        $rows = CustomerService::query()
            ->with(['customer:id,fname,lname,email', 'salesTransaction'])
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $rows->through(fn (CustomerService $service) => $this->mapAdminService($service)),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function notifications(Request $request)
    {
        $this->resolveStaff($request);

        $perPage = $request->integer('per_page', 50);

        $broadcastRows = CustomerNotification::query()
            ->where('reference_key', 'like', 'broadcast:%')
            ->select('reference_key')
            ->selectRaw('MIN(id) as id')
            ->selectRaw('MAX(title) as title')
            ->selectRaw('MAX(body) as body')
            ->selectRaw('MAX(created_at) as created_at')
            ->groupBy('reference_key')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $clientAlerts = $this->pendingWebDesignQuotationsQuery()
            ->with(['customer:id,fname,lname,email,mname', 'items'])
            ->latest('transacted_at')
            ->latest('id')
            ->limit($perPage)
            ->get()
            ->map(fn (SalesTransaction $row) => $this->mapWebDesignQuotationAlert($row))
            ->values();

        // Also surface staff inbox copies (in case Sales refreshes before TX list catches up).
        $staffAlertKeys = $clientAlerts
            ->pluck('id')
            ->map(fn ($id) => 'admin:webdesign-quotation:' . $id)
            ->all();

        $extraStaffAlerts = CustomerNotification::query()
            ->where('reference_key', 'like', 'admin:webdesign-quotation:%')
            ->when($staffAlertKeys, fn ($q) => $q->whereNotIn('reference_key', $staffAlertKeys))
            ->select('reference_key')
            ->selectRaw('MIN(id) as id')
            ->selectRaw('MAX(title) as title')
            ->selectRaw('MAX(body) as body')
            ->selectRaw('MAX(created_at) as created_at')
            ->groupBy('reference_key')
            ->orderByDesc('created_at')
            ->limit($perPage)
            ->get()
            ->map(function ($row) {
                $txnId = (int) str_replace('admin:webdesign-quotation:', '', (string) $row->reference_key);

                return [
                    'id' => $txnId > 0 ? $txnId : (int) $row->id,
                    'kind' => 'web_design_quotation',
                    'title' => $row->title ?: 'Web Design Quotation Request',
                    'desc' => $row->body,
                    'date' => $this->formatAppDateTime($row->created_at),
                    'audience' => 'Client',
                    'email' => null,
                    'transactionNo' => null,
                    'status' => 'Needs Pricing',
                    'actionUrl' => '/public/commerce-admin?tab=transactions',
                ];
            });

        $clientAlerts = $clientAlerts->concat($extraStaffAlerts)->values();

        return response()->json([
            'data' => [
                'clientAlerts' => $clientAlerts,
                'broadcasts' => $broadcastRows->getCollection()->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'title' => $row->title,
                    'desc' => $row->body,
                    'date' => optional($row->created_at)->format('Y-m-d'),
                    'audience' => 'All Client Portals',
                    'status' => 'Sent',
                    'kind' => 'broadcast',
                ])->values(),
            ],
            'meta' => [
                'pendingQuotations' => $clientAlerts->count(),
                'broadcasts' => [
                    'current_page' => $broadcastRows->currentPage(),
                    'last_page' => $broadcastRows->lastPage(),
                    'total' => $broadcastRows->total(),
                ],
            ],
        ]);
    }

    public function broadcastNotification(Request $request)
    {
        $this->resolveStaff($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $customerIds = User::role('customer')
            ->where('is_active', true)
            ->pluck('id');

        if ($customerIds->isEmpty()) {
            return response()->json([
                'message' => 'No active client accounts found to receive this broadcast.',
            ], 422);
        }

        $referenceKey = 'broadcast:' . now()->format('YmdHis') . ':' . substr(md5($validated['title'] . $validated['body']), 0, 8);
        $now = now();
        $payload = $customerIds->map(fn ($customerId) => [
            'customer_id' => $customerId,
            'reference_key' => $referenceKey,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'type' => 'general',
            'action_url' => '/public/dashboard?tab=notification',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        CustomerNotification::query()->insert($payload);

        return response()->json([
            'message' => 'Broadcast notice successfully sent to all client portals.',
            'data' => [
                'recipients' => count($payload),
                'referenceKey' => $referenceKey,
            ],
        ], 201);
    }

    private function mapAdminProfileChangeRequest(CustomerProfileChangeRequest $request): array
    {
        $customer = $request->customer;
        $client = trim(($customer?->mname ?: '') !== ''
            ? $customer->mname
            : ($customer?->full_name ?? 'Customer'));
        $payload = $request->requested_payload ?? [];
        $changes = $this->profileChangeFields($request);
        $avatarPath = $payload['avatar_path'] ?? null;

        return [
            'id' => $request->id,
            'kind' => 'profile_change',
            'proofNo' => $request->request_no,
            'invoiceId' => $request->request_no,
            'client' => $client,
            'email' => $customer?->email,
            'fileName' => $avatarPath ? basename($avatarPath) : '',
            'fileUrl' => StorageUrl::publicAsset($avatarPath),
            'status' => $request->status,
            'notes' => $request->notes,
            'summary' => $request->summary,
            'changes' => $changes,
            'currentAvatarUrl' => StorageUrl::publicAsset($customer?->avatar),
            'submittedAt' => optional($request->created_at)->format('Y-m-d H:i'),
            'issuedDate' => optional($request->created_at)->format('M j, Y'),
            'expiredDate' => optional($request->created_at)?->copy()->addDays(7)->format('M j, Y'),
            'amount' => 0,
            'serviceName' => 'Profile Change',
            'plan' => $this->profileChangePlanLabel($payload),
        ];
    }

    private function profileChangeFields(CustomerProfileChangeRequest $request): array
    {
        $current = $request->current_snapshot ?? [];
        $requested = $request->requested_payload ?? [];
        $labels = [
            'fname' => 'First Name',
            'lname' => 'Last Name',
            'mobile' => 'Mobile Phone',
            'mname' => 'Company Legal Name',
            'address_street' => 'Billing Address',
        ];
        $changes = [];

        foreach ($labels as $key => $label) {
            $from = trim((string) ($current[$key] ?? ''));
            $to = trim((string) ($requested[$key] ?? ''));
            if ($from !== $to) {
                $changes[] = [
                    'field' => $key,
                    'label' => $label,
                    'from' => $from ?: '—',
                    'to' => $to ?: '—',
                ];
            }
        }

        $currentAvatar = $current['avatar'] ?? null;
        $requestedAvatar = $requested['avatar_path'] ?? null;
        if ($requestedAvatar) {
            $changes[] = [
                'field' => 'avatar',
                'label' => 'Profile Photo',
                'from' => StorageUrl::publicAsset($currentAvatar) ?? '—',
                'to' => StorageUrl::publicAsset($requestedAvatar) ?? '—',
            ];
        }

        return $changes;
    }

    private function resolveApprovedProfileAvatarPath(User $customer, string $pendingPath): string
    {
        if (!Storage::disk('public')->exists($pendingPath)) {
            abort(422, 'The requested profile photo file could not be found. Ask the customer to submit again.');
        }

        $extension = pathinfo($pendingPath, PATHINFO_EXTENSION) ?: 'jpg';
        $newPath = 'avatars/' . $customer->id . '-' . now()->format('YmdHis') . '.' . $extension;
        Storage::disk('public')->move($pendingPath, $newPath);

        if ($customer->avatar && Storage::disk('public')->exists($customer->avatar)) {
            Storage::disk('public')->delete($customer->avatar);
        }

        return $newPath;
    }

    private function cleanupPendingProfileAvatar(array $payload): void
    {
        $path = $payload['avatar_path'] ?? null;
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function profileChangePlanLabel(array $payload): string
    {
        if (!empty($payload['avatar_path'])) {
            $parts = array_filter([
                'Profile photo update',
                trim(($payload['fname'] ?? '') . ' ' . ($payload['lname'] ?? '')),
                $payload['mname'] ?? null,
            ]);
            return implode(' · ', $parts);
        }

        $parts = array_filter([
            trim(($payload['fname'] ?? '') . ' ' . ($payload['lname'] ?? '')),
            $payload['mname'] ?? null,
            $payload['mobile'] ?? null,
        ]);

        return $parts ? implode(' · ', $parts) : 'Profile update request';
    }

    private function mapAdminPaymentProof(CustomerPaymentProof $proof): array
    {
        $customer = $proof->customer;
        $company = $customer?->full_name ?? 'Customer';
        $transaction = $proof->salesTransaction;
        $items = $transaction?->items;
        $firstItem = $items?->first();
        $transactedAt = $transaction?->transacted_at;

        return [
            'id' => $proof->id,
            'proofNo' => $proof->proof_no,
            'invoiceId' => $proof->invoice_id,
            'client' => $company,
            'email' => $customer?->email,
            'fileName' => $proof->file_name,
            'fileUrl' => StorageUrl::publicAsset($proof->file_path),
            'status' => $proof->status,
            'notes' => $proof->notes,
            'submittedAt' => optional($proof->created_at)->format('Y-m-d H:i'),
            'issuedDate' => TransactionLabelResolver::issuedDateFrom($transactedAt),
            'expiredDate' => TransactionLabelResolver::dueDateFrom($transactedAt),
            'amount' => (float) ($transaction?->grand_total ?? 0),
            'serviceName' => TransactionLabelResolver::serviceCategoryFromItems($items),
            'plan' => TransactionLabelResolver::planLabel($items, $firstItem?->name),
        ];
    }

    private function mapAdminTicket(CustomerSupportTicket $ticket): array
    {
        $customer = $ticket->customer;

        return [
            'id' => $ticket->id,
            'ticketNo' => $ticket->ticket_no,
            'subject' => $ticket->subject,
            'message' => $ticket->message,
            'client' => $customer?->full_name ?? 'Customer',
            'email' => $customer?->email,
            'status' => $ticket->status,
            'updatedAt' => optional($ticket->updated_at)->format('Y-m-d H:i'),
        ];
    }

    private function mapAdminService(CustomerService $service): array
    {
        $customer = $service->customer;

        return [
            'id' => $service->id,
            'title' => $service->title,
            'category' => $service->category,
            'plan' => $service->plan,
            'status' => $service->status,
            'client' => $customer?->full_name ?? 'Customer',
            'email' => $customer?->email,
            'renewLabel' => $service->renew_label,
            'renewAt' => optional($service->renew_at)->format('Y-m-d'),
            'transactionNo' => $service->salesTransaction?->transaction_no,
        ];
    }

    private function mapQueueOrder(SalesTransaction $row): array
    {
        return [
            'id' => (string) $row->id,
            'orderId' => $row->transaction_no,
            'company' => $row->customer_name ?: ($row->customer?->full_name ?? 'Unknown'),
            'dateCreated' => optional($row->transacted_at)->format('Y-m-d'),
            'amount' => (float) $row->grand_total,
            'status' => ucfirst($row->order_status ?: 'New'),
        ];
    }

    private function mapExpiringService(CustomerService $row): array
    {
        $daysLeft = $row->renew_at ? now()->diffInDays(Carbon::parse($row->renew_at), false) : null;

        return [
            'id' => (string) $row->id,
            'service' => $row->title,
            'company' => $row->customer?->full_name ?? 'Customer',
            'expiryDate' => optional($row->renew_at)->format('Y-m-d'),
            'daysLeft' => $daysLeft !== null ? "{$daysLeft} Days" : '—',
            'status' => 'Expiring',
        ];
    }

    private function mapOverdueInvoice(SalesTransaction $row): array
    {
        return [
            'id' => (string) $row->id,
            'reference' => $row->transaction_no,
            'company' => $row->customer_name ?: ($row->customer?->full_name ?? 'Unknown'),
            'dueDate' => optional($row->transacted_at)->format('Y-m-d'),
            'amount' => (float) $row->grand_total,
            'status' => 'Overdue',
        ];
    }

    private function pendingWebDesignQuotationsQuery()
    {
        return SalesTransaction::query()
            ->where(function ($query) {
                $query->where('notes', 'like', '%Pricing: Pending Quotation%')
                    ->orWhere(function ($inner) {
                        $inner->where(function ($notes) {
                            $notes->whereNull('notes')
                                ->orWhere('notes', 'not like', '%Pricing: Set by Sales%');
                        })
                            ->where('grand_total', '<=', 0)
                            ->whereHas('items', function ($items) {
                                $items->where(function ($item) {
                                    $item->where('item_type', 'like', '%web_design%')
                                        ->orWhere('item_type', 'like', '%webdesign%')
                                        ->orWhere('name', 'like', '%web design%')
                                        ->orWhere('name', 'like', '%Starter Launch%')
                                        ->orWhere('name', 'like', '%Professional Corporate%')
                                        ->orWhere('name', 'like', '%E-Commerce%');
                                });
                            });
                    });
            })
            ->where(function ($query) {
                $query->whereNull('notes')
                    ->orWhere('notes', 'not like', '%Pricing: Set by Sales%');
            });
    }

    private function formatAppDateTime(mixed $value, string $format = 'Y-m-d H:i'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $carbon = $value instanceof CarbonInterface
            ? $value->copy()
            : Carbon::parse($value);

        return $carbon
            ->timezone(config('app.timezone', 'Asia/Manila'))
            ->format($format);
    }

    private function mapWebDesignQuotationAlert(SalesTransaction $row): array
    {
        $customer = $row->customer;
        $client = $row->customer_name
            ?: (trim(($customer?->mname ?: '') !== ''
                ? (string) $customer->mname
                : ($customer?->full_name ?? 'Client')));
        $itemNames = $row->items
            ? $row->items->pluck('name')->filter()->take(3)->implode(', ')
            : '';

        return [
            'id' => (int) $row->id,
            'kind' => 'web_design_quotation',
            'title' => 'Web Design Quotation Request',
            'desc' => trim(
                "{$client} checked out a web design package"
                . ($itemNames ? " ({$itemNames})" : '')
                . ". Transaction {$row->transaction_no} needs Sales pricing."
            ),
            'date' => $this->formatAppDateTime(
                // Prefer created_at (actual submit time); avoid UTC ISO stored as naive transacted_at.
                $row->created_at ?? $row->transacted_at
            ),
            'audience' => $client,
            'email' => $row->customer_email ?: ($customer?->email),
            'transactionNo' => $row->transaction_no,
            'status' => 'Needs Pricing',
            'actionUrl' => '/public/commerce-admin?tab=transactions',
        ];
    }

    private function pendingPaymentProofCount(): int
    {
        return (int) CustomerPaymentProof::query()
            ->where('status', 'Pending Review')
            ->selectRaw('COUNT(DISTINCT COALESCE(sales_transaction_id, invoice_id)) as aggregate')
            ->value('aggregate');
    }

    /** Keep only the newest pending proof per invoice and remove older duplicates. */
    private function collapseDuplicatePendingPaymentProofs(): void
    {
        $pending = CustomerPaymentProof::query()
            ->where('status', 'Pending Review')
            ->orderByDesc('id')
            ->get(['id', 'invoice_id', 'sales_transaction_id', 'file_path']);

        $seen = [];
        foreach ($pending as $proof) {
            $key = (string) ($proof->sales_transaction_id ?: $proof->invoice_id);
            if ($key === '') {
                $key = 'id:' . $proof->id;
            }

            if (isset($seen[$key])) {
                if ($proof->file_path && Storage::disk('public')->exists($proof->file_path)) {
                    Storage::disk('public')->delete($proof->file_path);
                }
                $proof->delete();
                continue;
            }

            $seen[$key] = true;
        }
    }
}
