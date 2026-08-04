<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $cmsFinanceSales = [
            'dashboard.view',
            'pages.view',
            'pages.create',
            'pages.edit',
            'pages.delete',
            'pages.change_status',
            'albums.view',
            'albums.create',
            'albums.edit',
            'albums.delete',
            'file_manager.manage',
            'menus.view',
            'menus.create',
            'menus.edit',
            'menus.delete',
            'users.view',
            'users.create',
            'users.edit',
            'users.change_status',
            'roles.view',
            'roles.manage',
            'access_rights.manage',
        ];

        $commerceFinanceSales = [
            'commerce_dashboard.view',
            'customers.manage',
            'sales_transactions.view',
            'sales_transactions.manage',
            'commerce_approvals.view',
            'commerce_approvals.manage',
            'commerce_managed.view',
            'commerce_managed.manage',
            'commerce_contracts.view',
            'commerce_contracts.manage',
            'commerce_catalog.view',
            'commerce_catalog.manage',
            'products.manage',
            'inventory.view',
            'inventory.manage',
            'coupons.manage',
            'commerce_notifications.view',
            'commerce_notifications.manage',
            'commerce_helpdesk.view',
            'commerce_helpdesk.create',
            'commerce_helpdesk.update',
            'commerce_helpdesk.delete',
            'reports.view',
        ];

        $commerceCustomerCare = [
            'commerce_dashboard.view',
            'sales_transactions.view',
            'sales_transactions.manage',
            'commerce_notifications.view',
            'commerce_notifications.manage',
            'commerce_helpdesk.view',
            'commerce_helpdesk.update',
        ];

        $commerceTechnicalSupport = [
            'commerce_dashboard.view',
            'sales_transactions.view',
            'sales_transactions.manage',
            'commerce_notifications.view',
            'commerce_notifications.manage',
            'commerce_helpdesk.view',
            'commerce_helpdesk.create',
            'commerce_helpdesk.update',
        ];

        $admin = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'sanctum'],
            ['description' => 'Super Admin']
        );
        $admin->description = 'Super Admin';
        $admin->save();
        $admin->syncPermissions(Permission::all());

        $financeAdmin = Role::firstOrCreate(
            ['name' => 'finance_admin', 'guard_name' => 'sanctum'],
            ['description' => 'Finance Admin']
        );
        $financeAdmin->description = 'Finance Admin';
        $financeAdmin->save();
        $financeAdmin->syncPermissions(array_merge($cmsFinanceSales, $commerceFinanceSales));

        $salesAdmin = Role::firstOrCreate(
            ['name' => 'sales_admin', 'guard_name' => 'sanctum'],
            ['description' => 'Sales Admin']
        );
        $salesAdmin->description = 'Sales Admin';
        $salesAdmin->save();
        $salesAdmin->syncPermissions(array_merge($cmsFinanceSales, $commerceFinanceSales));

        $customerCare = Role::firstOrCreate(
            ['name' => 'customer_care', 'guard_name' => 'sanctum'],
            ['description' => 'Customer Care']
        );
        $customerCare->description = 'Customer Care';
        $customerCare->save();
        $customerCare->syncPermissions($commerceCustomerCare);

        $technicalSupport = Role::firstOrCreate(
            ['name' => 'technical_support', 'guard_name' => 'sanctum'],
            ['description' => 'Technical Support']
        );
        $technicalSupport->description = 'Technical Support';
        $technicalSupport->save();
        $technicalSupport->syncPermissions($commerceTechnicalSupport);

        $editor = Role::firstOrCreate(
            ['name' => 'editor', 'guard_name' => 'sanctum'],
            ['description' => 'Editor']
        );
        $editor->syncPermissions([
            'dashboard.view',
            'pages.view',
            'pages.create',
            'pages.edit',
            'news.view',
            'news.create',
            'news.edit',
            'news_categories.view',
            'news_categories.create',
            'news_categories.edit',
            'albums.view',
            'albums.create',
            'albums.edit',
            'menus.view',
        ]);

        $marketingPermissions = [
            'dashboard.view',
            'pages.view',
            'pages.create',
            'pages.edit',
            'albums.view',
            'albums.create',
            'albums.edit',
            'file_manager.manage',
            'menus.view',
            'menus.create',
            'menus.edit',
            'commerce_dashboard.view',
            'customers.manage',
            'commerce_managed.view',
            'commerce_managed.manage',
            'commerce_notifications.view',
            'commerce_notifications.manage',
            'commerce_helpdesk.view',
            'commerce_helpdesk.create',
            'commerce_helpdesk.update',
        ];

        $marketing = Role::firstOrCreate(
            ['name' => 'marketing', 'guard_name' => 'sanctum'],
            ['description' => 'Marketing']
        );
        $marketing->description = 'Marketing';
        $marketing->save();
        $marketing->syncPermissions($marketingPermissions);

        $customer = Role::firstOrCreate(
            ['name' => 'customer', 'guard_name' => 'sanctum'],
            ['description' => 'Customer']
        );
        $customer->description = 'Customer';
        $customer->save();
        $customer->syncPermissions([]);
    }
}
