<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Models\CustomerPaymentProof;
use App\Models\CustomerService;
use App\Models\CustomerSupportTicket;
use App\Models\SalesTransaction;
use App\Models\SalesTransactionItem;
use App\Models\User;
use App\Services\CustomerPortalProvisioner;
use App\Services\CustomerPortalNotificationSync;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CustomerPortalController extends Controller
{
    public function overview(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        app(CustomerPortalNotificationSync::class)->syncForCustomer($customer->id);

        $services = CustomerService::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('updated_at')
            ->get();

        $transactions = SalesTransaction::query()
            ->where('customer_id', $customer->id)
            ->with('items')
            ->latest('transacted_at')
            ->get();

        $openTickets = CustomerSupportTicket::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'Open')
            ->count();

        $activeServices = $services->where('status', 'Active')->count();
        $unpaid = $transactions->filter(fn ($row) => !in_array(strtolower((string) $row->payment_status), ['paid', 'completed', 'success'], true));
        $nextUnpaid = $unpaid->sortBy(fn ($row) => $row->transacted_at)->first();

        $stats = [
            'activeNodes' => $activeServices . ' Active',
            'activeNodesDetail' => $activeServices > 0
                ? $services->firstWhere('status', 'Active')?->plan . ' High Availability'
                : 'No active services yet',
            'unpaidInvoices' => $unpaid->count() . ' Pending',
            'unpaidInvoicesDetail' => $nextUnpaid
                ? '₱' . number_format((float) $nextUnpaid->grand_total, 0) . ' due ' . optional($nextUnpaid->transacted_at?->copy()->addDays(30))->format('M j')
                : 'All invoices are paid',
            'supportTickets' => $openTickets . ' Open',
            'supportTicketsDetail' => $openTickets > 0 ? 'Average response: 14 mins' : 'No open tickets',
            'slaUptime' => '99.99%',
            'slaUptimeDetail' => 'Tier-1 Fiber Route',
        ];

        $alerts = app(CustomerPortalNotificationSync::class)->buildOverviewAlerts($services, $transactions);

        return response()->json([
            'data' => [
                'stats' => $stats,
                'alerts' => $alerts,
                'services' => $services->map(fn (CustomerService $service) => $this->mapService($service))->values(),
            ],
        ]);
    }

    public function services(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        $services = CustomerService::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (CustomerService $service) => $this->mapService($service))
            ->values();

        return response()->json(['data' => $services]);
    }

    public function orders(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        $orders = SalesTransaction::query()
            ->where('customer_id', $customer->id)
            ->with('items')
            ->latest('transacted_at')
            ->get()
            ->map(fn (SalesTransaction $row) => $this->mapOrder($row))
            ->values();

        return response()->json(['data' => $orders]);
    }

    public function billing(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        $transactions = SalesTransaction::query()
            ->where('customer_id', $customer->id)
            ->with('items')
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('transacted_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('transacted_at', '<=', $request->input('date_to')))
            ->latest('transacted_at')
            ->get();

        $allTransactions = SalesTransaction::query()
            ->where('customer_id', $customer->id)
            ->with('items')
            ->latest('transacted_at')
            ->get();

        $invoices = $transactions->map(fn (SalesTransaction $row) => $this->mapInvoice($row))->values();
        $reminder = $this->buildBillingReminder($allTransactions);

        $paymentProofs = CustomerPaymentProof::query()
            ->where('customer_id', $customer->id)
            ->latest()
            ->get()
            ->map(fn (CustomerPaymentProof $proof) => $this->mapPaymentProof($proof))
            ->values();

        return response()->json([
            'data' => [
                'invoices' => $invoices,
                'reminder' => $reminder,
                'paymentProofs' => $paymentProofs,
            ],
        ]);
    }

    public function uploadPaymentProof(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        $validated = $request->validate([
            'invoice_id' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
            'receipt' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:5120'],
        ]);

        $transaction = $this->resolveInvoiceTransaction($customer, $validated['invoice_id']);
        $this->assertInvoicePayable($transaction);

        $file = $request->file('receipt');
        $path = $file->store('customer-payment-proofs/' . $customer->id, 'public');

        $proof = CustomerPaymentProof::create([
            'customer_id' => $customer->id,
            'sales_transaction_id' => $transaction->id,
            'proof_no' => $this->generatePaymentProofNo(),
            'invoice_id' => $this->invoiceId($transaction),
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'status' => 'Pending Review',
            'notes' => $validated['notes'] ?? null,
        ]);

        CustomerNotification::create([
            'customer_id' => $customer->id,
            'title' => 'Payment Proof Uploaded',
            'body' => 'We received your payment proof for ' . $proof->invoice_id . '. Our billing team will verify it shortly.',
            'type' => 'billing',
            'action_url' => '/public/dashboard?tab=billing',
        ]);

        app(CustomerPortalNotificationSync::class)->syncForCustomer($customer->id);

        return response()->json([
            'message' => 'Payment proof uploaded successfully',
            'data' => $this->mapPaymentProof($proof),
        ], 201);
    }

    public function deletePaymentProof(Request $request, CustomerPaymentProof $paymentProof)
    {
        $customer = $this->resolveCustomer($request);
        abort_unless((int) $paymentProof->customer_id === (int) $customer->id, 403);
        abort_if($paymentProof->status === 'Verified & Credited', 422, 'Verified payment proofs cannot be deleted.');

        if ($paymentProof->file_path && Storage::disk('public')->exists($paymentProof->file_path)) {
            Storage::disk('public')->delete($paymentProof->file_path);
        }

        $paymentProof->delete();

        return response()->json(['message' => 'Payment proof deleted']);
    }

    public function notifications(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        app(CustomerPortalNotificationSync::class)->syncForCustomer($customer->id);

        $rows = CustomerNotification::query()
            ->where('customer_id', $customer->id)
            ->latest()
            ->get()
            ->map(fn (CustomerNotification $row) => [
                'id' => $row->id,
                'title' => $row->title,
                'desc' => $row->body,
                'date' => optional($row->created_at)->format('Y-m-d'),
                'unread' => $row->read_at === null,
                'type' => $row->type,
                'actionUrl' => $row->action_url,
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function unreadNotificationCount(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        app(CustomerPortalNotificationSync::class)->syncForCustomer($customer->id);

        $count = CustomerNotification::query()
            ->where('customer_id', $customer->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['data' => ['count' => $count]]);
    }

    public function markNotificationRead(Request $request, CustomerNotification $notification)
    {
        $customer = $this->resolveCustomer($request);
        abort_unless((int) $notification->customer_id === (int) $customer->id, 403);

        if (!$notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['message' => 'Notification marked as read']);
    }

    public function markAllNotificationsRead(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        CustomerNotification::query()
            ->where('customer_id', $customer->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read']);
    }

    public function deleteNotification(Request $request, CustomerNotification $notification)
    {
        $customer = $this->resolveCustomer($request);
        abort_unless((int) $notification->customer_id === (int) $customer->id, 403);

        $notification->delete();

        return response()->json(['message' => 'Notification dismissed']);
    }

    public function tickets(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        $rows = CustomerSupportTicket::query()
            ->where('customer_id', $customer->id)
            ->latest()
            ->get()
            ->map(fn (CustomerSupportTicket $row) => [
                'id' => $row->ticket_no,
                'subject' => $row->subject,
                'date' => optional($row->created_at)->format('Y-m-d'),
                'status' => $row->status,
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function storeTicket(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $ticket = CustomerSupportTicket::create([
            'customer_id' => $customer->id,
            'ticket_no' => $this->generateTicketNo(),
            'subject' => $validated['subject'],
            'message' => $validated['message'] ?? null,
            'status' => 'Open',
        ]);

        CustomerNotification::create([
            'customer_id' => $customer->id,
            'title' => 'Support Ticket Created',
            'body' => 'Ticket ' . $ticket->ticket_no . ' has been submitted. Our team will respond shortly.',
            'type' => 'support',
            'action_url' => '/public/dashboard?tab=help',
        ]);

        return response()->json([
            'message' => 'Support ticket created',
            'data' => [
                'id' => $ticket->ticket_no,
                'subject' => $ticket->subject,
                'date' => optional($ticket->created_at)->format('Y-m-d'),
                'status' => $ticket->status,
            ],
        ], 201);
    }

    public function payInvoice(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        $validated = $request->validate([
            'invoice_id' => ['required', 'string', 'max:120'],
            'payment_method' => ['required', 'string', 'max:40'],
        ]);

        $transaction = $this->resolveInvoiceTransaction($customer, $validated['invoice_id']);
        $this->assertInvoicePayable($transaction);
        $this->assertInvoiceCanPay($transaction);

        $paymentLabel = $this->formatPaymentMethodLabel($validated['payment_method']);
        $paymentGateway = $this->formatPaymentMethodGateway($validated['payment_method']);

        $transaction->update([
            'notes' => $this->buildPaymentNotes($transaction, $paymentGateway, $paymentLabel, 'Invoice payment'),
        ]);

        CustomerNotification::create([
            'customer_id' => $customer->id,
            'title' => 'Payment Initiated',
            'body' => "Payment for {$this->invoiceId($transaction)} via {$paymentLabel} is being processed through Paynamics.",
            'type' => 'billing',
            'action_url' => '/public/dashboard?tab=orders',
        ]);

        app(CustomerPortalNotificationSync::class)->syncForCustomer($customer->id);

        return response()->json([
            'message' => 'Payment initiated',
            'data' => [
                'redirectUrl' => '/public/dashboard?tab=orders',
                'paymentLabel' => $paymentLabel,
                'invoiceId' => $this->invoiceId($transaction),
            ],
        ]);
    }

    public function addFunds(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:100'],
            'payment_method' => ['required', 'string', 'max:40'],
        ]);

        $amount = round((float) $validated['amount'], 2);
        $paymentLabel = $this->formatPaymentMethodLabel($validated['payment_method']);
        $paymentGateway = $this->formatPaymentMethodGateway($validated['payment_method']);

        $transaction = SalesTransaction::create([
            'transaction_no' => $this->generateTransactionNo(),
            'customer_id' => $customer->id,
            'customer_name' => trim($customer->fname . ' ' . $customer->lname),
            'customer_email' => $customer->email,
            'subtotal' => $amount,
            'discount_total' => 0,
            'tax_total' => 0,
            'shipping_total' => 0,
            'grand_total' => $amount,
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'notes' => $this->buildPaymentNotes(null, $paymentGateway, $paymentLabel, 'Account credit top-up'),
            'transacted_at' => now(),
        ]);

        SalesTransactionItem::create([
            'sales_transaction_id' => $transaction->id,
            'name' => 'Account Credit',
            'item_type' => 'credit',
            'price' => $amount,
            'quantity' => 1,
            'total_price' => $amount,
        ]);

        CustomerNotification::create([
            'customer_id' => $customer->id,
            'title' => 'Add Funds Requested',
            'body' => 'Account credit of ₱' . number_format($amount, 0) . " via {$paymentLabel} is pending Paynamics confirmation.",
            'type' => 'billing',
            'action_url' => '/public/dashboard?tab=orders',
        ]);

        app(CustomerPortalNotificationSync::class)->syncForCustomer($customer->id);

        return response()->json([
            'message' => 'Add funds request created',
            'data' => [
                'redirectUrl' => '/public/dashboard?tab=orders',
                'paymentLabel' => $paymentLabel,
                'invoiceId' => $this->invoiceId($transaction),
                'amount' => $amount,
            ],
        ], 201);
    }

    private function resolveCustomer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user, 401);

        if (!$user->hasRole('customer')) {
            abort(403, 'Customer portal access only.');
        }

        return $user;
    }

    private function mapService(CustomerService $service): array
    {
        return [
            'id' => (string) $service->id,
            'title' => $service->title,
            'category' => $service->category,
            'plan' => $service->plan,
            'renewLabel' => $service->renew_label,
            'renewDate' => CustomerPortalProvisioner::formatRenewDate($service->renew_at),
            'renewNote' => $service->renew_note ?: CustomerPortalProvisioner::formatRenewNote($service->renew_at),
            'status' => $service->status,
        ];
    }

    private function mapOrder(SalesTransaction $row): array
    {
        $firstItem = $row->items->first();
        $items = $row->items->map(fn ($item) => [
            'name' => $this->resolveInvoiceServiceName($item->name, $item->item_type),
            'detail' => $item->name,
            'price' => (float) $item->total_price,
        ])->values()->all();

        $payment = strtolower((string) $row->payment_status);
        $order = strtolower((string) $row->order_status);
        $isLive = in_array($payment, ['paid', 'completed', 'success'], true)
            && in_array($order, ['completed', 'active', 'delivered', 'live'], true);

        return [
            'id' => $row->transaction_no,
            'invoiceId' => $this->invoiceId($row),
            'serviceName' => $this->resolveInvoiceServiceName($firstItem?->name, $firstItem?->item_type),
            'date' => optional($row->transacted_at)->format('Y-m-d'),
            'expiredDate' => optional($row->transacted_at?->copy()->addYear())->format('Y-m-d'),
            'total' => (float) $row->grand_total,
            'status' => $isLive ? 'Active Live' : 'Pending Request',
            'gateway' => $this->extractPaymentMethod($row->notes),
            'items' => $items,
        ];
    }

    private function mapInvoice(SalesTransaction $row): array
    {
        $paid = in_array(strtolower((string) $row->payment_status), ['paid', 'completed', 'success'], true);
        $firstItem = $row->items->first();
        $dueAt = $row->transacted_at?->copy()->addDays(30);
        $daysUntilDue = $dueAt
            ? now()->startOfDay()->diffInDays($dueAt->copy()->startOfDay(), false)
            : null;
        $paymentSubmitted = $this->isPaymentSubmitted($row);
        $canPay = !$paid && !$paymentSubmitted && $daysUntilDue !== null && $daysUntilDue <= 7;

        if ($paid) {
            $status = 'Paid';
        } elseif ($paymentSubmitted) {
            $status = 'Awaiting Approval';
        } elseif ($daysUntilDue !== null && $daysUntilDue < 0) {
            $status = 'Overdue';
        } elseif ($daysUntilDue !== null && $daysUntilDue <= 7) {
            $status = 'Payment Due';
        } else {
            $status = 'Pending Payment';
        }

        return [
            'id' => $this->invoiceId($row),
            'transactionNo' => $row->transaction_no,
            'date' => optional($row->transacted_at)->format('Y-m-d'),
            'due' => optional($dueAt)->format('Y-m-d'),
            'amount' => (float) $row->grand_total,
            'status' => $status,
            'canPay' => $canPay,
            'daysUntilDue' => $daysUntilDue,
            'serviceName' => $this->resolveInvoiceServiceName($firstItem?->name, $firstItem?->item_type),
            'plan' => $firstItem?->name ?? $row->transaction_no,
            'subscription' => $firstItem?->name ?? $row->transaction_no,
            'items' => $this->resolveInvoiceServiceName($firstItem?->name, $firstItem?->item_type),
        ];
    }

    private function mapPaymentProof(CustomerPaymentProof $proof): array
    {
        return [
            'id' => $proof->proof_no,
            'recordId' => $proof->id,
            'invoiceId' => $proof->invoice_id,
            'fileName' => $proof->file_name,
            'fileUrl' => $proof->file_path
                ? url(Storage::disk('public')->url($proof->file_path))
                : null,
            'date' => optional($proof->created_at)->format('Y-m-d'),
            'status' => $proof->status,
            'notes' => $proof->notes,
        ];
    }

    private function resolveInvoiceServiceName(?string $name, ?string $itemType): string
    {
        $haystack = strtolower(trim(($name ?? '') . ' ' . ($itemType ?? '')));

        if (str_contains($haystack, 'domain')) {
            return 'Secure Domain';
        }
        if (str_contains($haystack, 'dms') || str_contains($haystack, 'document')) {
            return 'DMS';
        }
        if (str_contains($haystack, 'design') || str_contains($haystack, 'canvas') || str_contains($haystack, 'web')) {
            return 'Custom Web Design';
        }
        if (str_contains($haystack, 'credit')) {
            return 'Account Credit';
        }
        if (str_contains($haystack, 'hosting') || str_contains($haystack, 'cloud') || str_contains($haystack, 'server')) {
            return 'Hosting';
        }

        return $name ?: 'Service';
    }

    private function generatePaymentProofNo(): string
    {
        $prefix = 'PRF-' . now()->format('ymd') . '-';
        $latest = CustomerPaymentProof::query()
            ->where('proof_no', 'like', $prefix . '%')
            ->orderByDesc('proof_no')
            ->value('proof_no');

        $next = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function isPaymentSubmitted(SalesTransaction $row): bool
    {
        $notes = trim((string) $row->notes);

        if ($notes === '') {
            return false;
        }

        return str_starts_with($notes, 'Invoice payment')
            || str_starts_with($notes, 'Account credit top-up');
    }

    private function extractPaymentMethod(?string $notes): string
    {
        if (!$notes) {
            return 'Paynamics IPG';
        }

        $trimmed = trim($notes);
        if (!str_contains($trimmed, "\n") && strlen($trimmed) <= 40) {
            return $trimmed;
        }

        if (preg_match('/Payment method:\s*([^\n]+)/i', $trimmed, $matches)) {
            $line = trim($matches[1]);

            if (preg_match('/\(([^)]+)\)\s*$/', $line, $labelMatch)) {
                return trim($labelMatch[1]);
            }

            if (preg_match('/^Paynamics-(\w+)/i', $line, $idMatch)) {
                return $this->formatPaymentMethodLabel(strtolower($idMatch[1]));
            }

            return $line;
        }

        return 'Paynamics IPG';
    }

    private function formatPaymentMethodLabel(string $methodId): string
    {
        return match (strtolower($methodId)) {
            'cc' => 'Credit / Debit Card',
            'gc' => 'GCash',
            'bn' => 'Online Bank Transfer',
            'ecpay' => 'Over-the-Counter',
            default => ucwords(str_replace(['-', '_'], ' ', $methodId)),
        };
    }

    private function formatPaymentMethodGateway(string $methodId): string
    {
        return 'Paynamics-' . strtolower($methodId);
    }

    private function buildPaymentNotes(
        ?SalesTransaction $transaction,
        string $gateway,
        string $label,
        string $title
    ): string {
        $lines = [$title, "Payment method: {$gateway} ({$label})"];

        if ($transaction) {
            $transaction->loadMissing('items');
            if ($transaction->items->isNotEmpty()) {
                $lines[] = '';
                $lines[] = 'Items:';
                foreach ($transaction->items as $item) {
                    $lines[] = '1 x ' . $item->name . ' @ ₱' . number_format((float) $item->total_price, 2);
                }
            }
        }

        return implode("\n", $lines);
    }

    private function resolveInvoiceTransaction(User $customer, string $invoiceId): SalesTransaction
    {
        $transactionNo = str_starts_with($invoiceId, 'INV-')
            ? substr($invoiceId, 4)
            : $invoiceId;

        return SalesTransaction::query()
            ->where('customer_id', $customer->id)
            ->where('transaction_no', $transactionNo)
            ->with('items')
            ->firstOrFail();
    }

    private function assertInvoicePayable(SalesTransaction $transaction): void
    {
        $paid = in_array(strtolower((string) $transaction->payment_status), ['paid', 'completed', 'success'], true);
        abort_if($paid, 422, 'This invoice is already paid.');
    }

    private function assertInvoiceCanPay(SalesTransaction $transaction): void
    {
        if ($this->isPaymentSubmitted($transaction)) {
            abort(422, 'Payment is already pending admin approval.');
        }

        $dueAt = $transaction->transacted_at?->copy()->addDays(30);
        if (!$dueAt) {
            abort(422, 'This invoice is not ready for payment yet.');
        }

        $daysUntilDue = now()->startOfDay()->diffInDays($dueAt->copy()->startOfDay(), false);
        abort_if($daysUntilDue > 7, 422, 'Payment opens 7 days before the due date.');
    }

    private function generateTransactionNo(): string
    {
        $prefix = 'ST-' . now()->format('Ymd') . '-';
        $latest = SalesTransaction::withTrashed()
            ->where('transaction_no', 'like', $prefix . '%')
            ->orderByDesc('transaction_no')
            ->value('transaction_no');

        $next = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function invoiceId(SalesTransaction $row): string
    {
        return 'INV-' . $row->transaction_no;
    }

    private function buildBillingReminder(Collection $transactions): ?array
    {
        $pending = $transactions->filter(
            fn ($row) => !in_array(strtolower((string) $row->payment_status), ['paid', 'completed', 'success'], true)
        );

        if ($pending->isEmpty()) {
            return null;
        }

        $candidate = $pending
            ->sortBy(fn (SalesTransaction $row) => optional($row->transacted_at?->copy()->addDays(30))->timestamp ?? PHP_INT_MAX)
            ->first();

        if (!$candidate) {
            return null;
        }

        $dueAt = $candidate->transacted_at?->copy()->addDays(30);
        if (!$dueAt) {
            return null;
        }

        $daysUntilDue = now()->startOfDay()->diffInDays($dueAt->copy()->startOfDay(), false);

        // New orders still have ~30 days to pay — don't show a renewal banner yet.
        if ($daysUntilDue > 7) {
            return null;
        }

        $isRenewal = $this->isRenewalReminder($candidate);
        $dueDaysLabel = $daysUntilDue < 0
            ? 'Overdue'
            : 'Due in ' . max($daysUntilDue, 0) . ' Day' . ($daysUntilDue === 1 ? '' : 's');
        $canPay = !$this->isPaymentSubmitted($candidate) && $daysUntilDue <= 7;

        return [
            'invoiceId' => $this->invoiceId($candidate),
            'transactionNo' => $candidate->transaction_no,
            'title' => $candidate->items->first()?->name ?? $candidate->transaction_no,
            'dueDate' => $dueAt->format('F j, Y'),
            'amount' => (float) $candidate->grand_total,
            'kind' => $isRenewal ? 'renewal' : 'payment',
            'headline' => $isRenewal
                ? 'Renewal Reminder: Invoice ' . $dueDaysLabel
                : ($daysUntilDue < 0 ? 'Payment Overdue' : 'Payment Due Soon'),
            'buttonLabel' => $isRenewal ? 'Pay & Renew' : 'Pay Now',
            'daysUntilDue' => $daysUntilDue,
            'canPay' => $canPay,
        ];
    }

    private function isRenewalReminder(SalesTransaction $transaction): bool
    {
        $linkedService = CustomerService::query()
            ->where('customer_id', $transaction->customer_id)
            ->where('sales_transaction_id', $transaction->id)
            ->first();

        if ($linkedService && in_array($linkedService->status, ['Active', 'Expired'], true)) {
            return true;
        }

        $itemName = $transaction->items->first()?->name;
        if (!$itemName) {
            return false;
        }

        return CustomerService::query()
            ->where('customer_id', $transaction->customer_id)
            ->where('title', $itemName)
            ->where('status', 'Active')
            ->whereNotNull('renew_at')
            ->where('renew_at', '<=', now()->addDays(7))
            ->exists();
    }

    private function generateTicketNo(): string
    {
        $prefix = 'TKT-' . now()->format('ymd') . '-';
        $latest = CustomerSupportTicket::query()
            ->where('ticket_no', 'like', $prefix . '%')
            ->orderByDesc('ticket_no')
            ->value('ticket_no');

        $next = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
