<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WebDesignQuotationMail;
use App\Models\CustomerNotification;
use App\Models\SalesTransaction;
use App\Models\SalesTransactionProposal;
use App\Models\Setting;
use App\Models\User;
use App\Services\CommerceStaffNotifier;
use App\Services\CustomerPortalProvisioner;
use App\Services\PaynamicsService;
use App\Support\StorageUrl;
use App\Support\WebDesignQuotation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class SalesTransactionController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->integer('per_page', 10);

        $query = SalesTransaction::query()
            ->with([
                'customer:id,fname,lname,email',
                'user:id,fname,lname,email',
                'items',
                'proposals',
            ])
            ->when($request->search, function ($q) use ($request) {
                $term = $request->search;
                $q->where(function ($qq) use ($term) {
                    $qq->where('transaction_no', 'like', "%{$term}%")
                       ->orWhere('customer_name', 'like', "%{$term}%")
                       ->orWhere('customer_email', 'like', "%{$term}%")
                       ->orWhereHas('items', fn($itemQuery) => $itemQuery->where('name', 'like', "%{$term}%"));
                });
            })
            ->when($request->customer_id, fn($q) => $q->where('customer_id', $request->customer_id))
            ->when($request->payment_status, fn($q) => $q->where('payment_status', $request->payment_status))
            ->when($request->order_status, fn($q) => $q->where('order_status', $request->order_status))
            ->when(
                $request->filled('transacted_at_from'),
                fn($q) => $q->whereDate('transacted_at', '>=', $request->input('transacted_at_from'))
            )
            ->when(
                $request->filled('transacted_at_to'),
                fn($q) => $q->whereDate('transacted_at', '<=', $request->input('transacted_at_to'))
            );

        return response()->json(
            $query->latest('created_at')->latest('id')->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        $validated = $this->validatedPayload($request);
        $items = $validated['items'] ?? [];
        unset($validated['items']);
        $validated = $this->normalizeCustomer($validated);
        $validated['transaction_no'] =
            ($validated['transaction_no'] ?? null) ?: $this->generateTransactionNo();
        $validated['subtotal'] = $this->calculateSubtotal($validated, $items);
        $validated['grand_total'] = $this->calculateGrandTotal($validated);
        $validated['transacted_at'] = $this->normalizeTransactedAt(
            $validated['transacted_at'] ?? null
        );
        $validated['user_id'] = $request->user()?->id;

        $transaction = DB::transaction(function () use ($validated, $items) {
            $transaction = SalesTransaction::create($validated);
            $this->syncItems($transaction, $items);

            return $transaction;
        });

        if ($transaction->customer_id) {
            app(CustomerPortalProvisioner::class)
                ->provisionFromTransaction($transaction->fresh(['items']));
        }

        $this->notifyCustomerCareIfWebDesignQuotation($transaction->fresh(['items']));

        return response()->json([
            'message' => 'Sales transaction created successfully',
            'data' => $transaction->load([
                'customer:id,fname,lname,email',
                'user:id,fname,lname,email',
                'items',
            ]),
        ], 201);
    }

    /**
     * Creates a pending order and returns a Paynamics-hosted payment URL.
     *
     * No card, bank, e-wallet, or payment instrument data is collected or
     * stored by this application.
     */
    public function checkoutWithPaynamics(
        Request $request,
        PaynamicsService $paynamics
    ) {
        /** @var User|null $customer */
        $customer = $request->user();
        abort_unless($customer, 401);
        abort_unless(
            $customer->hasRole('customer'),
            403,
            'Only client accounts can add items to the cart and complete checkout.'
        );

        /*
         * Validate the profile before creating an order so missing mandatory
         * Paynamics billing fields do not leave an unusable pending order.
         */
        $paynamics->assertCustomerProfile($customer);

        $request->validate([
            'items' => ['required', 'array', 'min:1'],
        ]);

        $validated = $this->validatedPayload($request);
        $items = $validated['items'] ?? [];
        unset($validated['items']);

        $validated['customer_id'] = $customer->id;
        $validated['customer_name'] = trim(
            "{$customer->fname} {$customer->lname}"
        );
        $validated['customer_email'] = $customer->email;
        $validated['transaction_no'] =
            ($validated['transaction_no'] ?? null) ?: $this->generateTransactionNo();
        $validated['subtotal'] = $this->calculateSubtotal($validated, $items);
        $validated['grand_total'] = $this->calculateGrandTotal($validated);
        $validated['transacted_at'] = $this->normalizeTransactedAt(
            $validated['transacted_at'] ?? null
        );
        $validated['payment_status'] = 'pending';
        $validated['order_status'] = 'pending';
        $validated['user_id'] = $customer->id;

        $transaction = DB::transaction(function () use ($validated, $items) {
            $transaction = SalesTransaction::create($validated);
            $this->syncItems($transaction, $items);

            return $transaction->fresh(['items']);
        });

        try {
            $gateway = $paynamics->initiate(
                $transaction,
                $customer,
                $request->ip(),
                $request->userAgent()
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage(),
                'data' => [
                    'transaction_id' => $transaction->id,
                    'transaction_no' => $transaction->transaction_no,
                ],
            ], 502);
        }

        return response()->json([
            'message' => 'Redirecting to the Paynamics payment portal.',
            'data' => $transaction->load([
                'customer:id,fname,lname,email',
                'items',
            ]),
            'paynamics' => $gateway,
        ], 201);
    }

    public function show(SalesTransaction $salesTransaction)
    {
        return response()->json([
            'data' => $salesTransaction->load([
                'customer:id,fname,lname,email',
                'items',
                'proposals',
            ]),
        ]);
    }

    public function proposals(SalesTransaction $salesTransaction)
    {
        $rows = $salesTransaction->proposals()->latest()->get()->map(
            fn (SalesTransactionProposal $proposal) => $this->mapProposal($proposal)
        );

        return response()->json(['data' => $rows]);
    }

    public function uploadProposal(Request $request, SalesTransaction $salesTransaction)
    {
        $this->assertSalesCanManageWebDesign($request);
        abort_unless(WebDesignQuotation::isWebDesign($salesTransaction), 422, 'This order is not a web design quotation.');
        abort_unless(WebDesignQuotation::isPendingQuotation($salesTransaction), 422, 'Payment has already been requested for this quotation.');

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,png,jpg,jpeg', 'max:10240'],
        ]);

        $file = $validated['file'];
        $path = $file->store('web-design-proposals/' . $salesTransaction->id, 'public');

        $proposal = SalesTransactionProposal::create([
            'sales_transaction_id' => $salesTransaction->id,
            'uploaded_by' => $request->user()?->id,
            'version' => 1,
            'kind' => 'proposal',
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
        ]);

        $salesTransaction->update([
            'notes' => WebDesignQuotation::appendMarker(
                $salesTransaction->notes,
                WebDesignQuotation::PROPOSAL_SUBMITTED
            ),
        ]);

        if ($salesTransaction->customer_id) {
            CustomerNotification::create([
                'customer_id' => $salesTransaction->customer_id,
                'title' => 'Proposal Quotation Ready',
                'body' => 'A proposal quotation for '
                    . $salesTransaction->transaction_no
                    . ' is ready to review. Download it, sign it, and re-upload the signed copy.',
                'type' => 'billing',
                'action_url' => '/public/dashboard?tab=billing',
            ]);
        }

        return response()->json([
            'message' => 'Proposal quotation uploaded. The client can now download and sign it.',
            'data' => $this->mapProposal($proposal),
        ], 201);
    }

    public function proceedPayment(Request $request, SalesTransaction $salesTransaction)
    {
        $this->assertSalesCanManageWebDesign($request);
        abort_unless(WebDesignQuotation::isWebDesign($salesTransaction), 422, 'This order is not a web design quotation.');
        abort_unless(
            WebDesignQuotation::hasMarker($salesTransaction, WebDesignQuotation::PROPOSAL_SIGNED),
            422,
            'The client must sign and re-upload the proposal before payment can proceed.'
        );
        abort_if(
            (float) $salesTransaction->grand_total <= 0,
            422,
            'Set the web design price before requesting payment.'
        );

        $salesTransaction->update([
            'notes' => WebDesignQuotation::appendMarker(
                $salesTransaction->notes,
                WebDesignQuotation::PAYMENT_REQUESTED
            ),
            'payment_status' => 'pending',
        ]);

        if ($salesTransaction->customer_id) {
            CustomerNotification::create([
                'customer_id' => $salesTransaction->customer_id,
                'title' => 'Upload Proof of Payment',
                'body' => 'Please upload your proof of payment for invoice INV-'
                    . $salesTransaction->transaction_no
                    . '. Attach a receipt file from Billing.',
                'type' => 'billing',
                'action_url' => '/public/dashboard?tab=billing',
            ]);
        }

        return response()->json([
            'message' => 'Payment requested. The client has been notified to upload proof of payment.',
            'data' => $salesTransaction->fresh(['items', 'proposals', 'user', 'customer']),
        ]);
    }

    private function mapProposal(SalesTransactionProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'version' => (int) $proposal->version,
            'kind' => $proposal->kind,
            'fileName' => $proposal->file_name,
            'fileUrl' => StorageUrl::publicAsset($proposal->file_path),
            'uploadedAt' => optional($proposal->created_at)->format('Y-m-d H:i'),
        ];
    }

    public function update(
        Request $request,
        SalesTransaction $salesTransaction
    ) {
        $request->merge([
            'transaction_no' => $request->input(
                'transaction_no',
                $salesTransaction->transaction_no
            ),
            'customer_id' => $request->input(
                'customer_id',
                $salesTransaction->customer_id
            ),
            'customer_name' => $request->input(
                'customer_name',
                $salesTransaction->customer_name
            ),
            'customer_email' => $request->input(
                'customer_email',
                $salesTransaction->customer_email
            ),
            'subtotal' => $request->input(
                'subtotal',
                $salesTransaction->subtotal
            ),
            'discount_total' => $request->input(
                'discount_total',
                $salesTransaction->discount_total
            ),
            'tax_total' => $request->input(
                'tax_total',
                $salesTransaction->tax_total
            ),
            'shipping_total' => $request->input(
                'shipping_total',
                $salesTransaction->shipping_total
            ),
            'payment_status' => $request->input(
                'payment_status',
                $salesTransaction->payment_status
            ),
            'order_status' => $request->input(
                'order_status',
                $salesTransaction->order_status
            ),
            'notes' => $request->has('notes')
                ? $request->input('notes')
                : $salesTransaction->notes,
        ]);

        if ($request->exists('transacted_at')) {
            $request->merge([
                'transacted_at' => $request->input('transacted_at'),
            ]);
        }

        $validated = $this->validatedPayload($request, $salesTransaction->id);
        $items = $validated['items'] ?? null;
        unset($validated['items']);
        $validated = $this->normalizeCustomer($validated);
        $validated['transaction_no'] =
            ($validated['transaction_no'] ?? null) ?: $salesTransaction->transaction_no;
        $validated['subtotal'] = $this->calculateSubtotal($validated, $items);
        $validated['grand_total'] = $this->calculateGrandTotal($validated);
        if (array_key_exists('transacted_at', $validated)) {
            $validated['transacted_at'] = $this->normalizeTransactedAt(
                $validated['transacted_at']
            );
        }

        DB::transaction(function () use ($salesTransaction, $validated, $items) {
            $salesTransaction->update($validated);
            if (is_array($items)) {
                $this->syncItems($salesTransaction, $items);
            }
        });

        if ($salesTransaction->customer_id) {
            app(CustomerPortalProvisioner::class)
                ->refreshServicesFromTransaction(
                    $salesTransaction->fresh(['items'])
                );
        }

        return response()->json([
            'message' => 'Sales transaction updated successfully',
            'data' => $salesTransaction->fresh()->load([
                'customer:id,fname,lname,email',
                'user:id,fname,lname,email',
                'items',
            ]),
        ]);
    }

    public function destroy(SalesTransaction $salesTransaction)
    {
        $salesTransaction->delete();

        return response()->json([
            'message' => 'Sales transaction deleted successfully',
        ]);
    }

    private function assertSalesCanManageWebDesign(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->hasAnyRole(['sales_staff', 'sales_admin', 'admin']),
            403,
            'Only Sales Staff can upload proposals or request payment for web design orders.'
        );
    }

    private function validatedPayload(
        Request $request,
        ?int $ignoreId = null
    ): array {
        return $request->validate([
            'transaction_no' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('sales_transactions', 'transaction_no')
                    ->ignore($ignoreId),
            ],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'shipping_total' => ['nullable', 'numeric', 'min:0'],
            'payment_status' => ['required', 'string', 'max:50'],
            'order_status' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'transacted_at' => ['nullable', 'date'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => [
                'nullable',
                'integer',
                'exists:products,id',
            ],
            'items.*.name' => [
                'required_with:items',
                'string',
                'max:255',
            ],
            'items.*.item_type' => [
                'nullable',
                'string',
                'max:50',
            ],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_price' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function normalizeCustomer(array $payload): array
    {
        if (!empty($payload['customer_id'])) {
            $customer = User::find($payload['customer_id']);
            if ($customer) {
                $payload['customer_name'] =
                    ($payload['customer_name'] ?? null) ?: $customer->full_name;
                $payload['customer_email'] =
                    ($payload['customer_email'] ?? null) ?: $customer->email;
            }
        }

        return $payload;
    }

    private function calculateGrandTotal(array $payload): float
    {
        return max(
            0,
            (float) ($payload['subtotal'] ?? 0)
                - (float) ($payload['discount_total'] ?? 0)
                + (float) ($payload['tax_total'] ?? 0)
                + (float) ($payload['shipping_total'] ?? 0)
        );
    }

    private function calculateSubtotal(
        array $payload,
        ?array $items
    ): float {
        if (!is_array($items) || count($items) === 0) {
            return (float) ($payload['subtotal'] ?? 0);
        }

        return collect($items)->sum(function ($item) {
            return $this->calculateItemTotal($item);
        });
    }

    private function syncItems(
        SalesTransaction $transaction,
        array $items
    ): void {
        $transaction->items()->delete();

        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);

            $transaction->items()->create([
                'product_id' => $item['product_id'] ?? null,
                'name' => $item['name'],
                'item_type' => $item['item_type'] ?? 'product',
                'price' => $price,
                'quantity' => $quantity,
                'total_price' => $this->calculateItemTotal($item),
            ]);
        }
    }

    private function calculateItemTotal(array $item): float
    {
        if (
            array_key_exists('total_price', $item) &&
            $item['total_price'] !== null
        ) {
            return (float) $item['total_price'];
        }

        return (float) ($item['price'] ?? 0)
            * (float) ($item['quantity'] ?? 1);
    }

    private function normalizeTransactedAt(mixed $value): Carbon
    {
        if ($value === null || $value === '') {
            return now();
        }

        // Browser sends UTC ISO (toISOString); store wall-clock in app timezone (Asia/Manila).
        return Carbon::parse($value)->timezone(config('app.timezone', 'Asia/Manila'));
    }

    private function generateTransactionNo(): string
    {
        $prefix = 'ST-' . now()->format('Ymd') . '-';
        $latest = SalesTransaction::withTrashed()
            ->where('transaction_no', 'like', $prefix . '%')
            ->orderByDesc('transaction_no')
            ->value('transaction_no');

        $latestNumber = 0;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $latestNumber = (int) $matches[1];
        }

        $next = $latestNumber + 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function notifyCustomerCareIfWebDesignQuotation(SalesTransaction $transaction): void
    {
        $notes = (string) ($transaction->notes ?? '');
        $items = $transaction->items ?? collect();
        $isQuotation = str_contains($notes, 'Pricing: Pending Quotation')
            || (
                ! str_contains($notes, 'Pricing: Set by Sales')
                && (float) $transaction->grand_total <= 0
                && $items->contains(function ($item) {
                    $type = strtolower((string) ($item->item_type ?? ''));
                    $name = strtolower((string) ($item->name ?? ''));

                    return str_contains($type, 'web_design')
                        || str_contains($type, 'webdesign')
                        || str_contains($name, 'web design')
                        || str_contains($name, 'starter launch')
                        || str_contains($name, 'professional corporate')
                        || str_contains($name, 'e-commerce');
                })
            );

        if (! $isQuotation) {
            return;
        }

        $itemNames = $items->pluck('name')->filter()->take(3)->implode(', ');
        $clientLabel = $transaction->customer_name
            ?: ($transaction->customer?->full_name ?? 'Client');

        if ($transaction->customer_id) {
            CustomerNotification::create([
                'customer_id' => $transaction->customer_id,
                'title' => 'Web Design Quotation Submitted',
                'body' => 'Your web design quotation request '
                    . $transaction->transaction_no
                    . ($itemNames ? " ({$itemNames})" : '')
                    . ' was sent to Sales for pricing.',
                'type' => 'general',
                'action_url' => '/public/dashboard?tab=orders',
            ]);
        }

        app(CommerceStaffNotifier::class)->notifyOwnerAndRoles(
            $transaction->customer_id ? (int) $transaction->customer_id : null,
            ['sales_admin', 'sales_staff'],
            'admin:webdesign-quotation:' . $transaction->id,
            'Web Design Quotation Request',
            "{$clientLabel} submitted a Pending Quotation checkout"
                . ($itemNames ? " for {$itemNames}" : '')
                . " ({$transaction->transaction_no}). Assign a Sales Staff member and upload the proposal quotation.",
            'web_design_quotation',
            '/public/commerce-admin?tab=orders',
            false,
        );

        $to = Setting::query()->value('email') ?: 'customercare@webfocus.ph';

        try {
            Mail::to($to)->send(new WebDesignQuotationMail($transaction));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
