<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Album;

class AlbumSeeder extends Seeder
{
    public function run(): void
    {
        Album::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Home Banner',
                'transition_in' => 1,
                'transition_out' => 2,
                'transition' => 5,
                'type' => 'main_banner',
                'banner_type' => 'image',
                'user_id' => 1,
            ]
        );
    }
}
