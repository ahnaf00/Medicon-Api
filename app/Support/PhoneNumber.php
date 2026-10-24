<?php

namespace App\Support;

/**
 * Bangladeshi mobile numbers in one stored form, +8801XXXXXXXXX (audit F13), so
 * "01711000001", "8801711000001" and "+880 1711-000001" are the same account.
 */
final class PhoneNumber
{
    /** What a valid stored number looks like (operator codes 013–019). */
    public const PATTERN = '/^\+8801[3-9]\d{8}$/';

    public const INVALID_MESSAGE = 'Enter a Bangladeshi mobile number, e.g. 01711000000.';

    /** The +880 form of a Bangladeshi mobile number, or null if it isn't one. */
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);

        $local = match (true) {
            strlen($digits) === 13 && str_starts_with($digits, '880') => substr($digits, 3),
            strlen($digits) === 11 && str_starts_with($digits, '0') => substr($digits, 1),
            strlen($digits) === 10 => $digits,
            default => null,
        };

        $phone = $local === null ? null : '+880'.$local;

        return $phone !== null && preg_match(self::PATTERN, $phone) ? $phone : null;
    }
}
