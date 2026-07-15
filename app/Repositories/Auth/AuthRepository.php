<?php

namespace App\Repositories\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Repositories\Auth\AuthRepositoryInterface;

class AuthRepository implements AuthRepositoryInterface
{
    public function login(array $credentials): ?User
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return null;
        }
        
        return $user;
    }
}