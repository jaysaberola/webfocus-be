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
        $billingInChargeRole = Role::where('name', 'billing_in_charge')->where('guard_name', 'sanctum')->firstOrFail();
        $marketingRole = Role::where('name', 'marketing')->where('guard_name', 'sanctum')->firstOrFail();

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

        $billingInCharge = User::firstOrCreate(
            ['email' => 'billing@webfocus.ph'],
            [
                'fname' => 'Billing',
                'lname' => 'In Charge',
                'password' => $defaultPassword,
                'is_active' => true,
            ]
        );
        $billingInCharge->syncRoles([$billingInChargeRole]);

        $billingInChargePeople = [
            ['email' => 'girlie.tabelon@webfocus.ph', 'fname' => 'Girlie', 'lname' => 'Tabelon'],
            ['email' => 'john.albert.fernandez@webfocus.ph', 'fname' => 'John Albert', 'lname' => 'Fernandez'],
            ['email' => 'king.philip.labado@webfocus.ph', 'fname' => 'King Philip', 'lname' => 'Labado'],
            ['email' => 'neriza.sulit@webfocus.ph', 'fname' => 'Neriza', 'lname' => 'Sulit'],
            ['email' => 'tehn-ten.guerzo@webfocus.ph', 'fname' => 'Tehn-Ten', 'lname' => 'Guerzo'],
        ];

        foreach ($billingInChargePeople as $person) {
            $user = User::query()
                ->where('email', $person['email'])
                ->orWhere(function ($query) use ($person) {
                    $query->where('fname', $person['fname'])->where('lname', $person['lname']);
                })
                ->first();

            if (!$user) {
                $user = User::create([
                    'email' => $person['email'],
                    'fname' => $person['fname'],
                    'lname' => $person['lname'],
                    'password' => $defaultPassword,
                    'is_active' => true,
                ]);
            } else {
                $user->fname = $person['fname'];
                $user->lname = $person['lname'];
                $user->is_active = true;
                $user->save();
            }

            $user->assignRole($billingInChargeRole);
        }

        $marketing = User::firstOrCreate(
            ['email' => 'marketing@webfocus.ph'],
            [
                'fname' => 'Marketing',
                'lname' => 'Staff',
                'password' => $defaultPassword,
                'is_active' => true,
            ]
        );
        $marketing->syncRoles([$marketingRole]);
    }
}
