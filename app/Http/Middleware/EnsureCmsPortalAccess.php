<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCmsPortalAccess
{
    private const COMMERCE_ONLY_ROLES = [
        'technical_support',
        'customer_care',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasAnyRole(self::COMMERCE_ONLY_ROLES)) {
            return response()->json([
                'message' => 'Your account can only access the Commerce Control Center.',
            ], 403);
        }

        return $next($request);
    }
}
