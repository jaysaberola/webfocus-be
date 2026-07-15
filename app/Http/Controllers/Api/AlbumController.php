<?php

namespace App\Http\Controllers\Api;

use App\Models\Album;
use App\Models\Banner;
use App\Models\Option;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use Illuminate\Support\Facades\Storage;

class AlbumController extends Controller
{
    private function animationValue($id): ?string
    {
        if (empty($id)) {
            return null;
        }

        $query = Option::where('type', 'animation');

        if (is_numeric($id)) {
            return (clone $query)->where('id', (int) $id)->value('value');
        }

        $value = trim((string) $id);

        return (clone $query)
            ->where(function ($q) use ($value) {
                $q->where('value', $value)
                    ->orWhere('name', $value);
            })
            ->value('value') ?: $value;
    }

    public function index(Request $request)
    {
        $perPage = $request->per_page ?? 10;

        $query = Album::query();

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

        $albums = $query
            ->where('id', '!=', 1) // 🚫 exclude Home Banner
            ->withCount('banners')
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            })
            ->when($request->filled('name'), function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->input('name') . '%');
            })
            ->when($request->filled('total_images'), function ($q) use ($request) {
                $q->having('banners_count', '=', (int) $request->input('total_images'));
            })
            ->when($request->filled('date_updated_from'), function ($q) use ($request) {
                $q->whereDate('updated_at', '>=', $request->input('date_updated_from'));
            })
            ->when($request->filled('date_updated_to'), function ($q) use ($request) {
                $q->whereDate('updated_at', '<=', $request->input('date_updated_to'));
            })
            ->latest()
            ->paginate($perPage);

        return AlbumResource::collection($albums);
    }

    public function show(Album $album)
    {
        $album->load('banners');
        $album->setAttribute('transition_in_value', $this->animationValue($album->transition_in));
        $album->setAttribute('transition_out_value', $this->animationValue($album->transition_out));

        return response()->json(
            $album
        );
    }

    public function store(Request $request)
    {
        return DB::transaction(function () use ($request) {

            $album = Album::create([
                'name' => $request->name,
                'transition_in' => $request->transition_in,
                'transition_out' => $request->transition_out,
                'transition' => $request->transition,
                'type' => 'sub_banner',
                'banner_type' => $request->banner_type,
                'user_id' => auth()->id(),
            ]);

            $this->syncBanners($album, $request->banners ?? []);

            return response()->json($album->load('banners'), 201);
        });
    }

    public function update(Request $request, Album $album)
    {
        return DB::transaction(function () use ($request, $album) {

            $album->update($request->all());

            $this->syncBanners($album, $request->banners ?? []);

            return response()->json($album->load('banners'));
        });
    }

    private function syncBanners(Album $album, array $banners)
    {
        $existingIds = collect($banners)->pluck('id')->filter()->values();
        $removedBanners = Banner::where('album_id', $album->id)
            ->whereNotIn('id', $existingIds)
            ->get();

        foreach ($removedBanners as $removed) {
            if ($removed->image_path && Storage::disk('public')->exists($removed->image_path)) {
                Storage::disk('public')->delete($removed->image_path);
            }

            $removed->delete();
        }

        foreach ($banners as $index => $data) {

            $banner = Banner::updateOrCreate(
                [
                    'id' => $data['id'] ?? null,
                    'album_id' => $album->id,
                ],
                [
                    'title' => $data['title'] ?? null,
                    'description' => $data['description'] ?? null,
                    'alt' => $data['alt'] ?? null,
                    'button_text' => $data['button_text'] ?? null,
                    'url' => $data['url'] ?? null,
                    'order' => $data['order'] ?? $index,
                    'user_id' => auth()->id(),
                ]
            );

            $image = request()->file("banners.$index.image");

            if ($image instanceof UploadedFile) {
                if ($banner->image_path && Storage::disk('public')->exists($banner->image_path)) {
                    Storage::disk('public')->delete($banner->image_path);

                }
                $path = $image->store('banners', 'public');
                $banner->update(['image_path' => $path]);
            }
        }
    }

    public function destroy(Album $album)
    {
        return DB::transaction(function () use ($album) {
            $album->delete();

            return response()->json(null, 204);
        });
    }

    public function restore(Request $request)
    {
        $ids = $request->input('ids') ?? $request->input('id');

        if (is_null($ids)) {
            return response()->json(['message' => 'No id(s) provided'], 422);
        }

        $ids = is_array($ids) ? $ids : [$ids];

        $albums = Album::withTrashed()
            ->whereIn('id', $ids)
            ->where('id', '!=', 1)
            ->get();
        $restored = 0;

        foreach ($albums as $album) {
            if ($album->trashed()) {
                $album->restore();
                $restored++;
            }
        }

        return response()->json([
            'message' => 'Albums restored',
            'restored_count' => $restored,
        ]);
    }

    public function restoreById($id)
    {
        if ((int) $id === 1) {
            return response()->json(['message' => 'Home Banner cannot be restored here'], 422);
        }

        $album = Album::withTrashed()->findOrFail($id);

        if (! $album->trashed()) {
            return response()->json(['message' => 'Album is not deleted'], 422);
        }

        $album->restore();

        return response()->json(['message' => 'Album restored', 'id' => $album->id]);
    }
}
