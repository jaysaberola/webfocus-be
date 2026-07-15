<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [

            // 🔹 PAGES
            'pages.view',
            'pages.create',
            'pages.edit',
            'pages.delete',
            'pages.change_status',

            // 🔹 ALBUMS
            'albums.view',
            'albums.create',
            'albums.edit',
            'albums.delete',

            // 🔹 FILE MANAGER
            'file_manager.manage',

            // 🔹 MENUS
            'menus.view',
            'menus.create',
            'menus.edit',
            'menus.delete',

            // 🔹 NEWS
            'news.view',
            'news.create',
            'news.edit',
            'news.delete',
            'news.change_status',

            // 🔹 NEWS CATEGORIES
            'news_categories.view',
            'news_categories.create',
            'news_categories.edit',
            'news_categories.delete',

            // 🔹 WEBSITE SETTINGS
            'website_settings.edit',

            // 🔹 AUDIT LOGS
            'audit_logs.view',

            // 🔹 USERS
            'users.view',
            'users.create',
            'users.edit',
            'users.change_status',

            // 🔹 CUSTOMERS
            'customers.manage',

            // 🔹 SALES TRANSACTIONS
            'sales_transactions.view',
            'sales_transactions.manage',

            // 🔹 PRODUCTS
            'products.manage',

            // 🔹 INVENTORY
            'inventory.view',
            'inventory.manage',

            // 🔹 COUPONS
            'coupons.manage',

            // 🔹 REPORTS
            'reports.view',

            // 🔹 ADS / MODALS
            'banner_ads.manage',
            'page_modals.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum', // IMPORTANT for Next.js
            ]);
        }
    }
}
