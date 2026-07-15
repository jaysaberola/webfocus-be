<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArticleCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArticleCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = ArticleCategory::query();

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

        $categories = $query->withCount('articles')
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%");
            })
            ->when($request->filled('name'), function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->input('name') . '%');
            })
            ->when($request->filled('slug'), function ($q) use ($request) {
                $q->where('slug', 'like', '%' . $request->input('slug') . '%');
            })
            ->when($request->filled('articles_count'), function ($q) use ($request) {
                $q->having('articles_count', '=', (int) $request->input('articles_count'));
            })
            ->latest()
            ->paginate($request->integer('per_page', 10));

        $categories->getCollection()->transform(function ($category) {
            $categoryArray = $category->toArray();
            $categoryArray['is_deleted'] = !empty($category->deleted_at);
            return $categoryArray;
        });

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $category = ArticleCategory::create([
            'name'    => $validated['name'],
            'slug'    => Str::slug($validated['name']),
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Category created successfully',
            'data'    => $category,
        ], 201);
    }

    public function show(ArticleCategory $category)
    {
        return response()->json([
            'data' => $category
        ]);
    }

    public function update(Request $request, ArticleCategory $category)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $category->update([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
        ]);

        return response()->json([
            'message' => 'Category updated successfully',
            'data' => $category,
        ]);
    }

    public function delete(Request $request)
    {
        $ids = $request->input('ids') ?? $request->input('id');

        if (is_null($ids)) {
            return response()->json(['message' => 'No id(s) provided'], 422);
        }

        $ids = is_array($ids) ? $ids : [$ids];

        $categories = ArticleCategory::whereIn('id', $ids)->get();
        $deleted = 0;

        foreach ($categories as $c) {
            try {
                $c->delete();
                $deleted++;
            } catch (\Exception $e) {
                // ignore individual delete failures
            }
        }

        return response()->json(['message' => 'Categories deleted', 'deleted_count' => $deleted]);
    }

    public function restore(Request $request)
    {
        $ids = $request->input('ids') ?? $request->input('id');

        if (is_null($ids)) {
            return response()->json(['message' => 'No id(s) provided'], 422);
        }

        $ids = is_array($ids) ? $ids : [$ids];

        $categories = ArticleCategory::withTrashed()->whereIn('id', $ids)->get();
        $restored = 0;

        foreach ($categories as $category) {
            if ($category->trashed()) {
                $category->restore();
                $restored++;
            }
        }

        return response()->json([
            'message' => 'Categories restored',
            'restored_count' => $restored,
        ]);
    }

    public function restoreById(int $id)
    {
        $category = ArticleCategory::withTrashed()->findOrFail($id);

        if (! $category->trashed()) {
            return response()->json(['message' => 'Category is not deleted'], 422);
        }

        $category->restore();

        return response()->json([
            'message' => 'Category restored',
            'data' => $category,
        ]);
    }
}
