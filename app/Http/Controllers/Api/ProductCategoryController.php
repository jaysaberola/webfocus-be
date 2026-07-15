<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use App\Models\ProductCategory;

class ProductCategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = ProductCategory::query()
            ->when($request->search, function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%");
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        // Accept a few common field names from different frontends
        $normalizedName = $request->input('name')
            ?? $request->input('product_category_name')
            ?? $request->input('category_name');

        if (!is_null($normalizedName) && $request->input('name') !== $normalizedName) {
            $request->merge(['name' => $normalizedName]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $slug = $this->uniqueSlug(Str::slug($validated['name']));

        $category = ProductCategory::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Category created successfully',
            'data' => $category,
        ], 201);
    }

    public function show(ProductCategory $category)
    {
        return response()->json([
            'data' => $category,
        ]);
    }

    public function update(Request $request, ProductCategory $category)
    {
        // Accept a few common field names from different frontends
        $normalizedName = $request->input('name')
            ?? $request->input('product_category_name')
            ?? $request->input('category_name');

        if (!is_null($normalizedName) && $request->input('name') !== $normalizedName) {
            $request->merge(['name' => $normalizedName]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $slug = $this->uniqueSlug(Str::slug($validated['name']), $category->id);

        $category->update([
            'name' => $validated['name'],
            'slug' => $slug,
        ]);

        return response()->json([
            'message' => 'Category updated successfully',
            'data' => $category,
        ]);
    }

    public function destroy(ProductCategory $category)
    {
        $category->delete();

        return response()->json([
            'message' => 'Category deleted',
        ]);
    }

    public function restore(Request $request)
    {
        $ids = $request->input('ids') ?? $request->input('id');

        if (is_null($ids)) {
            return response()->json(['message' => 'No id(s) provided'], 422);
        }

        $ids = is_array($ids) ? $ids : [$ids];

        $categories = ProductCategory::withTrashed()->whereIn('id', $ids)->get();

        $restoredCount = 0;
        foreach ($categories as $c) {
            if ($c->trashed()) {
                $c->restore();
                $restoredCount++;
            }
        }

        return response()->json([
            'message' => 'Categories restored',
            'restored_count' => $restoredCount,
        ]);
    }

    public function restoreById(int $id)
    {
        $category = ProductCategory::withTrashed()->findOrFail($id);

        if ($category->trashed()) {
            $category->restore();
        }

        return response()->json([
            'message' => 'Category restored',
            'data' => $category,
        ]);
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = $base;
        $i = 2;

        while (ProductCategory::where('slug', $slug)
            ->when(!is_null($ignoreId), function ($q) use ($ignoreId) {
                $q->where('id', '!=', $ignoreId);
            })
            ->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
