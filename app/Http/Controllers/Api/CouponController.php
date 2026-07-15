<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->integer('per_page', 10);

        $query = Coupon::query()
            ->when($request->search, function ($q) use ($request) {
                $term = $request->search;
                $q->where(function ($qq) use ($term) {
                    $qq->where('code', 'like', "%{$term}%")
                       ->orWhere('name', 'like', "%{$term}%")
                       ->orWhere('description', 'like', "%{$term}%");
                });
            })
            ->when($request->status, fn($q) => $q->where('status', $request->status));

        return response()->json($query->latest('updated_at')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100', 'unique:coupons,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'discount_type' => ['required', Rule::in(['fixed', 'percent'])],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $coupon = Coupon::create([
            ...$validated,
            'code' => strtoupper($validated['code']),
            'status' => $validated['status'] ?? 'active',
            'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Coupon created successfully',
            'data' => $coupon,
        ], 201);
    }

    public function show(Coupon $coupon)
    {
        return response()->json(['data' => $coupon]);
    }

    public function update(Request $request, Coupon $coupon)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100', Rule::unique('coupons', 'code')->ignore($coupon->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'discount_type' => ['required', Rule::in(['fixed', 'percent'])],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:0'],
            'used_count' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $coupon->update([
            ...$validated,
            'code' => strtoupper($validated['code']),
            'status' => $validated['status'] ?? $coupon->status,
        ]);

        return response()->json([
            'message' => 'Coupon updated successfully',
            'data' => $coupon->fresh(),
        ]);
    }

    public function destroy(Coupon $coupon)
    {
        $coupon->delete();

        return response()->json(['message' => 'Coupon deleted successfully']);
    }
}
