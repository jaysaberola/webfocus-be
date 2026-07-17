<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class HomePageGrapesSeeder extends Seeder
{
    public function run(): void
    {
        $dataDir = database_path('seeders/data');
        $html = file_get_contents($dataDir . '/home_grapes.html');
        $css = file_get_contents($dataDir . '/home_grapes.css');
        $js = file_get_contents($dataDir . '/home_grapes.js');

        Page::updateOrCreate(
            ['slug' => 'home'],
            [
                'parent_page_id' => null,
                'album_id' => 1,
                'name' => 'Home',
                'label' => 'Home',
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
