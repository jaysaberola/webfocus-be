<?php

namespace App\Http\Controllers\Api;

use App\Models\Page;
use App\Models\Article;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class SearchController extends Controller
{
    /**
     * Search pages by title, content, meta description, etc.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchPages(Request $request)
    {
        $query = $request->input('q', $request->input('search', ''));
        $limit = $request->input('limit', $request->input('per_page', 10));

        if (empty($query)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'No search query provided'
            ]);
        }

        $pages = Page::where(function ($q) use ($query) {
            $q->where('name', 'like', "%{$query}%")
                ->orWhere('label', 'like', "%{$query}%")
                ->orWhere('contents', 'like', "%{$query}%")
                ->orWhere('meta_title', 'like', "%{$query}%")
                ->orWhere('meta_description', 'like', "%{$query}%")
                ->orWhere('meta_keyword', 'like', "%{$query}%");
        })
        ->where('status', 'published')
        ->select('id', 'slug', 'name', 'label', 'contents', 'image_url', 'meta_description', 'created_at')
        ->limit($limit)
        ->get();

        return response()->json([
            'success' => true,
            'data' => $pages,
            'count' => $pages->count(),
            'query' => $query
        ]);
    }

    /**
     * Global search across pages, articles, and other content
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function globalSearch(Request $request)
    {
        $query = $request->input('q', $request->input('search', ''));
        $limit = $request->input('limit', $request->input('per_page', 10));
        $types = $request->input('types', ['pages', 'articles']); // Allow filtering by type

        if (empty($query)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'pages' => [],
                    'articles' => []
                ],
                'message' => 'No search query provided'
            ]);
        }

        $results = [];

        // Search Pages
        if (in_array('pages', $types)) {
            $pages = Page::where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('label', 'like', "%{$query}%")
                    ->orWhere('contents', 'like', "%{$query}%")
                    ->orWhere('meta_title', 'like', "%{$query}%")
                    ->orWhere('meta_description', 'like', "%{$query}%")
                    ->orWhere('meta_keyword', 'like', "%{$query}%");
            })
            ->where('status', 'published')
            ->select('id', 'slug', 'name', 'label', 'contents', 'image_url', 'meta_description', 'created_at')
            ->limit($limit)
            ->get()
            ->map(function ($page) {
                return [
                    'id' => $page->id,
                    'type' => 'page',
                    'slug' => $page->slug,
                    'title' => $page->name,
                    'label' => $page->label,
                    'excerpt' => $page->meta_description ?? strip_tags(substr($page->contents, 0, 150)),
                    'image_url' => $page->image_url,
                    'created_at' => $page->created_at,
                    'url' => "/pages/{$page->slug}"
                ];
            });

            $results['pages'] = $pages;
        }

        // Search Articles
        if (in_array('articles', $types)) {
            $articles = Article::where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('contents', 'like', "%{$query}%")
                    ->orWhere('teaser', 'like', "%{$query}%")
                    ->orWhere('meta_title', 'like', "%{$query}%")
                    ->orWhere('meta_description', 'like', "%{$query}%")
                    ->orWhere('meta_keyword', 'like', "%{$query}%");
            })
            ->where('status', 'published')
            ->with('category:id,name')
            ->select('id', 'slug', 'name', 'teaser', 'contents', 'image_url', 'thumbnail_url', 'meta_description', 'category_id', 'date', 'created_at')
            ->limit($limit)
            ->get()
            ->map(function ($article) {
                return [
                    'id' => $article->id,
                    'type' => 'article',
                    'slug' => $article->slug,
                    'title' => $article->name,
                    'excerpt' => $article->teaser ?? $article->meta_description ?? strip_tags(substr($article->contents, 0, 150)),
                    'image_url' => $article->image_url,
                    'thumbnail_url' => $article->thumbnail_url,
                    'category' => $article->category ? $article->category->name : null,
                    'date' => $article->date,
                    'created_at' => $article->created_at,
                    'url' => "/articles/{$article->slug}"
                ];
            });

            $results['articles'] = $articles;
        }

        // Calculate total count
        $totalCount = collect($results)->sum(function ($items) {
            return count($items);
        });

        return response()->json([
            'success' => true,
            'data' => $results,
            'total_count' => $totalCount,
            'query' => $query
        ]);
    }

    /**
     * Quick search - returns combined results in a single flat array
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function quickSearch(Request $request)
    {
        $query = $request->input('q', $request->input('search', ''));
        $limit = $request->input('limit', $request->input('per_page', 5));

        if (empty($query)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'No search query provided'
            ]);
        }

        $results = [];

        // Search Pages
        $pages = Page::where(function ($q) use ($query) {
            $q->where('name', 'like', "%{$query}%")
                ->orWhere('label', 'like', "%{$query}%")
                ->orWhere('contents', 'like', "%{$query}%");
        })
        ->where('status', 'published')
        ->select('id', 'slug', 'name', 'label', 'image_url', 'created_at')
        ->limit($limit)
        ->get()
        ->map(function ($page) {
            return [
                'id' => $page->id,
                'type' => 'page',
                'slug' => $page->slug,
                'title' => $page->name,
                'subtitle' => $page->label,
                'image_url' => $page->image_url,
                'url' => "/pages/{$page->slug}"
            ];
        });

        // Search Articles
        $articles = Article::where(function ($q) use ($query) {
            $q->where('name', 'like', "%{$query}%")
                ->orWhere('teaser', 'like', "%{$query}%");
        })
        ->where('status', 'published')
        ->with('category:id,name')
        ->select('id', 'slug', 'name', 'teaser', 'thumbnail_url', 'category_id', 'date', 'created_at')
        ->limit($limit)
        ->get()
        ->map(function ($article) {
            return [
                'id' => $article->id,
                'type' => 'article',
                'slug' => $article->slug,
                'title' => $article->name,
                'subtitle' => $article->category ? $article->category->name : null,
                'image_url' => $article->thumbnail_url,
                'date' => $article->date,
                'url' => "/articles/{$article->slug}"
            ];
        });

        // Combine and sort by relevance (pages first, then articles)
        $results = $pages->concat($articles)->take($limit * 2);

        return response()->json([
            'success' => true,
            'data' => $results,
            'count' => $results->count(),
            'query' => $query
        ]);
    }
}
