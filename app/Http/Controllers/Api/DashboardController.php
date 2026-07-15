<?php

namespace App\Http\Controllers\Api;

use App\Models\Page;
use App\Models\Album;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        return response()->json([
            'data' => [
                'pages_count'  => Page::count(),
                'albums_count' => Album::count(),
                'news_count' => Article::count(),
            ],
        ]);
    }
}
