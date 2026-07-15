<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            ->when($request->filled('transacted_at_from'), fn($q) => $q->whereDate('transacted_at', '>=', $request->input('transacted_at_from')))
            ->when($request->filled('transacted_at_to'), fn($q) => $q->whereDate('transacted_at', '<=', $request->input('transacted_at_to')));

        return response()->json($query->latest('transacted_at')->latest('updated_at')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $this->validatedPayload($request);
        $items = $validated['items'] ?? [];
        unset($validated['items']);
        $validated = $this->normalizeCustomer($validated);
        $validated['transaction_no'] = ($validated['transaction_no'] ?? null) ?: $this->generateTransactionNo();
        $validated['subtotal'] = $this->calculateSubtotal($validated, $items);
        $validated['grand_total'] = $this->calculateGrandTotal($validated);
        $validated['user_id'] = $request->user()?->id;

        $transaction = DB::transaction(function () use ($validated, $items) {
            $transaction = SalesTransaction::create($validated);
            $this->syncItems($transaction, $items);

            return $transaction;
        });

        return response()->json([
            'message' => 'Sales transaction created successfully',
            'data' => $transaction->load(['customer:id,fname,lname,email', 'items']),
        ], 201);
    }

    public function show(SalesTransaction $salesTransaction)
    {
        return response()->json([
            'data' => $salesTransaction->load(['customer:id,fname,lname,email', 'items']),
        ]);
    }

    public function update(Request $request, SalesTransaction $salesTransaction)
    {
        $validated = $this->validatedPayload($request, $salesTransaction->id);
        $items = $validated['items'] ?? null;
        unset($validated['items']);
        $validated = $this->normalizeCustomer($validated);
        $validated['transaction_no'] = ($validated['transaction_no'] ?? null) ?: $salesTransaction->transaction_no;
        $validated['subtotal'] = $this->calculateSubtotal($validated, $items);
        $validated['grand_total'] = $this->calculateGrandTotal($validated);

        DB::transaction(function () use ($salesTransaction, $validated, $items) {
            $salesTransaction->update($validated);
            if (is_array($items)) {
                $this->syncItems($salesTransaction, $items);
            }
        });

        return response()->json([
            'message' => 'Sales transaction updated successfully',
            'data' => $salesTransaction->fresh()->load(['customer:id,fname,lname,email', 'items']),
        ]);
    }

    public function destroy(SalesTransaction $salesTransaction)
    {
        $salesTransaction->delete();

        return response()->json(['message' => 'Sales transaction deleted successfully']);
    }

    private function validatedPayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'transaction_no' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('sales_transactions', 'transaction_no')->ignore($ignoreId),
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
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.name' => ['required_with:items', 'string', 'max:255'],
            'items.*.item_type' => ['nullable', 'string', 'max:50'],
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
                $payload['customer_name'] = ($payload['customer_name'] ?? null) ?: $customer->full_name;
                $payload['customer_email'] = ($payload['customer_email'] ?? null) ?: $customer->email;
            }
        }

        return $payload;
    }

    private function calculateGrandTotal(array $payload): float
    {
        return max(0, (float) ($payload['subtotal'] ?? 0)
            - (float) ($payload['discount_total'] ?? 0)
            + (float) ($payload['tax_total'] ?? 0)
            + (float) ($payload['shipping_total'] ?? 0));
    }

    private function calculateSubtotal(array $payload, ?array $items): float
    {
        if (!is_array($items) || count($items) === 0) {
            return (float) ($payload['subtotal'] ?? 0);
        }

        return collect($items)->sum(function ($item) {
            return $this->calculateItemTotal($item);
        });
    }

    private function syncItems(SalesTransaction $transaction, array $items): void
    {
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
        if (array_key_exists('total_price', $item) && $item['total_price'] !== null) {
            return (float) $item['total_price'];
        }

        return (float) ($item['price'] ?? 0) * (float) ($item['quantity'] ?? 1);
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
}
