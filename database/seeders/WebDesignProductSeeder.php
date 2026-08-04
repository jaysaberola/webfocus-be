<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class WebDesignProductSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = \App\Models\User::query()->value('id') ?? 1;

        $category = ProductCategory::firstOrCreate(
            ['slug' => 'web-design'],
            [
                'name' => 'Web Design',
                'user_id' => $adminId,
            ]
        );

        $packages = [
            [
                'name' => 'Business Starter Launch',
                'price' => 12500,
                'description' => '100% Mobile Responsive Layout with up to 5 custom design sections.',
            ],
            [
                'name' => 'Custom Professional Corporate',
                'price' => 32000,
                'description' => 'Figma prototype with CMS database panel integration and SLA helpdesk.',
            ],
            [
                'name' => 'High-Concurrency E-Commerce Plus',
                'price' => 58000,
                'description' => 'E-commerce package with payment gateway sync and analytics dashboard.',
            ],
        ];

        foreach ($packages as $package) {
            Product::withTrashed()->updateOrCreate(
                ['slug' => Str::slug($package['name'])],
                [
                    'category_id' => $category->id,
                    'user_id' => $adminId,
                    'name' => $package['name'],
                    'price' => $package['price'],
                    'description' => $package['description'],
                    'status' => 'active',
                    'deleted_at' => null,
                ]
            );
        }
    }
}
