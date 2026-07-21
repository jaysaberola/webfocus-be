<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class DataPrivacySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = require database_path('seeders/data/data_privacy_default.php');

        Page::updateOrCreate(
            ['slug' => 'data-privacy'],
            [
                'parent_page_id' => null,
                'album_id' => null,
                'name' => $defaults['title'],
                'label' => $defaults['title'],
                'contents' => $defaults['html'],
                'grapes_html' => $defaults['html'],
                'grapes_css' => '',
                'grapes_js' => '',
                'content_type' => 'grapes',
                'status' => 'published',
                'page_type' => 'default',
                'user_id' => 1,
            ]
        );

        $setting = Setting::query()->first();
        if (! $setting) {
            return;
        }

        $setting->update([
            'data_privacy_title' => $defaults['title'],
            'data_privacy_popup_content' => $defaults['popup_content'],
            'data_privacy_content' => $defaults['html'],
        ]);
    }
}
