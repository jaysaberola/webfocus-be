<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::create([
            'website_name' => 'My Website',
            'company_name' => 'My Company',
            'email' => 'info@example.com',
            'promo_is_displayed' => true,
        ]);
    }
}
