<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_accounts_cannot_login_to_admin_side(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $customer = User::create([
            'fname' => 'Customer',
            'lname' => 'User',
            'email' => 'customer@example.com',
            'password' => Hash::make('password'),
        ]);

        Role::create([
            'name' => 'customer',
            'guard_name' => 'sanctum',
        ]);

        $customer->assignRole('customer');

        $this->postJson('http://localhost/api/login', [
            'email' => 'customer@example.com',
            'password' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $customer->id,
            'name' => 'cms-admin',
        ]);
    }
}
