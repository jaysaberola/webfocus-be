<?php

namespace App\Http\Controllers\Api;

use App\Models\Article;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\ArticleCategory;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ArticleController extends Controller
{

    public function index(Request $request)
    {
        $perPage = $request->integer('per_page', 10);

        $query = Article::query()->with('category:id,name');

        // trashed params acceptance
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

        // filters
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('status') || $request->filled('visibility')) {
            $query->where('status', $request->input('status', $request->input('visibility')));
        }

        if ($request->filled('is_featured')) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->input('date_to'));
        }

        if ($request->filled('type')) {
            $type = strtolower($request->input('type'));
            if ($type === 'featured') {
                $query->where('is_featured', true);
            } elseif ($type === 'regular') {
                $query->where('is_featured', false);
            }
        }

        if ($request->filled('updated_from')) {
            $query->whereDate('updated_at', '>=', $request->input('updated_from'));
        }

        if ($request->filled('updated_to')) {
            $query->whereDate('updated_at', '<=', $request->input('updated_to'));
        }

        if ($request->filled('title')) {
            $query->where('name', 'like', '%' . $request->input('title') . '%');
        }

        if ($request->filled('category')) {
            $term = $request->input('category');
            $query->whereHas('category', function ($categoryQuery) use ($term) {
                $categoryQuery->where('name', 'like', '%' . $term . '%');
            });
        }

        $query->when($request->filled('search'), function ($q) use ($request) {
            $q->where('name', 'like', '%' . $request->search . '%');
        });

        $articles = $query->latest('updated_at')->paginate($perPage);

        // Add helpful flags so frontend can highlight deleted rows like pages
        $articles->getCollection()->transform(function ($a) {
            $aArray = $a->toArray();
            $aArray['is_deleted'] = !empty($a->deleted_at) || ($a->status === 'deleted') || (isset($a->visibility) && strtolower($a->visibility) === 'deleted');
            $aArray['visibility'] = $a->visibility ?? null;
            return $aArray;
        });

        return response()->json($articles);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|integer',
            'date' => 'required|date',
            'name' => 'required|string|max:255',
            'contents' => 'required|string',
            'teaser' => 'required|string',
            'status' => 'required|in:private,published',
            'is_featured' => 'boolean',

            'meta_title' => 'nullable|string',
            'meta_keyword' => 'nullable|string',
            'meta_description' => 'nullable|string',

            'banner' => 'nullable|image|max:2048',
            'thumbnail' => 'nullable|image|max:1024',
        ]);

        // 🔹 Upload banner
        if ($request->hasFile('banner')) {
            $validated['image_url'] =
                $request->file('banner')->store('articles/banners', 'public');
        }

        // 🔹 Upload thumbnail
        if ($request->hasFile('thumbnail')) {
            $validated['thumbnail_url'] =
                $request->file('thumbnail')->store('articles/thumbnails', 'public');
        }

        $article = Article::create([
            'category_id' => $validated['category_id'],
            'slug' => Str::slug($validated['name']),
            'date' => $validated['date'],
            'name' => $validated['name'],
            'contents' => $validated['contents'],
            'teaser' => $validated['teaser'],
            'status' => $validated['status'],
            'is_featured' => $validated['is_featured'] ?? 0,
            'image_url' => $validated['image_url'] ?? null,
            'thumbnail_url' => $validated['thumbnail_url'] ?? null,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_keyword' => $validated['meta_keyword'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Article created successfully',
            'data' => $article
        ], 201);
    }

    public function show(Article $article)
    {
        $article->load('category');

        return response()->json([
            'data' => $article
        ]);
    }

    public function update(Request $request, Article $article)
    {
        $validated = $request->validate([
            'category_id' => 'required|integer',
            'date' => 'required|date',
            'name' => 'required|string|max:255',
            'contents' => 'required|string',
            'teaser' => 'required|string',
            'status' => 'required|in:private,published',
            'is_featured' => 'boolean',

            'meta_title' => 'nullable|string',
            'meta_keyword' => 'nullable|string',
            'meta_description' => 'nullable|string',

            'banner' => 'nullable|image|max:2048',
            'thumbnail' => 'nullable|image|max:1024',
        ]);

        // 🔹 Replace banner if uploaded
        if ($request->hasFile('banner')) {
            if ($article->image_url) {
                Storage::disk('public')->delete($article->image_url);
            }

            $validated['image_url'] =
                $request->file('banner')->store('articles/banners', 'public');
        }

        // 🔹 Replace thumbnail if uploaded
        if ($request->hasFile('thumbnail')) {
            if ($article->thumbnail_url) {
                Storage::disk('public')->delete($article->thumbnail_url);
            }

            $validated['thumbnail_url'] =
                $request->file('thumbnail')->store('articles/thumbnails', 'public');
        }

        $article->update([
            'category_id' => $validated['category_id'],
            'date' => $validated['date'],
            'name' => $validated['name'],
            'contents' => $validated['contents'],
            'teaser' => $validated['teaser'],
            'status' => $validated['status'],
            'is_featured' => $validated['is_featured'] ?? 0,
            'image_url' => $validated['image_url'] ?? $article->image_url,
            'thumbnail_url' => $validated['thumbnail_url'] ?? $article->thumbnail_url,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_keyword' => $validated['meta_keyword'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
        ]);

        return response()->json([
            'message' => 'Article updated successfully',
            'data' => $article
        ]);
    }

    public function fetch_categories()
    {
        return response()->json([
            'data' => ArticleCategory::orderBy('name')->get()
        ]);
    }

    public function destroy(Article $article)
    {
        // mark as deleted for frontend compatibility
        try {
            $article->update(['status' => 'deleted']);
        } catch (\Exception $e) {
            // ignore
        }

        $article->delete();

        return response()->json(['message' => 'Article deleted']);
    }

    public function restore(Request $request)
    {
        $ids = $request->input('ids') ?? $request->input('id');

        if (is_null($ids)) {
            return response()->json(['message' => 'No id(s) provided'], 422);
        }

        $ids = is_array($ids) ? $ids : [$ids];

        $articles = Article::withTrashed()->whereIn('id', $ids)->get();
        $restored = 0;

        foreach ($articles as $art) {
            if ($art->trashed()) {
                $art->restore();
                if ($art->status === 'deleted') {
                    try { $art->update(['status' => 'draft']); } catch (\Exception $e) {}
                }
                $restored++;
            }
        }

        return response()->json(['message' => 'Articles restored', 'restored_count' => $restored]);
    }

    public function restoreById($id)
    {
        $article = Article::withTrashed()->findOrFail($id);

        if (! $article->trashed()) {
            return response()->json(['message' => 'Article is not deleted'], 422);
        }

        $article->restore();

        if ($article->status === 'deleted') {
            try { $article->update(['status' => 'draft']); } catch (\Exception $e) {}
        }

        return response()->json(['message' => 'Article restored', 'id' => $article->id]);
    }

    public function duplicate(Request $request, Article $article)
    {
        $copyMedia = $request->boolean('copy_media', true);

        return DB::transaction(function () use ($request, $article, $copyMedia) {
            $clone = $article->replicate();

            $clone->name = $article->name . ' (Copy)';

            // ensure unique slug
            $baseSlug = Str::slug($clone->name);
            $slug = $baseSlug;
            $i = 1;
            while (Article::where('slug', $slug)->withTrashed()->exists()) {
                $slug = $baseSlug . '-' . $i++;
            }
            $clone->slug = $slug;

            $clone->status = 'draft';
            $clone->user_id = $request->user() ? $request->user()->id : $article->user_id;
            $clone->deleted_at = null;

            // handle media copying
            if ($copyMedia) {
                if (!empty($article->image_url) && Storage::disk('public')->exists($article->image_url)) {
                    $ext = pathinfo($article->image_url, PATHINFO_EXTENSION);
                    $dest = 'articles/banners/' . time() . '_' . uniqid() . ($ext ? '.' . $ext : '');
                    Storage::disk('public')->copy($article->image_url, $dest);
                    $clone->image_url = $dest;
                } else {
                    $clone->image_url = null;
                }

                if (!empty($article->thumbnail_url) && Storage::disk('public')->exists($article->thumbnail_url)) {
                    $ext = pathinfo($article->thumbnail_url, PATHINFO_EXTENSION);
                    $dest = 'articles/thumbnails/' . time() . '_' . uniqid() . ($ext ? '.' . $ext : '');
                    Storage::disk('public')->copy($article->thumbnail_url, $dest);
                    $clone->thumbnail_url = $dest;
                } else {
                    $clone->thumbnail_url = null;
                }
            } else {
                // don't duplicate files; keep URLs so frontend can decide
                $clone->image_url = $article->image_url;
                $clone->thumbnail_url = $article->thumbnail_url;
            }

            $clone->save();

            return response()->json([
                'message' => 'Article duplicated',
                'data' => $clone,
            ], 201);
        });
    }
}
