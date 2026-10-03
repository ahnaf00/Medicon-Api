<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-registered doctors start as `pending`; they get no doctor capabilities
 * until an admin marks their profile `verified`.
 */
class EnsureDoctorVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isVerifiedDoctor()) {
            return response()->json([
                'message' => 'Your doctor account has not been verified yet.',
            ], 403);
        }

        return $next($request);
    }
}
