<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->integer('per_page', 10);

        $query = Product::query()->with('category:id,name,slug');

        // trashed params acceptance (same style as pages/articles)
        $onlyTrashed = $request->boolean('only_trashed')
            || $request->boolean('onlyDeleted')
            || $request->boolean('trashed')
            || $request->boolean('show_deleted');

        $withTrashed = $request->boolean('with_trashed')
            || $request->boolean('withDeleted')
            || $request->boolean('include_deleted');

        if ($onlyTrashed) {
            $query = $query->onlyTrashed();
        } elseif ($withTrashed) {
            $query = $query->withTrashed();
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }
        if ($request->filled('category')) {
            $term = $request->input('category');
            $query->whereHas('category', function ($categoryQuery) use ($term) {
                $categoryQuery->where('name', 'like', '%' . $term . '%')
                    ->orWhere('slug', 'like', '%' . $term . '%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->input('name') . '%');
        }
        if ($request->filled('price')) {
            $query->where('price', (float) $request->input('price'));
        }

        $query->when($request->filled('search'), function ($q) use ($request) {
            $term = $request->search;
            $q->where(function ($qq) use ($term) {
                $qq->where('name', 'like', '%' . $term . '%')
                    ->orWhere('description', 'like', '%' . $term . '%')
                    ->orWhere('slug', 'like', '%' . $term . '%');
            });
        });

        $products = $query->latest('updated_at')->paginate($perPage);

        // Normalize image_url to a full URL (optional, but convenient)
        $products->getCollection()->transform(function ($p) {
            $arr = $p->toArray();
            if (!empty($p->image_url)) {
                $arr['image_url_full'] = url(Storage::url($p->image_url));
            }
            return $arr;
        });

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string'],

            // Accept either an id or a name via `category`
            'category' => ['nullable'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],

            'image' => ['nullable', 'image', 'max:4096'],
            'status' => ['nullable', 'string'],
        ]);

        $categoryId = null;

        if (!empty($validated['category_id'])) {
            $categoryId = (int) $validated['category_id'];
        } elseif ($request->filled('category')) {
            $raw = $request->input('category');

            if (is_numeric($raw)) {
                $candidateId = (int) $raw;
                $exists = ProductCategory::where('id', $candidateId)->exists();
                if (!$exists) {
                    return response()->json([
                        'message' => 'Invalid category id',
                        'errors' => [
                            'category' => ['The selected category does not exist.'],
                        ],
                    ], 422);
                }

                $categoryId = $candidateId;
            } else {
                $name = trim((string) $raw);
                if ($name !== '') {
                    $slug = Str::slug($name);
                    $cat = ProductCategory::where('slug', $slug)->first();

                    if (!$cat) {
                        $cat = ProductCategory::create([
                            'name' => $name,
                            'slug' => $this->uniqueCategorySlug($slug),
                            'user_id' => $request->user()->id,
                        ]);
                    }

                    $categoryId = $cat->id;
                }
            }
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('products/images', 'public');
        }

        $slug = $this->uniqueProductSlug(Str::slug($validated['name']));

        $product = Product::create([
            'category_id' => $categoryId,
            'slug' => $slug,
            'name' => $validated['name'],
            'price' => $validated['price'] ?? null,
            'description' => $validated['description'] ?? null,
            'image_url' => $imagePath,
            'status' => $validated['status'] ?? 'active',
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Product created successfully',
            'data' => $product,
        ], 201);
    }

    public function show(Product $product)
    {
        $product->load('category:id,name,slug');

        return response()->json([
            'data' => $product,
        ]);
    }

    public function update(Request $request, Product $product)
    {
        // PHP can't parse multipart on PUT — accept POST + _method=PUT spoof
        if ($request->isMethod('POST') && $request->input('_method') === 'PUT') {
            $request->setMethod('PUT');
        }

        $validated = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            'price'       => ['nullable', 'numeric'],
            'description' => ['nullable', 'string'],
            'category'    => ['nullable'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'image'       => ['nullable', 'image', 'max:4096'],
            'status'      => ['nullable', 'string'],
        ]);

        $updates = [
            'price'       => $validated['price'] ?? $product->price,
            'description' => array_key_exists('description', $validated)
                ? ($validated['description'] ?? null)
                : $product->description,
            'status'      => $validated['status'] ?? $product->status,
        ];

        if (array_key_exists('name', $validated)) {
            $updates['name'] = $validated['name'];
            $updates['slug'] = $this->uniqueProductSlug(Str::slug($validated['name']), $product->id);
        }

        // Category resolution
        if (!empty($validated['category_id'])) {
            $updates['category_id'] = (int) $validated['category_id'];
        } elseif ($request->filled('category')) {
            $raw = $request->input('category');

            if (is_numeric($raw)) {
                $candidateId = (int) $raw;
                if (!ProductCategory::where('id', $candidateId)->exists()) {
                    return response()->json([
                        'message' => 'Invalid category id',
                        'errors'  => ['category' => ['The selected category does not exist.']],
                    ], 422);
                }
                $updates['category_id'] = $candidateId;
            } else {
                $name = trim((string) $raw);
                if ($name !== '') {
                    $slug = Str::slug($name);
                    $cat  = ProductCategory::where('slug', $slug)->first();
                    if (!$cat) {
                        $cat = ProductCategory::create([
                            'name'    => $name,
                            'slug'    => $this->uniqueCategorySlug($slug),
                            'user_id' => $request->user()->id,
                        ]);
                    }
                    $updates['category_id'] = $cat->id;
                }
            }
        }

        // Image update
        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            if (!empty($product->image_url) && Storage::disk('public')->exists($product->image_url)) {
                Storage::disk('public')->delete($product->image_url);
            }
            $updates['image_url'] = $request->file('image')->store('products/images', 'public');
        }

        $product->update($updates);

        return response()->json([
            'message' => 'Product updated successfully',
            'data'    => $product->fresh()->load('category:id,name,slug'),
        ]);
    }

    private function uniqueProductSlug(string $base, ?int $excludeId = null): string
    {
        $slug = $base;
        $i    = 2;

        while (
            Product::where('slug', $slug)
                ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    public function destroy(Product $product)
    {
        // Soft delete + set status for frontend convenience (matches pages/articles style)
        try {
            $product->update(['status' => 'deleted']);
        } catch (\Exception $e) {
            // ignore
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted',
        ]);
    }

    public function restore(Request $request)
    {
        $ids = $request->input('ids') ?? $request->input('id');

        if (is_null($ids)) {
            return response()->json(['message' => 'No id(s) provided'], 422);
        }

        $ids = is_array($ids) ? $ids : [$ids];

        $products = Product::withTrashed()->whereIn('id', $ids)->get();

        $restoredCount = 0;
        foreach ($products as $p) {
            if ($p->trashed()) {
                $p->restore();
                if ($p->status === 'deleted') {
                    try {
                        $p->update(['status' => 'active']);
                    } catch (\Exception $e) {
                        // ignore
                    }
                }
                $restoredCount++;
            }
        }

        return response()->json([
            'message' => 'Products restored',
            'restored_count' => $restoredCount,
        ]);
    }

    public function restoreById(int $id)
    {
        $product = Product::withTrashed()->findOrFail($id);

        if ($product->trashed()) {
            $product->restore();
        }

        if ($product->status === 'deleted') {
            try {
                $product->update(['status' => 'active']);
            } catch (\Exception $e) {
                // ignore
            }
        }

        return response()->json([
            'message' => 'Product restored',
            'data' => $product,
        ]);
    }

    private function uniqueCategorySlug(string $base): string
    {
        $slug = $base;
        $i = 2;

        while (ProductCategory::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
