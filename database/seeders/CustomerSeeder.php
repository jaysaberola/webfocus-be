<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class CustomerSeeder extends Seeder
{
    /**
     * Demo customer portal account (public site login).
     *
     * Email:    customer@webfocus.ph
     * Password: password
     */
    public function run(): void
    {
        $customerRole = Role::firstOrCreate(
            ['name' => 'customer', 'guard_name' => 'sanctum'],
            ['description' => 'Customer']
        );

        $customer = User::firstOrCreate(
            ['email' => 'customer@webfocus.ph'],
            [
                'fname' => 'Juan',
                'lname' => 'Dela Cruz',
                'mname' => 'Apex Global Corp',
                'mobile' => '+639175551234',
                'phone' => '81234567',
                'address_street' => 'BGC Taguig, Metro Manila',
                'address_city' => 'Taguig City',
                'address_province' => 'Metro Manila',
                'address_zip' => '1634',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        $customer->fill([
            'fname' => 'Juan',
            'lname' => 'Dela Cruz',
            'mname' => 'Apex Global Corp',
            'mobile' => '+639175551234',
            'phone' => '81234567',
            'address_street' => 'BGC Taguig, Metro Manila',
            'address_city' => 'Taguig City',
            'address_province' => 'Metro Manila',
            'address_zip' => '1634',
            'is_active' => true,
        ])->save();

        $customer->syncRoles([$customerRole]);
    }
}
