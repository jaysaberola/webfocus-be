<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WebDesignQuotationMail;
use App\Models\CustomerNotification;
use App\Models\SalesTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerPortalProvisioner;
use App\Services\PaynamicsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
                'items',
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
            $query->latest('transacted_at')->latest('updated_at')->paginate($perPage)
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
            ]),
        ]);
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
            'transacted_at' => $request->input(
                'transacted_at',
                optional($salesTransaction->transacted_at)?->format('Y-m-d')
            ),
        ]);

        $validated = $this->validatedPayload($request, $salesTransaction->id);
        $items = $validated['items'] ?? null;
        unset($validated['items']);
        $validated = $this->normalizeCustomer($validated);
        $validated['transaction_no'] =
            ($validated['transaction_no'] ?? null) ?: $salesTransaction->transaction_no;
        $validated['subtotal'] = $this->calculateSubtotal($validated, $items);
        $validated['grand_total'] = $this->calculateGrandTotal($validated);

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
            || $items->contains(function ($item) {
                $type = strtolower((string) ($item->item_type ?? ''));
                $name = strtolower((string) ($item->name ?? ''));

                return str_contains($type, 'web_design')
                    || str_contains($type, 'webdesign')
                    || str_contains($name, 'web design')
                    || str_contains($name, 'starter launch')
                    || str_contains($name, 'professional corporate')
                    || str_contains($name, 'e-commerce');
            });

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
                    . ' was sent to Sales / Customer Care for pricing.',
                'type' => 'general',
                'action_url' => '/public/dashboard?tab=orders',
            ]);
        }

        $staffIds = User::role(['sales_admin', 'admin', 'customer_care', 'finance_admin'])
            ->where('is_active', true)
            ->pluck('id');

        $referenceKey = 'admin:webdesign-quotation:' . $transaction->id;
        foreach ($staffIds as $staffId) {
            CustomerNotification::query()->updateOrCreate(
                [
                    'customer_id' => $staffId,
                    'reference_key' => $referenceKey,
                ],
                [
                    'title' => 'Web Design Quotation Request',
                    'body' => "{$clientLabel} submitted a Pending Quotation checkout"
                        . ($itemNames ? " for {$itemNames}" : '')
                        . " ({$transaction->transaction_no}). Set the package price in Transactions.",
                    'type' => 'web_design_quotation',
                    'action_url' => '/public/commerce-admin?tab=transactions',
                ]
            );
        }

        $to = Setting::query()->value('email') ?: 'customercare@webfocus.ph';

        try {
            Mail::to($to)->send(new WebDesignQuotationMail($transaction));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
