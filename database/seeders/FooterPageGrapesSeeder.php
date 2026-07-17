<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class FooterPageGrapesSeeder extends Seeder
{
    public function run(): void
    {
        $dataDir = database_path('seeders/data');
        $html = file_get_contents($dataDir . '/footer_grapes.html');
        $css = file_get_contents($dataDir . '/footer_grapes.css');
        $js = file_get_contents($dataDir . '/footer_grapes.js');

        Page::updateOrCreate(
            ['slug' => 'footer'],
            [
                'parent_page_id' => null,
                'album_id' => null,
                'name' => 'Footer',
                'label' => 'Footer',
                'contents' => '',
                'grapes_html' => trim($html),
                'grapes_css' => trim($css),
                'grapes_js' => trim($js),
                'content_type' => 'grapes',
                'status' => 'published',
                'page_type' => 'default',
                'user_id' => 1,
            ]
        );
    }
}
