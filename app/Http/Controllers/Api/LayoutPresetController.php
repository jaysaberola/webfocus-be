<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LayoutPreset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LayoutPresetController extends Controller
{
    public function index(Request $request)
    {
        $query = LayoutPreset::query();

        // Search
        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                ->orWhere('category', 'like', "%{$search}%");
            });
        }
        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->input('name') . '%');
        }
        if ($request->filled('category')) {
            $query->where('category', 'like', '%' . $request->input('category') . '%');
        }
        if ($request->filled('status')) {
            $status = strtolower($request->input('status'));
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $query->orderByDesc('created_at');

        $perPage = $request->per_page ?? 10;

        return $query->paginate($perPage);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'content' => 'required|string',
            'is_active' => 'boolean',
            'thumbnail' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        // Handle thumbnail upload
        if ($request->hasFile('thumbnail')) {
            $path = $request->file('thumbnail')->store(
                'layout-presets',
                'public'
            );
            $data['thumbnail'] = $path; // /storage/...
        }

        return LayoutPreset::create($data);
    }

    public function update(Request $request, $id)
    {
        $preset = LayoutPreset::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'content' => 'required|string',
            'is_active' => 'boolean',
            'thumbnail' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        if ($request->hasFile('thumbnail')) {
            // delete old thumbnail if exists
            if ($preset->thumbnail) {
                $oldPath = $preset->thumbnail;
                Storage::disk('public')->delete($oldPath);
            }

            $path = $request->file('thumbnail')->store(
                'layout-presets',
                'public'
            );
            $data['thumbnail'] = $path;
        }

        $preset->update($data);

        return $preset;
    }

    public function destroy($id)
    {
        $preset = LayoutPreset::findOrFail($id);

        try {
            $preset->update(['is_active' => false]);
        } catch (\Exception $e) {
            // ignore update failures
        }

        try {
            $preset->delete();
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete preset', 'error' => $e->getMessage()], 500);
        }

        if ($preset->thumbnail) {
            try {
                Storage::disk('public')->delete($preset->thumbnail);
            } catch (\Exception $e) {
                // ignore storage delete failures
            }
        }

        return response()->json(['message' => 'Preset deleted']);
    }

    public function restore(Request $request)
    {
        $ids = $request->input('ids') ?? $request->input('id');

        if (is_null($ids)) {
            return response()->json(['message' => 'No id(s) provided'], 422);
        }

        $ids = is_array($ids) ? $ids : [$ids];

        $presets = LayoutPreset::withTrashed()->whereIn('id', $ids)->get();
        $restored = 0;

        foreach ($presets as $p) {
            if ($p->trashed()) {
                $p->restore();
                try { $p->update(['is_active' => true]); } catch (\Exception $e) {}
                $restored++;
            }
        }

        return response()->json(['message' => 'Presets restored', 'restored_count' => $restored]);
    }

    public function restoreById($id)
    {
        $preset = LayoutPreset::withTrashed()->findOrFail($id);

        if (! $preset->trashed()) {
            return response()->json(['message' => 'Preset is not deleted'], 422);
        }

        $preset->restore();
        try { $preset->update(['is_active' => true]); } catch (\Exception $e) {}

        return response()->json(['message' => 'Preset restored', 'id' => $preset->id]);
    }
}
