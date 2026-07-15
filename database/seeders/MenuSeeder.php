<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('menus')->updateOrInsert(
            ['id' => 1], // re-run safe
            [
                'name' => 'Main Menu',
                'items' => json_encode([
                    [
                        'id' => 1,
                        'label' => 'Home',
                        'type' => 'page',
                        'target' => env('CORS_ALLOWED_ORIGIN').'/public/home',
                        'children' => [],
                    ],
                    [
                        'id' => 2,
                        'label' => 'About',
                        'type' => 'page',
                        'target' => env('CORS_ALLOWED_ORIGIN').'/public/about',
                        'children' => [],
                    ],
                    [
                        'id' => 4,
                        'label' => 'News',
                        'type' => 'page',
                        'target' => env('CORS_ALLOWED_ORIGIN').'/public/news',
                        'children' => [],
                    ],
                    [
                        'id' => 5,
                        'label' => 'Contact Us',
                        'type' => 'page',
                        'target' => env('CORS_ALLOWED_ORIGIN').'/public/contact-us',
                        'children' => [],
                    ],
                ]),
                'is_active' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ]
        );
    }
}
