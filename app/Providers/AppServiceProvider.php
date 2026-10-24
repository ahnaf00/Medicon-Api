<?php

namespace App\Providers;

use App\Support\PhoneNumber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Auth routes: 6 attempts per minute per IP
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(6)->by($request->ip());
        });

        // Per phone number, whatever the IP (audit SEC-005): each new code resets the
        // wrong-guess counter, so sends must be limited per phone, and so must checks.
        RateLimiter::for('otp-send', fn (Request $request) => Limit::perMinutes(10, 3)
            ->by('otp-send|'.self::phoneKey($request))
            ->response(fn () => response()->json(['message' => 'Too many codes requested for this number. Try again in a few minutes.'], 429)));
        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinutes(10, 10)
            ->by('otp-verify|'.self::phoneKey($request))
            ->response(fn () => response()->json(['message' => 'Too many attempts for this number. Try again in a few minutes.'], 429)));

        // AI triage: 10 attempts per minute per user
        RateLimiter::for('ai', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Global authenticated: 60 requests per minute per user
        RateLimiter::for('global', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }

    /** The stored +880 form, so "01711000001" and "+880 1711-000001" share a limit. */
    private static function phoneKey(Request $request): string
    {
        $phone = PhoneNumber::normalize($request->input('phone'));
        if ($phone !== null) {
            return $phone;
        }

        $digits = preg_replace('/\D+/', '', (string) $request->input('phone'));

        return $digits !== '' ? $digits : 'ip:'.$request->ip();
    }
}
