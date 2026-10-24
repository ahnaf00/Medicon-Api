<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Compares medicine names with the allergies written on the patient's profile
 * (audit G1 / CLIN-005).
 *
 * This is a name match only: "Penicillin" is caught in "Penicillin V", but not
 * in "Amoxicillin" (same drug class). The app says so wherever it shows the
 * result; it is not a drug-class or cross-reactivity check.
 */
class AllergyCheck
{
    /** Profile entries that mean "no allergies". */
    private const NONE = ['none', 'no', 'nil', 'n/a', 'na', 'nka', 'nkda', 'no known allergies', 'no known drug allergies', 'not known', 'unknown'];

    /**
     * @param  array<int, string>  $medicineNames
     * @return array<int, array{medicine: string, allergy: string}>
     */
    public static function conflicts(?string $allergies, array $medicineNames): array
    {
        $allergens = self::allergens($allergies);
        if ($allergens === []) {
            return [];
        }

        $found = [];
        foreach ($medicineNames as $medicine) {
            $name = self::normalise($medicine);
            foreach ($allergens as $allergen) {
                if (self::mentions($name, $allergen) || self::mentions($allergen, self::firstWord($name))) {
                    $found[] = ['medicine' => trim($medicine), 'allergy' => $allergen];
                }
            }
        }

        return $found;
    }

    /** @return array<int, string> */
    public static function allergens(?string $allergies): array
    {
        if ($allergies === null || trim($allergies) === '') {
            return [];
        }

        return collect(preg_split('/[,;\n\/]+|\band\b/i', $allergies))
            ->map(fn ($part) => self::normalise((string) $part))
            // "Penicillin (rash)" → "penicillin": the reaction is not part of the name.
            ->map(fn ($part) => trim(preg_replace('/\(.*?\)/', '', $part)))
            ->filter(fn ($part) => mb_strlen($part) >= 3 && ! in_array($part, self::NONE, true))
            ->unique()
            ->values()
            ->all();
    }

    private static function normalise(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower($value)));
    }

    private static function firstWord(string $name): string
    {
        return explode(' ', $name)[0] ?? '';
    }

    /** Whole-word match, so "ace" does not match "paracetamol". */
    private static function mentions(string $haystack, string $needle): bool
    {
        if (mb_strlen($needle) < 3) {
            return false;
        }

        return (bool) preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $haystack);
    }
}
