<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Models\CustomerPaymentProof;
use App\Models\CustomerProfileChangeRequest;
use App\Models\CustomerService;
use App\Models\CustomerSupportTicket;
use App\Models\SalesTransaction;
use App\Models\SalesTransactionItem;
use App\Models\SalesTransactionProposal;
use App\Models\User;
use App\Support\TransactionLabelResolver;
use App\Support\StorageUrl;
use App\Support\WebDesignQuotation;
use App\Services\CommerceStaffNotifier;
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
            ->with('salesTransaction')
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

        $activeServices = $services->filter(
            fn (CustomerService $service) => CustomerPortalProvisioner::isActiveStatus(
                CustomerPortalProvisioner::resolveStatusForService($service)
            )
        );
        $unpaid = $transactions->filter(fn ($row) => !in_array(strtolower((string) $row->payment_status), ['paid', 'completed', 'success'], true));
        $nextUnpaid = $unpaid->sortBy(fn ($row) => $row->transacted_at)->first();

        $stats = [
            'activeNodes' => $activeServices->count() . ' Active',
            'activeNodesDetail' => $activeServices->count() > 0
                ? $activeServices->first()?->plan . ' High Availability'
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
            ->with('salesTransaction')
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
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->map(fn (SalesTransaction $row) => $this->mapOrder($row))
            ->values();

        return response()->json(['data' => $orders]);
    }

    public function cancelOrder(Request $request, SalesTransaction $salesTransaction)
    {
        $customer = $this->resolveCustomer($request);
        abort_unless((int) $salesTransaction->customer_id === (int) $customer->id, 403);

        $salesTransaction->loadMissing('items');
        $status = CustomerPortalProvisioner::resolveServiceStatus($salesTransaction);
        abort_if(
            $status === CustomerPortalProvisioner::STATUS_CANCELLED,
            422,
            'This order is already cancelled.'
        );
        abort_if(
            in_array($status, [
                CustomerPortalProvisioner::STATUS_ACTIVE,
                CustomerPortalProvisioner::STATUS_PROVISIONING,
            ], true),
            422,
            'Paid or active orders cannot be cancelled here. Please contact support.'
        );

        $notes = trim((string) $salesTransaction->notes);
        $cancelLine = 'Customer cancelled this order from My Orders on ' . now()->toDateString() . '.';
        $salesTransaction->update([
            'order_status' => 'cancelled',
            'payment_status' => in_array(strtolower((string) $salesTransaction->payment_status), ['paid', 'completed', 'success'], true)
                ? $salesTransaction->payment_status
                : 'cancelled',
            'notes' => $notes === '' ? $cancelLine : $notes . "\n" . $cancelLine,
        ]);

        CustomerService::query()
            ->where('sales_transaction_id', $salesTransaction->id)
            ->update(['status' => CustomerPortalProvisioner::STATUS_CANCELLED]);

        $orderNo = $salesTransaction->transaction_no;
        $clientLabel = trim(($customer->mname ?: '') !== ''
            ? (string) $customer->mname
            : trim(($customer->fname ?? '') . ' ' . ($customer->lname ?? ''))) ?: ($customer->email ?? 'Client');

        CustomerNotification::create([
            'customer_id' => $customer->id,
            'title' => 'Order Cancelled',
            'body' => 'You cancelled order ' . $orderNo . '.',
            'type' => 'order',
            'action_url' => '/public/dashboard?tab=orders',
        ]);

        app(CommerceStaffNotifier::class)->notifyOwnerAndRoles(
            (int) $customer->id,
            ['finance_admin', 'sales_admin', 'sales_staff', 'admin', 'customer_care'],
            'admin:order-cancelled:' . $salesTransaction->id,
            'Customer Cancelled an Order',
            "{$clientLabel} cancelled order {$orderNo}.",
            'order',
            '/public/commerce-admin?tab=orders',
        );

        app(CustomerPortalNotificationSync::class)->syncForCustomer($customer->id);

        return response()->json([
            'message' => 'Order cancelled.',
            'data' => $this->mapOrder($salesTransaction->fresh('items')),
        ]);
    }

    public function billing(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        $transactions = SalesTransaction::query()
            ->where('customer_id', $customer->id)
            ->with(['items', 'proposals'])
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('transacted_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('transacted_at', '<=', $request->input('date_to')))
            ->latest('created_at')
            ->latest('id')
            ->get();

        $allTransactions = SalesTransaction::query()
            ->where('customer_id', $customer->id)
            ->with(['items', 'proposals'])
            ->latest('created_at')
            ->latest('id')
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
        abort_if(
            WebDesignQuotation::isPendingQuotation($transaction),
            422,
            'This web design order is still Pending Quotation. Wait for Sales to request payment before uploading proof.'
        );

        $invoiceId = $this->invoiceId($transaction);

        $verifiedExists = CustomerPaymentProof::query()
            ->where('customer_id', $customer->id)
            ->where(function ($query) use ($transaction, $invoiceId) {
                $query->where('sales_transaction_id', $transaction->id)
                    ->orWhere('invoice_id', $invoiceId);
            })
            ->where('status', 'Verified & Credited')
            ->exists();

        abort_if($verifiedExists, 422, 'This invoice already has a verified payment proof.');

        $pendingProofs = CustomerPaymentProof::query()
            ->where('customer_id', $customer->id)
            ->where(function ($query) use ($transaction, $invoiceId) {
                $query->where('sales_transaction_id', $transaction->id)
                    ->orWhere('invoice_id', $invoiceId);
            })
            ->where('status', 'Pending Review')
            ->latest('id')
            ->get();

        $file = $request->file('receipt');
        $path = $file->store('customer-payment-proofs/' . $customer->id, 'public');

        if ($pendingProofs->isNotEmpty()) {
            $proof = $pendingProofs->first();

            foreach ($pendingProofs->skip(1) as $duplicate) {
                $this->deletePaymentProofFile($duplicate->file_path);
                $duplicate->delete();
            }

            $this->deletePaymentProofFile($proof->file_path);

            $proof->update([
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'notes' => $validated['notes'] ?? $proof->notes,
                'status' => 'Pending Review',
            ]);

            $message = 'Payment proof updated. Your previous pending submission for this invoice was replaced.';
            $statusCode = 200;
        } else {
            $proof = CustomerPaymentProof::create([
                'customer_id' => $customer->id,
                'sales_transaction_id' => $transaction->id,
                'proof_no' => $this->generatePaymentProofNo(),
                'invoice_id' => $invoiceId,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'status' => 'Pending Review',
                'notes' => $validated['notes'] ?? null,
            ]);

            $message = 'Payment proof uploaded successfully';
            $statusCode = 201;
        }

        CustomerNotification::create([
            'customer_id' => $customer->id,
            'title' => $statusCode === 200 ? 'Payment Proof Updated' : 'Payment Proof Uploaded',
            'body' => 'We received your payment proof for ' . $proof->invoice_id . '. Our billing team will verify it shortly.',
            'type' => 'billing',
            'action_url' => '/public/dashboard?tab=billing',
        ]);

        $clientLabel = trim(($customer->mname ?: '') !== ''
            ? (string) $customer->mname
            : trim(($customer->fname ?? '') . ' ' . ($customer->lname ?? ''))) ?: ($customer->email ?? 'Client');

        app(CommerceStaffNotifier::class)->notifyOwnerAndRoles(
            (int) $customer->id,
            ['finance_admin', 'sales_admin', 'sales_staff', 'admin', 'customer_care'],
            'admin:payment-proof:' . $proof->id,
            'Payment Proof Pending Review',
            "{$clientLabel} uploaded payment proof {$proof->proof_no} for {$proof->invoice_id}. Review it in Approvals.",
            'payment_proof',
            '/public/commerce-admin?tab=approvals',
        );

        if ($transaction->user_id && (int) $transaction->user_id !== (int) $customer->id) {
            CustomerNotification::query()->updateOrCreate(
                [
                    'customer_id' => $transaction->user_id,
                    'reference_key' => 'admin:payment-proof:' . $proof->id,
                ],
                [
                    'title' => 'Client Uploaded Proof of Payment',
                    'body' => "{$clientLabel} uploaded payment proof {$proof->proof_no} for {$proof->invoice_id}. Review it in Approvals.",
                    'type' => 'payment_proof',
                    'action_url' => '/public/commerce-admin?tab=approvals',
                    'read_at' => null,
                ]
            );
        }

        app(CustomerPortalNotificationSync::class)->syncForCustomer($customer->id);

        return response()->json([
            'message' => $message,
            'data' => $this->mapPaymentProof($proof->fresh()),
        ], $statusCode);
    }

    public function deletePaymentProof(Request $request, CustomerPaymentProof $paymentProof)
    {
        $customer = $this->resolveCustomer($request);
        abort_unless((int) $paymentProof->customer_id === (int) $customer->id, 403);
        abort_if($paymentProof->status === 'Verified & Credited', 422, 'Verified payment proofs cannot be deleted.');

        $this->deletePaymentProofFile($paymentProof->file_path);

        $paymentProof->delete();

        return response()->json(['message' => 'Payment proof deleted']);
    }

    public function listProposals(Request $request, SalesTransaction $salesTransaction)
    {
        $customer = $this->resolveCustomer($request);
        abort_unless((int) $salesTransaction->customer_id === (int) $customer->id, 403);

        $rows = $salesTransaction->proposals()->latest()->get()->map(fn (SalesTransactionProposal $proposal) => [
            'id' => $proposal->id,
            'version' => (int) $proposal->version,
            'kind' => $proposal->kind,
            'fileName' => $proposal->file_name,
            'fileUrl' => StorageUrl::publicAsset($proposal->file_path),
            'uploadedAt' => optional($proposal->created_at)->format('Y-m-d H:i'),
        ]);

        return response()->json(['data' => $rows]);
    }

    public function uploadSignedProposal(Request $request)
    {
        $customer = $this->resolveCustomer($request);
        $validated = $request->validate([
            'invoice_id' => ['required', 'string', 'max:120'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,png,jpg,jpeg', 'max:10240'],
        ]);

        $transaction = $this->resolveInvoiceTransaction($customer, $validated['invoice_id']);
        abort_unless(WebDesignQuotation::isWebDesign($transaction), 422, 'This invoice is not a web design quotation.');
        abort_unless(
            WebDesignQuotation::hasMarker($transaction, WebDesignQuotation::PROPOSAL_SUBMITTED),
            422,
            'No proposal quotation is available to sign yet.'
        );
        abort_if(
            WebDesignQuotation::isPaymentRequested($transaction),
            422,
            'Payment has already been requested for this quotation.'
        );

        $file = $validated['file'];
        $path = $file->store('web-design-proposals/' . $transaction->id, 'public');

        $proposal = SalesTransactionProposal::create([
            'sales_transaction_id' => $transaction->id,
            'uploaded_by' => $customer->id,
            'version' => 2,
            'kind' => 'signed',
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
        ]);

        $transaction->update([
            'notes' => WebDesignQuotation::appendMarker(
                $transaction->notes,
                WebDesignQuotation::PROPOSAL_SIGNED
            ),
        ]);

        $assigneeId = $transaction->client_owner_id ?: $transaction->user_id;
        app(CommerceStaffNotifier::class)->notifyOwnerAndRoles(
            (int) $customer->id,
            ['sales_admin'],
            'admin:webdesign-signed:' . $transaction->id,
            'Signed Proposal Uploaded',
            trim(($customer->mname ?: $customer->full_name) ?: 'Client')
                . ' uploaded the signed proposal for '
                . $transaction->transaction_no
                . '. You can now proceed to payment.',
            'web_design_quotation',
            '/public/commerce-admin?tab=orders',
            false,
        );

        if ($assigneeId) {
            CustomerNotification::query()->updateOrCreate(
                [
                    'customer_id' => $assigneeId,
                    'reference_key' => 'admin:webdesign-signed:' . $transaction->id,
                ],
                [
                    'title' => 'Signed Proposal Uploaded',
                    'body' => 'The client signed and re-uploaded the proposal for '
                        . $transaction->transaction_no
                        . '. Click Proceed Payment in Orders.',
                    'type' => 'web_design_quotation',
                    'action_url' => '/public/commerce-admin?tab=orders',
                    'read_at' => null,
                ]
            );
        }

        return response()->json([
            'message' => 'Signed proposal uploaded. Sales will review it and request payment.',
            'data' => [
                'id' => $proposal->id,
                'version' => 2,
                'kind' => 'signed',
                'fileName' => $proposal->file_name,
                'fileUrl' => StorageUrl::publicAsset($proposal->file_path),
            ],
        ], 201);
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

        $clientLabel = trim(($customer->mname ?: '') !== ''
            ? (string) $customer->mname
            : trim(($customer->fname ?? '') . ' ' . ($customer->lname ?? ''))) ?: ($customer->email ?? 'Client');

        app(CommerceStaffNotifier::class)->notifyOwnerAndRoles(
            (int) $customer->id,
            ['customer_care', 'technical_support', 'admin'],
            'admin:support-ticket:' . $ticket->id,
            'New Support Ticket',
            "{$clientLabel} submitted ticket {$ticket->ticket_no}: {$ticket->subject}",
            'support_ticket',
            '/public/commerce-admin?tab=helpdesk',
        );

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

    public function pendingProfileChange(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        $pending = CustomerProfileChangeRequest::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'Pending Review')
            ->latest()
            ->first();

        return response()->json([
            'data' => $pending ? $this->mapProfileChangeRequest($pending) : null,
        ]);
    }

    public function submitProfileChange(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        $validated = $request->validate([
            'fname' => ['required', 'string', 'max:255'],
            'lname' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:60'],
            'mname' => ['nullable', 'string', 'max:255'],
            'address_country' => ['nullable', 'string', 'max:255'],
            'address_region' => ['nullable', 'string', 'max:255'],
            'address_province' => ['nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:255'],
            'address_street' => ['nullable', 'string', 'max:500'],
            'address_zip' => ['nullable', 'string', 'max:30'],
            'summary' => ['nullable', 'string', 'max:500'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:1024'],
        ]);

        $hasPending = CustomerProfileChangeRequest::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'Pending Review')
            ->exists();

        abort_if($hasPending, 422, 'You already have a profile change request awaiting admin review.');

        $requestedPayload = [
            'fname' => $validated['fname'],
            'lname' => $validated['lname'],
            'mobile' => $validated['mobile'] ?? null,
            'mname' => $validated['mname'] ?? null,
            'address_country' => $validated['address_country'] ?? null,
            'address_region' => $validated['address_region'] ?? null,
            'address_province' => $validated['address_province'] ?? null,
            'address_city' => $validated['address_city'] ?? null,
            'address_street' => $validated['address_street'] ?? null,
            'address_zip' => $validated['address_zip'] ?? null,
        ];

        $currentSnapshot = [
            'fname' => $customer->fname,
            'lname' => $customer->lname,
            'mobile' => $customer->mobile,
            'mname' => $customer->mname,
            'address_country' => $customer->address_country,
            'address_region' => $customer->address_region,
            'address_province' => $customer->address_province,
            'address_city' => $customer->address_city,
            'address_street' => $customer->address_street,
            'address_zip' => $customer->address_zip,
            'avatar' => $customer->avatar,
        ];

        if ($request->hasFile('avatar')) {
            $requestedPayload['avatar_path'] = $request->file('avatar')->store(
                'profile-change-requests/' . $customer->id,
                'public'
            );
        }

        $hasProfileFieldChanges = collect($requestedPayload)
            ->except(['avatar_path'])
            ->some(function ($value, $key) use ($currentSnapshot) {
                return trim((string) ($value ?? '')) !== trim((string) ($currentSnapshot[$key] ?? ''));
            });

        abort_unless(
            $hasProfileFieldChanges || !empty($requestedPayload['avatar_path']),
            422,
            'No profile changes were detected.'
        );

        $summary = $validated['summary'] ?? null;
        if (!$summary) {
            if (!empty($requestedPayload['avatar_path']) && !$hasProfileFieldChanges) {
                $summary = 'Update profile photo for ' . $customer->email;
            } else {
                $summary = 'Update representative, phone, company, and billing address for ' . $customer->email;
            }
        }

        $changeRequest = CustomerProfileChangeRequest::create([
            'customer_id' => $customer->id,
            'request_no' => $this->generateProfileChangeRequestNo(),
            'status' => 'Pending Review',
            'summary' => $summary,
            'requested_payload' => $requestedPayload,
            'current_snapshot' => $currentSnapshot,
        ]);

        CustomerNotification::create([
            'customer_id' => $customer->id,
            'title' => 'Profile Change Submitted',
            'body' => 'Your profile update request ' . $changeRequest->request_no . ' is pending admin approval.',
            'type' => 'account',
            'action_url' => '/public/dashboard?tab=account',
        ]);

        $clientLabel = trim(($customer->mname ?: '') !== ''
            ? (string) $customer->mname
            : trim(($customer->fname ?? '') . ' ' . ($customer->lname ?? ''))) ?: ($customer->email ?? 'Client');

        app(CommerceStaffNotifier::class)->notifyOwnerAndRoles(
            (int) $customer->id,
            ['customer_care', 'admin', 'sales_admin'],
            'admin:profile-change:' . $changeRequest->id,
            'Profile Change Pending Review',
            "{$clientLabel} submitted profile change {$changeRequest->request_no}.",
            'profile_change',
            '/public/commerce-admin?tab=approvals',
        );

        app(CustomerPortalNotificationSync::class)->syncForCustomer($customer->id);

        return response()->json([
            'message' => 'Profile changes sent for admin approval.',
            'data' => $this->mapProfileChangeRequest($changeRequest),
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
        $status = CustomerPortalProvisioner::resolveStatusForService($service);

        return [
            'id' => (string) $service->id,
            'title' => $service->title,
            'category' => $service->category,
            'plan' => $service->plan,
            'renewLabel' => $service->renew_label,
            'renewDate' => CustomerPortalProvisioner::formatRenewDate($service->renew_at),
            'renewNote' => $service->renew_note ?: CustomerPortalProvisioner::formatRenewNote($service->renew_at),
            'status' => $status,
        ];
    }

    private function mapOrder(SalesTransaction $row): array
    {
        $firstItem = $row->items->first();
        $items = $row->items->map(fn ($item) => [
            'name' => TransactionLabelResolver::serviceCategory($item->name, $item->item_type),
            'detail' => $item->name,
            'price' => (float) $item->total_price,
        ])->values()->all();

        $planLabel = TransactionLabelResolver::planLabel($row->items, $firstItem?->name);
        $status = CustomerPortalProvisioner::resolveServiceStatus($row);
        $paid = in_array(strtolower((string) $row->payment_status), ['paid', 'completed', 'success'], true);
        $domain = $row->items
            ->pluck('name')
            ->first(fn ($name) => TransactionLabelResolver::looksLikeDomain($name));

        return [
            'id' => $row->transaction_no,
            'recordId' => $row->id,
            'invoiceId' => $this->invoiceId($row),
            'serviceName' => TransactionLabelResolver::serviceCategoryFromItems($row->items),
            'plan' => $planLabel,
            'domain' => $domain,
            'date' => TransactionLabelResolver::issuedDateFrom($row->transacted_at),
            'createdAt' => optional($row->created_at)?->toIso8601String(),
            'dueDate' => TransactionLabelResolver::dueDateFrom($row->transacted_at),
            'expiredDate' => TransactionLabelResolver::dueDateFrom($row->transacted_at),
            'total' => WebDesignQuotation::displayAmount($row),
            'status' => $status,
            'paymentStatus' => $paid ? 'Paid' : (strtolower((string) $row->payment_status) === 'cancelled' ? 'Cancelled' : 'Unpaid'),
            'gateway' => $this->extractPaymentMethod($row->notes),
            'canCancel' => in_array($status, [
                CustomerPortalProvisioner::STATUS_PENDING,
                CustomerPortalProvisioner::STATUS_AWAITING_APPROVAL,
            ], true),
            'items' => $items,
        ];
    }

    private function mapInvoice(SalesTransaction $row): array
    {
        $paid = in_array(strtolower((string) $row->payment_status), ['paid', 'completed', 'success'], true);
        $firstItem = $row->items->first();
        $planLabel = TransactionLabelResolver::planLabel($row->items, $firstItem?->name ?? $row->transaction_no);
        $dueAt = $row->transacted_at?->copy()->addDays(30);
        $daysUntilDue = $dueAt
            ? now()->startOfDay()->diffInDays($dueAt->copy()->startOfDay(), false)
            : null;
        $paymentSubmitted = $this->isPaymentSubmitted($row);
        $pendingQuotation = WebDesignQuotation::isPendingQuotation($row);
        $proposalSubmitted = WebDesignQuotation::hasMarker($row, WebDesignQuotation::PROPOSAL_SUBMITTED);
        $proposalSigned = WebDesignQuotation::hasMarker($row, WebDesignQuotation::PROPOSAL_SIGNED);
        $canPay = !$paid && !$paymentSubmitted && !$pendingQuotation && $daysUntilDue !== null && $daysUntilDue <= 7;

        if ($paid) {
            $status = 'Paid';
        } elseif ($pendingQuotation) {
            $status = 'Pending Quotation';
        } elseif ($paymentSubmitted) {
            $status = 'Awaiting Approval';
        } elseif (WebDesignQuotation::isPaymentRequested($row)) {
            $status = 'Pending Payment';
        } elseif ($daysUntilDue !== null && $daysUntilDue < 0) {
            $status = 'Overdue';
        } elseif ($daysUntilDue !== null && $daysUntilDue <= 7) {
            $status = 'Payment Due';
        } else {
            $status = 'Pending Payment';
        }

        $proposal = $row->relationLoaded('proposals')
            ? $row->proposals->where('kind', 'proposal')->sortByDesc('id')->first()
            : $row->proposals()->where('kind', 'proposal')->latest()->first();

        return [
            'id' => $this->invoiceId($row),
            'transactionNo' => $row->transaction_no,
            'date' => optional($row->transacted_at)->format('Y-m-d'),
            'createdAt' => optional($row->created_at)?->toIso8601String(),
            'due' => optional($dueAt)->format('Y-m-d'),
            'amount' => WebDesignQuotation::displayAmount($row),
            'status' => $status,
            'canPay' => $canPay,
            'daysUntilDue' => $daysUntilDue,
            'serviceName' => TransactionLabelResolver::serviceCategoryFromItems($row->items),
            'plan' => $planLabel,
            'subscription' => $planLabel,
            'items' => TransactionLabelResolver::serviceCategoryFromItems($row->items),
            'pendingQuotation' => $pendingQuotation,
            'proposalSubmitted' => $proposalSubmitted,
            'proposalSigned' => $proposalSigned,
            'proposalFileName' => $proposal?->file_name,
            'proposalUrl' => StorageUrl::publicAsset($proposal?->file_path),
            'actionsDisabled' => $pendingQuotation && ! $proposalSubmitted,
        ];
    }

    private function mapPaymentProof(CustomerPaymentProof $proof): array
    {
        return [
            'id' => $proof->proof_no,
            'recordId' => $proof->id,
            'invoiceId' => $proof->invoice_id,
            'fileName' => $proof->file_name,
            'fileUrl' => StorageUrl::publicAsset($proof->file_path),
            'date' => optional($proof->created_at)->format('Y-m-d'),
            'status' => $proof->status,
            'notes' => $proof->notes,
        ];
    }

    private function mapProfileChangeRequest(CustomerProfileChangeRequest $request): array
    {
        $avatarPath = $request->requested_payload['avatar_path'] ?? null;

        return [
            'id' => $request->id,
            'reference' => $request->request_no,
            'submittedAt' => optional($request->created_at)->format('M j, Y g:i A'),
            'status' => $request->status === 'Pending Review'
                ? 'Pending Admin Review'
                : $request->status,
            'summary' => $request->summary,
            'requestedPayload' => $request->requested_payload,
            'currentSnapshot' => $request->current_snapshot,
            'pendingAvatarUrl' => StorageUrl::publicAsset($avatarPath),
            'currentAvatarUrl' => StorageUrl::publicAsset($request->current_snapshot['avatar'] ?? null),
        ];
    }

    private function generateProfileChangeRequestNo(): string
    {
        $prefix = 'APR-' . now()->format('Ymd') . '-';
        $latest = CustomerProfileChangeRequest::query()
            ->where('request_no', 'like', $prefix . '%')
            ->orderByDesc('request_no')
            ->value('request_no');

        $next = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function deletePaymentProofFile(?string $filePath): void
    {
        if ($filePath && Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }
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
        $pending = $transactions->filter(function (SalesTransaction $row) {
            if (in_array(strtolower((string) $row->payment_status), ['paid', 'completed', 'success'], true)) {
                return false;
            }

            return ! WebDesignQuotation::isPendingQuotation($row);
        });

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
            'amount' => WebDesignQuotation::displayAmount($candidate),
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
            ->whereIn('status', ['Active', CustomerPortalProvisioner::STATUS_ACTIVE])
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
