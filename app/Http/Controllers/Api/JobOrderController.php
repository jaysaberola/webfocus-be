<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class JobOrderController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->integer('per_page', 10);

        $query = JobOrder::query()
            ->with(['customer:id,fname,mname,lname,email', 'items', 'payments'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->where(function ($qq) use ($term) {
                    $qq->where('jo_no', 'like', "%{$term}%")
                        ->orWhere('customer_name', 'like', "%{$term}%")
                        ->orWhereHas('items', fn($itemQuery) => $itemQuery->where('name', 'like', "%{$term}%"));
                });
            })
            ->when($request->filled('source'), fn($q) => $q->where('source', $request->input('source')))
            ->when($request->filled('delivery_type'), fn($q) => $q->where('delivery_type', $request->input('delivery_type')))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('order_date_from'), fn($q) => $q->whereDate('order_date', '>=', $request->input('order_date_from')))
            ->when($request->filled('order_date_to'), fn($q) => $q->whereDate('order_date', '<=', $request->input('order_date_to')))
            ->when($request->filled('date_needed_from'), fn($q) => $q->whereDate('date_needed', '>=', $request->input('date_needed_from')))
            ->when($request->filled('date_needed_to'), fn($q) => $q->whereDate('date_needed', '<=', $request->input('date_needed_to')));

        return response()->json($query->latest('order_date')->latest('updated_at')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $this->validatedPayload($request);
        $validated = $this->normalizeCustomer($validated);

        $jobOrder = DB::transaction(function () use ($request, $validated) {
            $items = $this->normalizeItems($validated['items'] ?? []);
            $payments = $this->normalizePayments($request, $validated['payments'] ?? []);
            $totals = $this->calculateTotals($items, $payments, (float) ($validated['delivery_charge'] ?? 0));

            $jobOrder = JobOrder::create([
                ...$this->jobOrderAttributes($validated),
                'jo_no' => ($validated['jo_no'] ?? null) ?: $this->generateJoNo(),
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'total' => $totals['total'],
                'total_quantity' => $totals['total_quantity'],
                'user_id' => $request->user()?->id,
            ]);

            $jobOrder->items()->createMany($items);
            $jobOrder->payments()->createMany($payments);

            return $jobOrder;
        });

        return response()->json([
            'message' => 'Job order created successfully',
            'data' => $jobOrder->load(['customer:id,fname,mname,lname,email', 'items', 'payments']),
        ], 201);
    }

    public function show(JobOrder $jobOrder)
    {
        return response()->json([
            'data' => $jobOrder->load(['customer:id,fname,mname,lname,email', 'items', 'payments']),
        ]);
    }

    public function update(Request $request, JobOrder $jobOrder)
    {
        $validated = $this->validatedPayload($request, $jobOrder->id);
        $validated = $this->normalizeCustomer($validated);

        DB::transaction(function () use ($request, $validated, $jobOrder) {
            $items = $this->normalizeItems($validated['items'] ?? []);
            $payments = $this->normalizePayments($request, $validated['payments'] ?? [], $jobOrder);
            $totals = $this->calculateTotals($items, $payments, (float) ($validated['delivery_charge'] ?? 0));

            $jobOrder->update([
                ...$this->jobOrderAttributes($validated),
                'jo_no' => ($validated['jo_no'] ?? null) ?: $jobOrder->jo_no,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'total' => $totals['total'],
                'total_quantity' => $totals['total_quantity'],
            ]);

            $jobOrder->items()->delete();
            $jobOrder->payments()->delete();
            $jobOrder->items()->createMany($items);
            $jobOrder->payments()->createMany($payments);
        });

        return response()->json([
            'message' => 'Job order updated successfully',
            'data' => $jobOrder->fresh()->load(['customer:id,fname,mname,lname,email', 'items', 'payments']),
        ]);
    }

    public function destroy(JobOrder $jobOrder)
    {
        $jobOrder->delete();

        return response()->json(['message' => 'Job order deleted successfully']);
    }

    private function validatedPayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'jo_no' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('job_orders', 'jo_no')->ignore($ignoreId),
            ],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_type' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_email' => ['nullable', 'email', 'max:150'],
            'customer_contact' => ['nullable', 'string', 'max:60'],
            'source' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'string', 'max:60'],
            'order_date' => ['nullable', 'date'],
            'date_needed' => ['nullable', 'date'],
            'delivery_type' => ['nullable', 'string', 'max:80'],
            'delivery_location' => ['nullable', 'string', 'max:150'],
            'delivery_address' => ['nullable', 'string'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.item_type' => ['required', 'string', 'max:30'],
            'items.*.name' => ['required', 'string', 'max:180'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.is_miscellaneous' => ['nullable', 'boolean'],
            'payments' => ['nullable', 'array'],
            'payments.*.payment_method' => ['required_with:payments', 'string', 'max:80'],
            'payments.*.amount' => ['nullable', 'numeric', 'min:0'],
            'payments.*.remarks' => ['nullable', 'string'],
            'payments.*.attachment_path' => ['nullable', 'string', 'max:255'],
            'payments.*.attachment' => ['nullable', 'file', 'max:10240'],
        ]);
    }

    private function normalizeCustomer(array $payload): array
    {
        $payload['customer_type'] = $payload['customer_type'] ?? 'existing';

        if (!empty($payload['customer_id'])) {
            $customer = User::find($payload['customer_id']);
            if ($customer) {
                $payload['customer_name'] = ($payload['customer_name'] ?? null) ?: $customer->full_name;
                $payload['customer_email'] = ($payload['customer_email'] ?? null) ?: $customer->email;
                $payload['customer_contact'] = ($payload['customer_contact'] ?? null) ?: ($customer->mobile ?? $customer->phone);
            }
        }

        return $payload;
    }

    private function jobOrderAttributes(array $payload): array
    {
        return [
            'customer_id' => $payload['customer_id'] ?? null,
            'customer_type' => $payload['customer_type'] ?? 'existing',
            'customer_name' => $payload['customer_name'] ?? null,
            'customer_email' => $payload['customer_email'] ?? null,
            'customer_contact' => $payload['customer_contact'] ?? null,
            'source' => $payload['source'] ?? null,
            'category' => $payload['category'] ?? 'Order',
            'status' => $payload['status'] ?? 'Open Date',
            'order_date' => $payload['order_date'] ?? now(),
            'date_needed' => $payload['date_needed'] ?? null,
            'delivery_type' => $payload['delivery_type'] ?? null,
            'delivery_location' => $payload['delivery_location'] ?? null,
            'delivery_address' => $payload['delivery_address'] ?? null,
            'delivery_charge' => $payload['delivery_charge'] ?? 0,
            'remarks' => $payload['remarks'] ?? null,
        ];
    }

    private function normalizeItems(array $items): array
    {
        return collect($items)->map(function ($item) {
            $price = (float) ($item['price'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 1);

            return [
                'product_id' => $item['product_id'] ?? null,
                'item_type' => $item['item_type'] ?? 'product',
                'name' => $item['name'],
                'price' => $price,
                'quantity' => $quantity,
                'total_price' => $price * $quantity,
                'is_miscellaneous' => (bool) ($item['is_miscellaneous'] ?? false),
            ];
        })->values()->all();
    }

    private function normalizePayments(Request $request, array $payments, ?JobOrder $jobOrder = null): array
    {
        return collect($payments)->map(function ($payment, $index) use ($request, $jobOrder) {
            $attachmentPath = $payment['attachment_path'] ?? null;
            $file = $request->file("payments.{$index}.attachment");

            if ($file) {
                if ($jobOrder && $attachmentPath && Storage::disk('public')->exists($attachmentPath)) {
                    Storage::disk('public')->delete($attachmentPath);
                }
                $attachmentPath = $file->store('job-order-payments', 'public');
            }

            return [
                'payment_method' => $payment['payment_method'],
                'amount' => (float) ($payment['amount'] ?? 0),
                'remarks' => $payment['remarks'] ?? null,
                'attachment_path' => $attachmentPath,
            ];
        })->values()->all();
    }

    private function calculateTotals(array $items, array $payments, float $deliveryCharge): array
    {
        $itemsSubtotal = collect($items)->sum('total_price');
        $discountTotal = collect($payments)
            ->filter(fn($payment) => str_contains(strtolower($payment['payment_method']), 'discount'))
            ->sum('amount');
        $subtotal = $itemsSubtotal + $deliveryCharge;

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'total' => max(0, $subtotal - $discountTotal),
            'total_quantity' => collect($items)->sum('quantity'),
        ];
    }

    private function generateJoNo(): string
    {
        $prefix = 'JO' . now()->format('Ymd');
        $next = JobOrder::where('jo_no', 'like', $prefix . '%')->count() + 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
