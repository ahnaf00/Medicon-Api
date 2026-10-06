<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the internal endpoints used by the transcriber agent
 * (`tools/transcriber`). The agent is not a user, so it has no Sanctum token;
 * it sends the shared `TRANSCRIBER_SECRET` as `X-Transcriber-Secret`.
 */
class VerifyTranscriberSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.transcriber.secret');

        // A missing or weak secret disables the endpoints rather than opening them.
        if (strlen($secret) < 32) {
            return response()->json(['message' => 'Transcription is not configured on this server.'], 503);
        }

        if (! hash_equals($secret, (string) $request->header('X-Transcriber-Secret'))) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
