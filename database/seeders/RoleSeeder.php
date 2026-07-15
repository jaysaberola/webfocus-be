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
        // Clear cached roles & permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        
        $admin = Role::firstOrCreate([
            'name' => 'admin',
            'description' => 'Administrator',
            'guard_name' => 'sanctum',
        ]);

        $admin->syncPermissions(Permission::all());

        $editor = Role::firstOrCreate([
            'name' => 'editor',
            'description' => 'Editor',
            'guard_name' => 'sanctum',
        ]);

        $editor->syncPermissions([
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

        $staff = Role::firstOrCreate([
            'name' => 'staff',
            'description' => 'Staff',
            'guard_name' => 'sanctum',
        ]);

        $staff->syncPermissions([
            'pages.view',
            'news.view',
            'albums.view',
            'menus.view',
            'users.view',
        ]);

        $user = Role::firstOrCreate([
            'name' => 'user',
            'description' => 'User',
            'guard_name' => 'sanctum',
        ]);

        $user->syncPermissions([]);

        $customer = Role::firstOrCreate(
            ['name' => 'customer', 'guard_name' => 'sanctum'],
            ['description' => 'Customer']
        );

        $customer->syncPermissions([]);
    }
}
