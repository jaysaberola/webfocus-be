<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Models\CustomerPaymentProof;
use App\Models\CustomerService;
use App\Models\CustomerSupportTicket;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Services\CustomerPortalNotificationSync;
use App\Services\CustomerPortalProvisioner;
use Carbon\Carbon;
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

        $pendingProofs = CustomerPaymentProof::query()->where('status', 'Pending Review')->count();
        $openTickets = CustomerSupportTicket::query()->whereIn('status', ['Open', 'In Progress'])->count();
        $activeClients = User::role('customer')->where('is_active', true)->count();
        $activeServices = CustomerService::query()->where('status', 'Active')->count();

        return response()->json([
            'data' => [
                'counts' => [
                    'pendingApprovals' => $pendingProofs,
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
            'data' => $rows->through(fn (CustomerPaymentProof $proof) => $this->mapAdminPaymentProof($proof)),
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

    private function mapAdminPaymentProof(CustomerPaymentProof $proof): array
    {
        $customer = $proof->customer;
        $company = $customer?->full_name ?? 'Customer';

        return [
            'id' => $proof->id,
            'proofNo' => $proof->proof_no,
            'invoiceId' => $proof->invoice_id,
            'client' => $company,
            'email' => $customer?->email,
            'fileName' => $proof->file_name,
            'fileUrl' => $proof->file_path ? url(Storage::disk('public')->url($proof->file_path)) : null,
            'status' => $proof->status,
            'notes' => $proof->notes,
            'submittedAt' => optional($proof->created_at)->format('Y-m-d H:i'),
            'amount' => (float) ($proof->salesTransaction?->grand_total ?? 0),
            'serviceName' => $proof->salesTransaction?->items?->first()?->name,
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
}
