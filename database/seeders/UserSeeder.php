<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'sanctum')->firstOrFail();
        $editorRole = Role::where('name', 'editor')->where('guard_name', 'sanctum')->firstOrFail();
        $financeAdminRole = Role::where('name', 'finance_admin')->where('guard_name', 'sanctum')->firstOrFail();
        $salesAdminRole = Role::where('name', 'sales_admin')->where('guard_name', 'sanctum')->firstOrFail();
        $technicalSupportRole = Role::where('name', 'technical_support')->where('guard_name', 'sanctum')->firstOrFail();
        $customerCareRole = Role::where('name', 'customer_care')->where('guard_name', 'sanctum')->firstOrFail();

        $defaultPassword = Hash::make('password');

        $admin = User::firstOrCreate(
            ['email' => 'admin@wsi.com'],
            [
                'fname' => 'Super',
                'lname' => 'Admin',
                'password' => $defaultPassword,
                'is_active' => true,
            ]
        );
        $admin->syncRoles([$adminRole]);

        $john = User::firstOrCreate(
            ['email' => 'john@wsi.com'],
            [
                'fname' => 'John',
                'lname' => 'Doe',
                'password' => $defaultPassword,
                'is_active' => true,
            ]
        );
        $john->syncRoles([$editorRole]);

        $financeAdmin = User::firstOrCreate(
            ['email' => 'finance@webfocus.ph'],
            [
                'fname' => 'Finance',
                'lname' => 'Admin',
                'password' => $defaultPassword,
                'is_active' => true,
            ]
        );
        $financeAdmin->syncRoles([$financeAdminRole]);

        $salesAdmin = User::firstOrCreate(
            ['email' => 'sales@webfocus.ph'],
            [
                'fname' => 'Sales',
                'lname' => 'Admin',
                'password' => $defaultPassword,
                'is_active' => true,
            ]
        );
        $salesAdmin->syncRoles([$salesAdminRole]);

        $technicalSupport = User::firstOrCreate(
            ['email' => 'support@webfocus.ph'],
            [
                'fname' => 'Technical',
                'lname' => 'Support',
                'password' => $defaultPassword,
                'is_active' => true,
            ]
        );
        $technicalSupport->syncRoles([$technicalSupportRole]);

        $customerCare = User::firstOrCreate(
            ['email' => 'care@webfocus.ph'],
            [
                'fname' => 'Customer',
                'lname' => 'Care',
                'password' => $defaultPassword,
                'is_active' => true,
            ]
        );
        $customerCare->syncRoles([$customerCareRole]);
    }
}
