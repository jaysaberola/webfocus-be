<?php

namespace App\Http\Controllers\Api;

use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class MenuController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->integer('per_page', 10);

        $query = Menu::query();

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

        $query->when($request->filled('search'), function ($q) use ($request) {
            $q->where('name', 'like', '%' . $request->search . '%');
        });
        $query->when($request->filled('name'), function ($q) use ($request) {
            $q->where('name', 'like', '%' . $request->input('name') . '%');
        });
        $query->when($request->filled('status'), function ($q) use ($request) {
            $status = strtolower($request->input('status'));
            if ($status === 'active') {
                $q->where('is_active', true);
            } elseif ($status === 'inactive') {
                $q->where('is_active', false);
            }
        });
        $query->when($request->filled('date_modified_from'), function ($q) use ($request) {
            $q->whereDate('updated_at', '>=', $request->input('date_modified_from'));
        });
        $query->when($request->filled('date_modified_to'), function ($q) use ($request) {
            $q->whereDate('updated_at', '<=', $request->input('date_modified_to'));
        });

        $menus = $query->latest()->paginate($perPage);

        // Add helper flags for frontend to detect deleted rows
        $menus->getCollection()->transform(function ($m) {
            $mArr = $m->toArray();
            $mArr['is_deleted'] = !empty($m->deleted_at);
            $mArr['visibility'] = $mArr['visibility'] ?? null;
            return $mArr;
        });

        return response()->json($menus);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'items' => 'required|array',
            'is_active' => 'boolean',
        ]);

        $menu = Menu::create($validated);

        return response()->json([
            'message' => 'Menu created successfully',
            'data' => $menu
        ], 201);
    }

    public function show(Menu $menu)
    {
        return response()->json([
            'data' => $menu
        ]);
    }

    public function update(Request $request, Menu $menu)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'items' => 'required|array',
            'is_active' => 'boolean',
        ]);

        $menu->update($validated);

        return response()->json([
            'message' => 'Menu updated successfully',
            'data' => $menu
        ]);
    }

    public function destroy(Menu $menu)
    {
        // set inactive so UI indicates deletion immediately
        try {
            $menu->update(['is_active' => false]);
        } catch (\Exception $e) {
            // ignore
        }

        $menu->delete();

        return response()->json([
            'message' => 'Menu deleted'
        ]);
    }

    public function restore(Request $request)
    {
        $ids = $request->input('ids') ?? $request->input('id');

        if (is_null($ids)) {
            return response()->json(['message' => 'No id(s) provided'], 422);
        }

        $ids = is_array($ids) ? $ids : [$ids];

        $menus = Menu::withTrashed()->whereIn('id', $ids)->get();
        $restored = 0;

        foreach ($menus as $m) {
            if ($m->trashed()) {
                $m->restore();
                try { $m->update(['is_active' => true]); } catch (\Exception $e) {}
                $restored++;
            }
        }

        return response()->json(['message' => 'Menus restored', 'restored_count' => $restored]);
    }

    public function restoreById($id)
    {
        $menu = Menu::withTrashed()->findOrFail($id);

        if (! $menu->trashed()) {
            return response()->json(['message' => 'Menu is not deleted'], 422);
        }

        $menu->restore();
        try { $menu->update(['is_active' => true]); } catch (\Exception $e) {}

        return response()->json(['message' => 'Menu restored', 'id' => $menu->id]);
    }

    public function setActive(Menu $menu)
    {
        DB::transaction(function () use ($menu) {
            Menu::where('id', '!=', $menu->id)->update([
                'is_active' => false,
            ]);

            $menu->update([
                'is_active' => true,
            ]);
        });

        return response()->json([
            'message' => 'Menu activated successfully',
            'data' => $menu
        ]);
    }
}
