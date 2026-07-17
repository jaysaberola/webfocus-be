<?php

namespace App\Http\Controllers\Api\Page;

use App\Support\StorageAssetUrl;
use Str;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Option;
use App\Models\Article;
use Illuminate\Http\Request;
use App\Models\ArticleCategory;
use App\Mail\ContactMessageMail;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;

class PublicPageController extends Controller
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

    public function show(string $slug)
    {
        $page = Page::with([
                'album.banners' => function ($q) {
                    $q->orderBy('order');
                }
            ])
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        $transitionInValue = $page->album ? $this->animationValue($page->album->transition_in) : null;
        $transitionOutValue = $page->album ? $this->animationValue($page->album->transition_out) : null;

        return response()->json([
            'id'        => $page->id,
            'title'     => $page->name,
            'label'     => $page->label,
            'slug'      => $page->slug,
            'content'   => $page->contents,
            'content_type' => $page->content_type,
            'grapes_html' => $page->grapes_html,
            'grapes_css' => $page->grapes_css,
            'grapes_js' => $page->grapes_js,
            'page_type' => $page->page_type,
            'template'  => $page->template,
            'image_url' => $page->image_url,

            'meta' => [
                'title'       => $page->meta_title,
                'description' => $page->meta_description,
                'keywords'    => $page->meta_keyword,
            ],
            'album' => $page->album ? [
                'id'             => $page->album->id,
                'name'           => $page->album->name,
                'type'           => $page->album->type,
                'banner_type'    => $page->album->banner_type,
                'transition'     => $page->album->transition,
                'transition_in'  => $page->album->transition_in,
                'transition_out' => $page->album->transition_out,
                'transition_in_value'  => $transitionInValue,
                'transition_out_value' => $transitionOutValue,
                'banners' => $page->album->banners->map(function ($banner) {
                    $mediaType = preg_match('/\.(mp4|webm|ogg|mov|m4v)(\?.*)?$/i', (string) $banner->image_path)
                        ? 'video'
                        : 'image';

                    return [
                        'id'          => $banner->id,
                        'title'       => $banner->title,
                        'title_font' => $banner->title_font ?? null,
                        'title_font_size' => $banner->title_font_size ?? null,
                        'title_bold' => $banner->title_bold ?? null,
                        'description' => $banner->description,
                        'description_font' => $banner->description_font ?? null,
                        'description_font_size' => $banner->description_font_size ?? null,
                        'description_bold' => $banner->description_bold ?? null,
                        'alt'         => $banner->alt,
                        'image_path'  => $banner->image_path,
                        'image_url'   => StorageAssetUrl::publicDisk($banner->image_path),
                        'media_type' => $mediaType,
                        'button_text' => $banner->button_text,
                        'button_font' => $banner->button_font ?? null,
                        'button_font_size' => $banner->button_font_size ?? null,
                        'button_bold' => $banner->button_bold ?? null,
                        'url'         => $banner->url,
                        'order'       => $banner->order,
                    ];
                })->values(),
            ] : null,
        ]);
    }

    public function active()
    {
        $menu = Menu::where('is_active', true)->first();

        if (!$menu) {
            return response()->json([
                'data' => null,
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $menu->id,
                'name' => $menu->name,
                'items' => $this->normalizeItems($menu->items),
            ],
        ]);
    }

    private function normalizeItems(array $items): array
    {
        return collect($items)->map(function ($item) {
            return [
                'id' => $item['id'],
                'label' => $item['label'],
                'type' => $item['type'],
                'target' => $item['target'],
                'children' => $this->normalizeItems($item['children'] ?? []),
            ];
        })->values()->all();
    }

    public function footer()
    {
        $footer = Page::where('slug', 'footer')
            ->where('status', 'published')
            ->first();

        if (!$footer) {
            return response()->json([
                'message' => 'Footer not found'
            ], 404);
        }

        $hasGrapes = $footer->content_type === 'grapes'
            || filled($footer->grapes_html)
            || filled($footer->grapes_css)
            || filled($footer->grapes_js);

        $contents = $footer->contents;
        if ($hasGrapes) {
            $contents = trim((string) ($footer->grapes_html ?? ''));
            if (filled($footer->grapes_css)) {
                $contents .= "\n<style>{$footer->grapes_css}</style>";
            }
            if (filled($footer->grapes_js)) {
                $contents .= "\n<script>{$footer->grapes_js}</script>";
            }
        }

        return response()->json([
            'data' => [
                'id' => $footer->id,
                'slug' => $footer->slug,
                'contents' => $contents,
                'content_type' => $footer->content_type,
                'grapes_html' => $footer->grapes_html,
                'grapes_css' => $footer->grapes_css,
                'grapes_js' => $footer->grapes_js,
            ]
        ]);
    }
    
    public function public_articles(Request $request)
    {
        $query = Article::query()
            ->with(['category:id,name,slug', 'user:id,fname,lname'])
            ->where('status', 'published')
            ->orderBy('date', 'desc');

        // 🔍 Search
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('teaser', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('category')) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->category);
            });
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        if ($request->filled('year')) {
            $query->whereYear('date', $request->year);
        }

        if ($request->filled('month')) {
            $query->whereMonth('date', $request->month);
        }

        $articles = $query->paginate(
            $request->get('per_page', 10)
        );

        return response()->json($articles);
    }

    public function public_articles_show(string $slug)
    {
        $article = Article::with(['category:id,name,slug', 'user:id,fname,lname'])
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return response()->json($article);
    }

    public function public_article_categories()
    {
        $categories = ArticleCategory::query()
            ->select('id', 'name', 'slug')
            ->withCount([
                'articles as articles_count' => function ($q) {
                    $q->where('status', 'published');
                }
            ])
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

    public function archive()
    {
        $rows = Article::where('status', 'published')
            ->selectRaw('YEAR(date) as year, MONTH(date) as month, COUNT(*) as total')
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();

        // Transform to nested structure
        $archive = [];

        foreach ($rows as $row) {
            $archive[$row->year][] = [
                'month' => $row->month,
                'total' => $row->total,
            ];
        }

        return response()->json($archive);
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'inquiry_type'   => 'required|string',
            'first_name'     => 'required|string|max:100',
            'last_name'      => 'required|string|max:100',
            'email'          => 'required|email',
            'contact_number' => 'required|string|max:30',
            'message'        => 'required|string|max:2000',
        ]);

        Mail::to(config('mail.from.address'))
            ->send(new ContactMessageMail($data));

        return response()->json([
            'message' => 'Message sent successfully'
        ]);
    }
}
