<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        /**
         * Ensure roles exist (already seeded, but safe check)
         */
        $adminRole  = Role::where('name', 'admin')->where('guard_name', 'sanctum')->firstOrFail();
        $editorRole = Role::where('name', 'editor')->where('guard_name', 'sanctum')->firstOrFail();
        $userRole   = Role::where('name', 'user')->where('guard_name', 'sanctum')->firstOrFail();

        /**
         * --------------------
         * ADMIN USER
         * --------------------
         */
        $admin = User::firstOrCreate(
            ['email' => 'admin@wsi.com'],
            [
                'fname' => 'Admin',
                'lname' => 'User',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        // ⭐ Assign ADMIN role (already has ALL permissions via RoleSeeder)
        $admin->syncRoles([$adminRole]);

        /**
         * --------------------
         * JOHN DOE (EDITOR)
         * --------------------
         */
        $john = User::firstOrCreate(
            ['email' => 'john@wsi.com'],
            [
                'fname' => 'John',
                'lname' => 'Doe',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        $john->syncRoles([$editorRole]);

        /**
         * --------------------
         * JANE DOE (USER)
         * --------------------
         */
        $jane = User::firstOrCreate(
            ['email' => 'jane@wsi.com'],
            [
                'fname' => 'Jane',
                'lname' => 'Doe',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        $jane->syncRoles([$userRole]);
    }
}
