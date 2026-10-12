<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suspension is checked when a token is issued, but a token issued earlier
 * would otherwise keep working. `pending` (unverified doctor) is not blocked
 * here; that is EnsureDoctorVerified's job.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status === 'suspended') {
            return response()->json(['message' => 'Account is suspended'], 403);
        }

        return $next($request);
    }
}
