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

            // CMS — Dashboard
            'dashboard.view',

            // CMS — Pages
            'pages.view',
            'pages.create',
            'pages.edit',
            'pages.delete',
            'pages.change_status',

            // CMS — Banners / Albums
            'albums.view',
            'albums.create',
            'albums.edit',
            'albums.delete',

            // CMS — File Manager
            'file_manager.manage',

            // CMS — Menus
            'menus.view',
            'menus.create',
            'menus.edit',
            'menus.delete',

            // CMS — News
            'news.view',
            'news.create',
            'news.edit',
            'news.delete',
            'news.change_status',

            // CMS — News Categories
            'news_categories.view',
            'news_categories.create',
            'news_categories.edit',
            'news_categories.delete',

            // CMS — Website Settings
            'website_settings.edit',

            // CMS — Audit Logs
            'audit_logs.view',

            // CMS — Account Management (users, roles, access rights)
            'users.view',
            'users.create',
            'users.edit',
            'users.change_status',
            'roles.view',
            'roles.manage',
            'access_rights.manage',

            // Commerce — Dashboard
            'commerce_dashboard.view',

            // Commerce — Clients
            'customers.manage',

            // Commerce — Transactions
            'sales_transactions.view',
            'sales_transactions.manage',

            // Commerce — Approvals
            'commerce_approvals.view',
            'commerce_approvals.manage',

            // Commerce — Managed Services
            'commerce_managed.view',
            'commerce_managed.manage',

            // Commerce — Contracts
            'commerce_contracts.view',
            'commerce_contracts.manage',

            // Commerce — Catalog
            'commerce_catalog.view',
            'commerce_catalog.manage',
            'products.manage',
            'inventory.view',
            'inventory.manage',
            'coupons.manage',

            // Commerce — Notifications
            'commerce_notifications.view',
            'commerce_notifications.manage',

            // Commerce — Helpdesk
            'commerce_helpdesk.view',
            'commerce_helpdesk.create',
            'commerce_helpdesk.update',
            'commerce_helpdesk.delete',

            // Commerce — Reports
            'reports.view',

            // CMS — Ads / Modals
            'banner_ads.manage',
            'page_modals.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum',
            ]);
        }
    }
}
