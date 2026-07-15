<?php

namespace App\Http\Controllers\Api\Page;

use App\Models\Page;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\PageResource;
use App\Services\GrapesParser;

class PageController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'label'             => 'nullable|string|max:255',
            'parent_page_id'    => 'nullable|exists:pages,id',
            'album_id'          => 'nullable|exists:albums,id',
            'contents'          => 'nullable|string',
            'status'            => 'required|in:published,private,draft',
            'meta_title'        => 'nullable|string|max:255',
            'meta_description'  => 'nullable|string',
            'meta_keyword'      => 'nullable|string',
            'template'          => 'nullable|string|max:255',
            'content_type'      => 'nullable|in:tiny,grapes',
            'grapes_html'       => 'nullable|string',
            'grapes_css'        => 'nullable|string',
            'grapes_js'         => 'nullable|string',
            'grapes_payload'    => 'sometimes|nullable|string',
        ]);

        // If frontend sent a single grapes payload (JSON or HTML), parse it
        if (($validated['content_type'] ?? null) === 'grapes'
            && empty($validated['grapes_html'])
            && ! empty($validated['grapes_payload'] ?? null)) {
            $parts = GrapesParser::parse($validated['grapes_payload']);
            $validated['grapes_html'] = $parts['grapes_html'];
            $validated['grapes_css'] = $parts['grapes_css'];
            $validated['grapes_js'] = $parts['grapes_js'];
        }

        $page = Page::create([
            'name'             => $validated['name'],
            'label'            => $validated['label'] ?? null,
            'slug'             => Str::slug($validated['name']),
            'parent_page_id'   => $validated['parent_page_id'] ?? null,
            'album_id'         => $validated['album_id'] ?? null,
            'contents'         => $validated['contents'] ?? null,
            'status'           => $validated['status'],
            'meta_title'       => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
            'meta_keyword'     => $validated['meta_keyword'] ?? null,
            'template'         => $validated['template'] ?? null,
            'content_type'     => $validated['content_type'] ?? 'tiny',
            'grapes_html'      => $validated['grapes_html'] ?? null,
            'grapes_css'       => $validated['grapes_css'] ?? null,
            'grapes_js'        => $validated['grapes_js'] ?? null,
            'user_id'          => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Page created successfully',
            'data'    => $page,
        ], 201);
    }

    public function index(Request $request)
    {
        $perPage = $request->integer('per_page', 10);

        $query = Page::query();

        // Accept multiple possible query param names sent by the frontend toggle
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

        $pages = $query
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->where(function ($qq) use ($term) {
                    $qq->where('name', 'like', '%' . $term . '%')
                        ->orWhere('label', 'like', '%' . $term . '%')
                        ->orWhere('slug', 'like', '%' . $term . '%')
                        ->orWhere('contents', 'like', '%' . $term . '%')
                        ->orWhere('meta_title', 'like', '%' . $term . '%')
                        ->orWhere('meta_description', 'like', '%' . $term . '%')
                        ->orWhere('meta_keyword', 'like', '%' . $term . '%');
                });
            })
            ->when($request->filled('title'), function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->input('title') . '%');
            })
            ->when($request->filled('label'), function ($q) use ($request) {
                $q->where('label', 'like', '%' . $request->input('label') . '%');
            })
            ->when($request->filled('content'), function ($q) use ($request) {
                $term = $request->input('content');
                $q->where(function ($qq) use ($term) {
                    $qq->where('contents', 'like', '%' . $term . '%')
                        ->orWhere('grapes_html', 'like', '%' . $term . '%')
                        ->orWhere('grapes_css', 'like', '%' . $term . '%')
                        ->orWhere('grapes_js', 'like', '%' . $term . '%');
                });
            })
            ->when($request->filled('album'), function ($q) use ($request) {
                $term = $request->input('album');
                $q->where(function ($qq) use ($term) {
                    if (is_numeric($term)) {
                        $qq->where('album_id', (int) $term);
                    }

                    $qq->orWhereHas('album', function ($albumQuery) use ($term) {
                        $albumQuery->where('name', 'like', '%' . $term . '%');
                    });
                });
            })
            ->when($request->filled('last_modified_by'), function ($q) use ($request) {
                $term = $request->input('last_modified_by');
                $q->whereHas('user', function ($userQuery) use ($term) {
                    $userQuery->where('fname', 'like', '%' . $term . '%')
                        ->orWhere('mname', 'like', '%' . $term . '%')
                        ->orWhere('lname', 'like', '%' . $term . '%')
                        ->orWhere('email', 'like', '%' . $term . '%');
                });
            })
            ->when($request->filled('visibility'), function ($q) use ($request) {
                $q->where('status', strtolower($request->input('visibility')));
            })
            ->when($request->filled('seo_title'), function ($q) use ($request) {
                $q->where('meta_title', 'like', '%' . $request->input('seo_title') . '%');
            })
            ->when($request->filled('seo_description'), function ($q) use ($request) {
                $q->where('meta_description', 'like', '%' . $request->input('seo_description') . '%');
            })
            ->when($request->filled('seo_keyword'), function ($q) use ($request) {
                $q->where('meta_keyword', 'like', '%' . $request->input('seo_keyword') . '%');
            })
            ->when($request->filled('date_modified_from'), function ($q) use ($request) {
                $q->whereDate('updated_at', '>=', $request->input('date_modified_from'));
            })
            ->when($request->filled('date_modified_to'), function ($q) use ($request) {
                $q->whereDate('updated_at', '<=', $request->input('date_modified_to'));
            })
            ->latest('updated_at')
            ->paginate($perPage);

        return PageResource::collection($pages);
    }

    public function show(int $id)
    {
        $page = Page::findOrFail($id);

        return response()->json([
            'id' => $page->id,
            'name' => $page->name,
            'label' => $page->label,
            'album_id' => $page->album_id, // ✅ ADD THIS
            'contents' => $page->contents,
            'content_type' => $page->content_type,
            'grapes_html' => $page->grapes_html,
            'grapes_css' => $page->grapes_css,
            'grapes_js' => $page->grapes_js,
            'status' => $page->status,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'meta_keyword' => $page->meta_keyword,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $page = Page::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string',
            'label' => 'sometimes|nullable|string',
            'album_id' => 'sometimes|nullable|exists:albums,id',
            'contents' => 'sometimes|required|string',
            'content_type' => 'sometimes|in:tiny,grapes',
            'grapes_html' => 'sometimes|nullable|string',
            'grapes_css' => 'sometimes|nullable|string',
            'grapes_js' => 'sometimes|nullable|string',
            'grapes_payload' => 'sometimes|nullable|string',
            'status' => 'sometimes|required|in:published,private',
            'meta_title' => 'sometimes|nullable|string',
            'meta_description' => 'sometimes|nullable|string',
            'meta_keyword' => 'sometimes|nullable|string',
        ]);

        if (array_key_exists('name', $validated)) {
            $validated['slug'] = $this->uniqueSlug(Str::slug($validated['name']), $page->id);
        }

        // Parse grapes_payload into fields when update payload uses grapes export
        if (($validated['content_type'] ?? null) === 'grapes'
            && empty($validated['grapes_html'] ?? null)
            && ! empty($validated['grapes_payload'] ?? null)) {
            $parts = GrapesParser::parse($validated['grapes_payload']);
            $validated['grapes_html'] = $parts['grapes_html'];
            $validated['grapes_css'] = $parts['grapes_css'];
            $validated['grapes_js'] = $parts['grapes_js'];
        }

        $page->update($validated);

        return response()->json([
            'message' => 'Page updated successfully',
        ]);
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = $base ?: 'page';
        $i = 2;

        while (Page::where('slug', $slug)
            ->when(!is_null($ignoreId), function ($q) use ($ignoreId) {
                $q->where('id', '!=', $ignoreId);
            })->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    public function pages_menu()
    {
        return response()->json([
            'data' => Page::select('id', 'name', 'label', 'slug')
                ->where('status', 'published')
                ->orderBy('id')
                ->get()
        ]);
    }

    public function destroy(int $id)
    {
        $page = Page::findOrFail($id);

        // Ensure frontend recognizes this as deleted by setting status
        try {
            $page->update(['status' => 'deleted']);
        } catch (\Exception $e) {
            // ignore if update fails for unexpected reasons
        }

        // Soft delete (Page uses SoftDeletes). If you want hard delete, use forceDelete().
        $page->delete();

        return response()->json([
            'message' => 'Page deleted'
        ]);
    }

    public function restore(Request $request)
    {
        $ids = $request->input('ids') ?? $request->input('id');

        if (is_null($ids)) {
            return response()->json(['message' => 'No id(s) provided'], 422);
        }

        $ids = is_array($ids) ? $ids : [$ids];

        $pages = Page::withTrashed()->whereIn('id', $ids)->get();

        $restoredCount = 0;

        foreach ($pages as $p) {
            if ($p->trashed()) {
                $p->restore();

                // If status was 'deleted', move it back to 'draft' so it no longer appears as deleted
                if ($p->status === 'deleted') {
                    try {
                        $p->update(['status' => 'draft']);
                    } catch (\Exception $e) {
                        // ignore update failures
                    }
                }

                $restoredCount++;
            }
        }

        return response()->json([
            'message' => 'Pages restored',
            'restored_count' => $restoredCount
        ]);
    }

}
