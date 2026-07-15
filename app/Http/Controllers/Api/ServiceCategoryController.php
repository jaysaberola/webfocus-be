<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;

class ServiceCategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = ServiceCategory::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->input('search') . '%');
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $name = $this->resolveName($request);
        if (!is_null($name)) {
            $request->merge(['name' => $name]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
            'position' => ['nullable', 'integer'],
            'order' => ['nullable', 'integer'],
        ]);

        $sortOrder = $this->resolveSortOrder($request, $validated);

        $category = ServiceCategory::create([
            'name' => $validated['name'],
            'sort_order' => $sortOrder ?? 0,
            'position' => array_key_exists('position', $validated)
                ? $validated['position']
                : $sortOrder,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category created',
            'data' => [
                'id' => $category->id,
                'name' => $category->name,
            ],
        ], 201);
    }

    public function show(ServiceCategory $category)
    {
        return response()->json([
            'data' => $category,
        ]);
    }

    public function update(Request $request, ServiceCategory $category)
    {
        $name = $this->resolveName($request);
        if (!is_null($name)) {
            $request->merge(['name' => $name]);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
            'position' => ['nullable', 'integer'],
            'order' => ['nullable', 'integer'],
        ]);

        $updates = [];

        if (array_key_exists('name', $validated)) {
            $updates['name'] = $validated['name'];
        }

        $sortOrder = $this->resolveSortOrder($request, $validated);
        if (!is_null($sortOrder)) {
            $updates['sort_order'] = $sortOrder;
        }

        if ($request->has('position')) {
            $updates['position'] = $validated['position'];
        } elseif (!is_null($sortOrder) && !array_key_exists('position', $updates)) {
            $updates['position'] = $category->position;
        }

        if (empty($updates)) {
            return response()->json([
                'success' => true,
                'message' => 'Category updated',
                'data' => $category,
            ]);
        }

        $category->update($updates);

        return response()->json([
            'success' => true,
            'message' => 'Category updated',
            'data' => $category->fresh(),
        ]);
    }

    public function destroy(ServiceCategory $category)
    {
        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted',
        ]);
    }

    public function handlePostAction(Request $request, ServiceCategory $category)
    {
        $method = strtoupper((string) $request->input('_method', ''));

        if (in_array($method, ['PUT', 'PATCH'], true)) {
            $request->setMethod('PUT');

            return $this->update($request, $category);
        }

        if ($method === 'DELETE') {
            return $this->destroy($category);
        }

        return response()->json([
            'message' => 'Unsupported method override',
        ], 405);
    }

    private function resolveName(Request $request): ?string
    {
        return $request->input('name')
            ?? $request->input('title')
            ?? $request->input('service_category_name');
    }

    private function resolveSortOrder(Request $request, array $validated): ?int
    {
        if (array_key_exists('sort_order', $validated) && !is_null($validated['sort_order'])) {
            return (int) $validated['sort_order'];
        }

        if (array_key_exists('order', $validated) && !is_null($validated['order'])) {
            return (int) $validated['order'];
        }

        if ($request->has('position') && !is_null($validated['position'] ?? null)) {
            return (int) $validated['position'];
        }

        return null;
    }
}
