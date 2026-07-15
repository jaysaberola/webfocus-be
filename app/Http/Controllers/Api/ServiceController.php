<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min($request->integer('per_page', 10), 100));

        $query = Service::query()->with('category:id,name,sort_order,position');

        $onlyTrashed = $request->boolean('only_trashed')
            || $request->boolean('onlyDeleted')
            || $request->boolean('trashed')
            || $request->boolean('show_deleted');

        $withTrashed = $request->boolean('with_trashed')
            || $request->boolean('withDeleted')
            || $request->boolean('include_deleted');

        if ($onlyTrashed) {
            $query->onlyTrashed();
        } elseif ($withTrashed) {
            $query->withTrashed();
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where(function ($serviceQuery) use ($term) {
                $serviceQuery->where('name', 'like', '%' . $term . '%')
                    ->orWhere('description', 'like', '%' . $term . '%');
            });
        }

        $services = $query->latest('updated_at')->paginate($perPage);

        return response()->json([
            'data' => $services->getCollection()->map(function (Service $service) {
                return $service->toArray();
            })->values(),
            'meta' => [
                'total' => $services->total(),
                'per_page' => $services->perPage(),
                'current_page' => $services->currentPage(),
                'last_page' => $services->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
            'is_active' => ['nullable', 'boolean'],
            'category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);

        [$status, $isActive] = $this->normalizeStatus(
            $validated['status'] ?? null,
            $validated['is_active'] ?? null,
            'active',
            true
        );

        $service = Service::create([
            'name' => $validated['name'],
            'price' => $validated['price'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => $status,
            'is_active' => $isActive,
            'category_id' => $validated['category_id'] ?? null,
            'image' => $request->hasFile('image')
                ? $request->file('image')->store('services', 'public')
                : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service created',
            'data' => $service->fresh()->load('category:id,name,sort_order,position'),
        ], 201);
    }

    public function show(Service $service)
    {
        return response()->json([
            'data' => $service->load('category:id,name,sort_order,position'),
        ]);
    }

    public function update(Request $request, Service $service)
    {
        if ($request->isMethod('POST') && in_array(strtoupper((string) $request->input('_method')), ['PUT', 'PATCH'], true)) {
            $request->setMethod('PUT');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
            'is_active' => ['nullable', 'boolean'],
            'category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);

        $updates = [];

        if (array_key_exists('name', $validated)) {
            $updates['name'] = $validated['name'];
        }

        if (array_key_exists('price', $validated)) {
            $updates['price'] = $validated['price'];
        }

        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'];
        }

        if (array_key_exists('category_id', $validated) || $request->has('category_id')) {
            $updates['category_id'] = $validated['category_id'] ?? null;
        }

        if (array_key_exists('status', $validated) || array_key_exists('is_active', $validated) || $request->has('is_active')) {
            [$status, $isActive] = $this->normalizeStatus(
                $validated['status'] ?? null,
                $validated['is_active'] ?? null,
                $service->status,
                (bool) $service->is_active
            );

            $updates['status'] = $status;
            $updates['is_active'] = $isActive;
        }

        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            if (!empty($service->image) && Storage::disk('public')->exists($service->image)) {
                Storage::disk('public')->delete($service->image);
            }

            $updates['image'] = $request->file('image')->store('services', 'public');
        }

        $service->update($updates);

        return response()->json([
            'success' => true,
            'message' => 'Service updated',
            'data' => $service->fresh()->load('category:id,name,sort_order,position'),
        ]);
    }

    public function destroy(Service $service)
    {
        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service deleted',
        ]);
    }

    public function restoreById(int $id)
    {
        $service = Service::withTrashed()->findOrFail($id);

        if ($service->trashed()) {
            $service->restore();
        }

        return response()->json([
            'success' => true,
            'message' => 'Service restored',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        Service::whereIn('id', $validated['ids'])->delete();

        return response()->json([
            'success' => true,
            'message' => 'Services deleted',
        ]);
    }

    public function bulkStatus(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $isActive = $validated['status'] === 'active';

        Service::whereIn('id', $validated['ids'])->update([
            'status' => $validated['status'],
            'is_active' => $isActive,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status updated',
        ]);
    }

    public function handlePostAction(Request $request, Service $service)
    {
        $method = strtoupper((string) $request->input('_method', ''));

        if (in_array($method, ['PUT', 'PATCH'], true)) {
            $request->setMethod('PUT');

            return $this->update($request, $service);
        }

        if ($method === 'DELETE') {
            return $this->destroy($service);
        }

        return response()->json([
            'message' => 'Unsupported method override',
        ], 405);
    }

    private function normalizeStatus(?string $status, mixed $isActive, string $fallbackStatus, bool $fallbackIsActive): array
    {
        if (!is_null($status)) {
            return [$status, $status === 'active'];
        }

        if (!is_null($isActive)) {
            $normalizedIsActive = filter_var($isActive, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $normalizedIsActive = is_null($normalizedIsActive) ? (bool) $isActive : $normalizedIsActive;

            return [$normalizedIsActive ? 'active' : 'inactive', $normalizedIsActive];
        }

        return [$fallbackStatus, $fallbackIsActive];
    }
}
