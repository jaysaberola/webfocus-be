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
                'mobile' => '+63 917 555 1234',
                'address_street' => 'Antel Global Corporate Center, Ortigas Center',
                'address_city' => 'Pasig City',
                'address_province' => 'Metro Manila',
                'address_zip' => '1605',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        $customer->syncRoles([$customerRole]);
    }
}
