<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceAddon;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class HostingCatalogSeeder extends Seeder
{
    private const LEGACY_ADDON_CATEGORY_SLUGS = [
        'hosting-addons-cloud',
        'hosting-addons-shared',
        'hosting-addons-dedicated',
        'hosting-addons-baremetal',
        'hosting-addons-universal',
    ];

    public function run(): void
    {
        $catalog = require database_path('seeders/data/hosting_catalog.php');

        $this->cleanupLegacyAddonServices();

        $categoryIds = [];

        foreach ($catalog['categories'] as $category) {
            ServiceCategory::updateOrCreate(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'sort_order' => $category['sort_order'],
                    'position' => $category['position'],
                ]
            );

            $categoryIds[$category['slug']] = ServiceCategory::where('slug', $category['slug'])->value('id');
        }

        foreach ($catalog['plans'] as $index => $plan) {
            Service::withTrashed()->updateOrCreate(
                ['slug' => $plan['slug']],
                [
                    'category_id' => $categoryIds[$plan['category']] ?? null,
                    'name' => $plan['name'],
                    'price' => $plan['price'],
                    'description' => null,
                    'metadata' => $plan['metadata'],
                    'status' => 'active',
                    'is_active' => true,
                    'deleted_at' => null,
                ]
            );
        }

        $sortOrder = 0;

        foreach ($catalog['addons'] as $planType => $addons) {
            foreach ($addons as $addon) {
                $sortOrder++;

                ServiceAddon::withTrashed()->updateOrCreate(
                    ['slug' => $addon['slug']],
                    [
                        'name' => $addon['name'],
                        'price' => $addon['price'],
                        'description' => $addon['description'] ?? null,
                        'label' => $addon['label'] ?? null,
                        'plan_type' => $planType,
                        'billing' => 'yr',
                        'status' => 'active',
                        'is_active' => true,
                        'sort_order' => $sortOrder,
                        'deleted_at' => null,
                    ]
                );
            }
        }
    }

    private function cleanupLegacyAddonServices(): void
    {
        Service::withTrashed()
            ->where(function ($query) {
                $query->where('slug', 'like', 'addon-%')
                    ->orWhereJsonContains('metadata->item_type', 'addon');
            })
            ->forceDelete();

        ServiceCategory::whereIn('slug', self::LEGACY_ADDON_CATEGORY_SLUGS)->delete();
    }
}
