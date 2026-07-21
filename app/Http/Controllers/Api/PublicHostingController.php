<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceAddon;
use Illuminate\Http\Request;

class PublicHostingController extends Controller
{
    public function plans(Request $request)
    {
        $query = Service::query()
            ->with('category:id,name,slug,sort_order')
            ->where('status', 'active')
            ->where('is_active', true)
            ->where(function ($serviceQuery) {
                $serviceQuery->whereJsonContains('metadata->item_type', 'plan')
                    ->orWhere('slug', 'like', 'host-%');
            });

        if ($request->filled('type')) {
            $query->whereJsonContains('metadata->plan_type', $request->input('type'));
        }

        $plans = $query
            ->orderBy('category_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Service $service) => $this->formatPlan($service))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

    public function addons(Request $request)
    {
        $query = ServiceAddon::query()->active()->orderBy('sort_order')->orderBy('name');

        if ($request->filled('type')) {
            $type = $request->input('type');

            if ($request->boolean('universal_only')) {
                $query->where('plan_type', 'universal');
            } elseif ($request->boolean('type_only')) {
                $query->where('plan_type', $type);
            } else {
                $query->where(function ($addonQuery) use ($type) {
                    $addonQuery->where('plan_type', $type)
                        ->orWhere('plan_type', 'universal');
                });
            }
        }

        $addons = $query->get()->map(fn (ServiceAddon $addon) => $this->formatAddon($addon))->values();

        return response()->json([
            'success' => true,
            'data' => $addons,
        ]);
    }

    private function formatPlan(Service $service): array
    {
        $metadata = is_array($service->metadata) ? $service->metadata : [];

        return [
            'id' => $service->slug,
            'slug' => $service->slug,
            'name' => $service->name,
            'price' => (float) $service->price,
            'type' => $metadata['plan_type'] ?? null,
            'billing' => $metadata['billing'] ?? 'yr',
            'ram' => $metadata['ram'] ?? null,
            'ssd' => $metadata['ssd'] ?? null,
            'category' => $service->category?->only(['id', 'name', 'slug']),
        ];
    }

    private function formatAddon(ServiceAddon $addon): array
    {
        return [
            'id' => $addon->slug,
            'slug' => $addon->slug,
            'name' => $addon->name,
            'price' => (float) $addon->price,
            'desc' => $addon->description,
            'label' => $addon->label,
            'plan_type' => $addon->plan_type,
            'billing' => $addon->billing,
        ];
    }
}
