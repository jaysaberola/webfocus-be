<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Setting;

class PublicLegalController extends Controller
{
    public function privacy()
    {
        $defaults = require database_path('seeders/data/data_privacy_default.php');
        $setting = Setting::query()->first();
        $page = Page::query()->where('slug', 'data-privacy')->first();

        $title = $setting?->data_privacy_title ?: $page?->name ?: $defaults['title'];
        $popupContent = $setting?->data_privacy_popup_content ?: $defaults['popup_content'];
        $html = $this->resolveLegalHtml($setting, $page, $defaults['html']);

        return response()->json([
            'success' => true,
            'data' => [
                'title' => $title,
                'popup_content' => $popupContent,
                'terms_title' => $defaults['terms_title'] ?? 'Terms of Use',
                'html' => $html,
            ],
        ]);
    }

    private function resolveLegalHtml(?Setting $setting, ?Page $page, string $fallback): string
    {
        if ($page?->content_type === 'grapes') {
            $html = trim((string) ($page->grapes_html ?: $page->contents ?: ''));
            if ($html !== '') {
                return $html;
            }
        }

        $stored = trim((string) ($setting?->data_privacy_content ?: ''));
        if ($stored !== '') {
            return $stored;
        }

        return $fallback;
    }
}
