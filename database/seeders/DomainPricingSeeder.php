<?php

namespace Database\Seeders;

use App\Models\DomainCategory;
use App\Models\DomainTld;
use Illuminate\Database\Seeder;

class DomainPricingSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Top Level Domains',
                'slug' => 'top_level_domains',
                'base_price' => 1440.00,
                'selling_price' => 1728.00,
                'markup_percent' => 20,
                'is_one_time' => false,
                'tlds' => ['com', 'net', 'org', 'biz', 'info'],
            ],
            [
                'name' => 'Country Level Domains',
                'slug' => 'country_level_domains',
                'base_price' => 2880.00,
                'selling_price' => 3456.00,
                'markup_percent' => 20,
                'is_one_time' => false,
                'tlds' => ['ph', 'com.ph', 'net.ph', 'org.ph'],
            ],
            [
                'name' => 'Hybrid Top Level Domains',
                'slug' => 'hybrid_top_level_domains',
                'base_price' => 3360.00,
                'selling_price' => 4032.00,
                'markup_percent' => 20,
                'is_one_time' => false,
                'tlds' => ['asia', 'online', 'site', 'store'],
            ],
            [
                'name' => 'Education Domains',
                'slug' => 'education_domains',
                'base_price' => 4420.00,
                'selling_price' => 5304.00,
                'markup_percent' => 20,
                'is_one_time' => false,
                'tlds' => ['edu.ph'],
            ],
            [
                'name' => 'Government Domains',
                'slug' => 'government_domains',
                'base_price' => 4320.00,
                'selling_price' => 5184.00,
                'markup_percent' => 20,
                'is_one_time' => true,
                'tlds' => ['gov.ph'],
            ],
        ];

        foreach ($categories as $item) {
            $category = DomainCategory::updateOrCreate(
                ['slug' => $item['slug']],
                [
                    'name' => $item['name'],
                    'base_price' => $item['base_price'],
                    'selling_price' => $item['selling_price'],
                    'markup_percent' => $item['markup_percent'],
                    'is_one_time' => $item['is_one_time'],
                    'active' => true,
                ]
            );

            foreach ($item['tlds'] as $tld) {
                DomainTld::updateOrCreate(
                    ['tld' => $tld],
                    [
                        'domain_category_id' => $category->id,
                        'active' => true,
                    ]
                );
            }
        }
    }
}