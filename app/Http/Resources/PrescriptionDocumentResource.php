<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything the prescription letterhead needs, in one payload. The app's
 * document view and the PDF template both render from this, so they agree on
 * age, dose pattern and dates.
 */
class PrescriptionDocumentResource extends JsonResource
{
    /** Dates are stored in UTC; the document shows the clinic's calendar date. */
    public const DISPLAY_TIMEZONE = 'Asia/Dhaka';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $issuedAt = $this->created_at?->copy()->setTimezone(self::DISPLAY_TIMEZONE);
        $doctorProfile = $this->doctor?->doctorProfile;
        $patientProfile = $this->patient?->patientProfile;

        return [
            'id'                => $this->id,
            'issuedAt'          => $this->created_at?->toIso8601String(),
            'issuedDate'        => $issuedAt?->format('M j, Y'),
            'diagnosisSummary'  => $this->diagnosis_summary,
            'doctor' => [
                'name'                  => $this->doctor?->name,
                'qualification'         => $doctorProfile?->qualification,
                'specialty'             => $doctorProfile?->specialty,
                'hospitalName'          => $doctorProfile?->hospital_name,
                'bmdcRegistrationNo'    => $doctorProfile?->bmdc_registration_no,
            ],
            'patient' => [
                'name'      => $this->patient?->name,
                'gender'    => $patientProfile?->gender,
                'age'       => self::formatAge($patientProfile?->date_of_birth, $issuedAt),
                'weightKg'  => $patientProfile?->weight_kg !== null ? (float) $patientProfile->weight_kg : null,
            ],
            'tests' => $this->tests->map(fn ($test) => [
                'name'          => $test->name,
                'instructions'  => $test->instructions,
            ])->values(),
            'medicines' => $this->items->map(fn ($item) => [
                'id'            => $item->id,
                'name'          => $item->medicine_name,
                'dosage'        => $item->dosage,
                'pattern'       => self::dosePattern($item->dosage_schedule),
                'durationDays'  => $item->duration_days,
                'instructions'  => $item->instructions,
            ])->values(),
            'followUpDate'      => $this->follow_up_date?->format('Y-m-d'),
            'advice'            => $this->advice,
        ];
    }

    /**
     * Age at the issue date as `35y 4m 12d`, or null without a date of birth.
     */
    public static function formatAge(?CarbonInterface $dateOfBirth, ?CarbonInterface $on): ?string
    {
        if ($dateOfBirth === null || $on === null) {
            return null;
        }

        // Compare calendar dates only: put the issue date's Y-m-d on the birth date's clock.
        $start = $dateOfBirth->copy()->startOfDay();
        $diff = $start->diff($start->copy()->setDate($on->year, $on->month, $on->day));

        return $diff->invert ? null : "{$diff->y}y {$diff->m}m {$diff->d}d";
    }

    /**
     * `morning`/`noon`/`night` schedule → `1+0+1`. Null when no schedule was given.
     *
     * @param  array<string, mixed>|null  $schedule
     */
    public static function dosePattern(?array $schedule): ?string
    {
        if (empty($schedule)) {
            return null;
        }

        return collect(['morning', 'noon', 'night'])
            ->map(fn (string $slot) => empty($schedule[$slot]) ? '0' : '1')
            ->implode('+');
    }
}
