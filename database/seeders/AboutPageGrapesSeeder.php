<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class AboutPageGrapesSeeder extends Seeder
{
    public function run(): void
    {
        $dataDir = database_path('seeders/data');
        $html = file_get_contents($dataDir . '/about_grapes.html');
        $css = file_get_contents($dataDir . '/about_grapes.css');
        $js = file_get_contents($dataDir . '/about_grapes.js');

        $payload = [
            'parent_page_id' => null,
            'album_id' => null,
            'name' => 'About',
            'label' => 'About Us',
            'contents' => '',
            'grapes_html' => trim($html),
            'grapes_css' => trim($css),
            'grapes_js' => trim($js),
            'content_type' => 'grapes',
            'status' => 'published',
            'page_type' => 'standard',
            'user_id' => 1,
        ];

        foreach (['about', 'about-us'] as $slug) {
            Page::updateOrCreate(['slug' => $slug], $payload);
        }
    }
}
