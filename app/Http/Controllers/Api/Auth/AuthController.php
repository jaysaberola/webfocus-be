<?php

namespace App\Http\Controllers\Api\Auth;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Http\Resources\Auth\LoginResource;
use App\Models\User;
use App\Services\ClientOwnerRotator;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    private const CUSTOMER_ROLE = 'customer';

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $user = auth()->user();
        $user->load('roles');

        if ($user->hasRole(self::CUSTOMER_ROLE)) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => ['Customer accounts cannot log in to the admin portal. Please use the customer login page.'],
            ]);
        }

        //$user->tokens()->delete();

        $token = $user->createToken('cms-admin')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => new LoginResource($user),
        ]);
    }

    public function registerCustomer(Request $request)
    {
        $validated = $request->validate([
            'fname' => ['required', 'string', 'max:255'],
            'lname' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'mobile' => ['nullable', 'string', 'max:60'],
            'company' => ['nullable', 'string', 'max:255'],
        ]);

        Role::firstOrCreate(
            ['name' => self::CUSTOMER_ROLE, 'guard_name' => 'sanctum'],
            ['description' => 'Customer']
        );

        $fname = trim((string) $validated['fname']);
        $lname = trim((string) ($validated['lname'] ?? ''));
        $company = trim((string) ($validated['company'] ?? ''));
        if (User::isPlaceholderLastName($lname)) {
            $lname = '';
        }

        $user = User::create([
            'fname' => $fname,
            'lname' => $lname,
            'mname' => $company !== '' ? $company : null,
            'email' => $validated['email'],
            'mobile' => $validated['mobile'] ?? null,
            'contact_person' => $fname,
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);
        $user->assignRole(self::CUSTOMER_ROLE);
        $user->load('roles');
        app(ClientOwnerRotator::class)->assignOwnerToNewCustomer($user);
        $user->refresh();

        $token = $user->createToken('cms-customer')->plainTextToken;

        return response()->json([
            'message' => 'Customer account created successfully',
            'token' => $token,
            'user' => new LoginResource($user),
        ], 201);
    }

    public function customerLogin(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $user = auth()->user();

        if (! $user->hasRole(self::CUSTOMER_ROLE)) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => ['This account cannot sign in here. Please use the admin portal login.'],
            ]);
        }

        $token = $user->createToken('cms-customer')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new LoginResource($user),
        ]);
    }
}
