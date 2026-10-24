<?php

namespace App\Services;

use App\Models\PhoneOtp;
use Illuminate\Support\Facades\Hash;

/**
 * Issues and checks the one-time codes for login and for changing a phone
 * number (audit F12), so both follow the same expiry and attempt rules.
 *
 * No SMS provider is integrated: the only working mode is the explicit demo
 * mode with a fixed code.
 */
class PhoneOtpService
{
    public const DEMO_OTP = '123456';

    public const OK = 'ok';
    public const EXPIRED = 'expired';
    public const LOCKED = 'locked';
    public const WRONG = 'wrong';

    public function canSend(): bool
    {
        return (bool) config('services.otp.demo_mode');
    }

    public function issue(string $phone): void
    {
        PhoneOtp::updateOrCreate(
            ['phone' => $phone],
            [
                'otp' => Hash::make(self::DEMO_OTP),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(5),
            ]
        );
    }

    /** One of the constants above. A correct code is used up. */
    public function check(string $phone, string $otp): string
    {
        $record = PhoneOtp::where('phone', $phone)->first();

        if (! $record || now()->greaterThan($record->expires_at)) {
            return self::EXPIRED;
        }

        if ($record->attempts >= 3) {
            $record->delete();

            return self::LOCKED;
        }

        if (! Hash::check($otp, $record->otp)) {
            $record->increment('attempts');

            return self::WRONG;
        }

        $record->delete();

        return self::OK;
    }

    /** The response for a failed check, the same for login and phone change. */
    public static function failure(string $result): array
    {
        return match ($result) {
            self::LOCKED => ['Max attempts reached. Request a new OTP.', 429],
            self::WRONG => ['Invalid OTP.', 400],
            default => ['OTP expired or invalid.', 400],
        };
    }
}
