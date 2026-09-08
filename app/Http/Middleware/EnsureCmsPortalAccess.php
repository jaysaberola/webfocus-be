<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCmsPortalAccess
{
    private const CUSTOMER_ROLE = 'customer';

    private const COMMERCE_ONLY_ROLES = [
        'technical_support',
        'customer_care',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasRole(self::CUSTOMER_ROLE)) {
            return response()->json([
                'message' => 'Customer accounts cannot access the admin portal.',
            ], 403);
        }

        if ($user && $user->hasAnyRole(self::COMMERCE_ONLY_ROLES)) {
            return response()->json([
                'message' => 'Your account can only access the Commerce Control Center.',
            ], 403);
        }

        return $next($request);
    }
}
