<?php

namespace App\Services;

use App\Models\DoctorProfile;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Ranks verified doctors of one specialty for symptom search.
 *
 * Score (0–1) =
 *   0.35 × how soon the next free slot is (the signal patients actually feel)
 *   0.25 × rating out of 5
 *   0.20 × completed consultations (log-scaled, saturates at ~100)
 *   0.20 × experience (saturates at 30 years)
 * Ties break on doctor id so the order is stable.
 */
class DoctorRankingService
{
    private const WEIGHT_AVAILABILITY = 0.35;
    private const WEIGHT_RATING = 0.25;
    private const WEIGHT_CONSULTATIONS = 0.20;
    private const WEIGHT_EXPERIENCE = 0.20;

    private const LOOKAHEAD_DAYS = 14;

    public function __construct(private DoctorSlotService $slots) {}

    /**
     * When no verified doctor practises the requested specialty, General Medicine
     * doctors are returned instead and `matchedSpecialty` is false.
     *
     * @return array{doctors: Collection<int, DoctorProfile>, matchedSpecialty: bool}
     */
    public function rank(string $specialty): array
    {
        $doctors = $this->candidates($specialty);
        $matched = $doctors->isNotEmpty();

        if (! $matched && $specialty !== SymptomTriageService::DEFAULT_SPECIALTY) {
            $doctors = $this->candidates(SymptomTriageService::DEFAULT_SPECIALTY);
        }

        $next = $this->slots->nextAvailable($doctors->pluck('user_id')->all(), self::LOOKAHEAD_DAYS);

        $scored = $doctors->map(function (DoctorProfile $doctor) use ($next) {
            $nextAt = $next[$doctor->user_id] ?? null;
            // Response-only attribute, read by DoctorProfileResource; never saved.
            $doctor->setAttribute('next_available_at', $nextAt?->toIso8601String());

            return ['doctor' => $doctor, 'score' => $this->score($doctor, $nextAt)];
        });

        $ranked = $scored
            ->sort(fn ($a, $b) => [$b['score'], $a['doctor']->user_id] <=> [$a['score'], $b['doctor']->user_id])
            ->pluck('doctor')
            ->values();

        return ['doctors' => $ranked, 'matchedSpecialty' => $matched];
    }

    /**
     * @return Collection<int, DoctorProfile>
     */
    private function candidates(string $specialty): Collection
    {
        return DoctorProfile::query()
            ->where('verification_status', 'verified')
            ->whereRaw('LOWER(specialty) = ?', [mb_strtolower($specialty)])
            ->whereHas('user', fn ($q) => $q->role('doctor'))
            ->with('user')
            ->withCount(['appointments as completed_consultations_count' => fn ($q) => $q->where('status', 'completed')])
            ->get();
    }

    private function score(DoctorProfile $doctor, ?Carbon $nextAt): float
    {
        // 1.0 for a slot right now, 0.5 a day away, ~0.13 a week away, 0 for none.
        $availability = 0.0;
        if ($nextAt !== null) {
            $hours = max(0, now()->diffInMinutes($nextAt, true) / 60);
            $availability = 1 / (1 + $hours / 24);
        }

        $rating = min(max((float) $doctor->rating, 0), 5) / 5;
        $consultations = min(1, log(1 + (int) $doctor->completed_consultations_count) / log(101));
        $experience = min(max((int) $doctor->experience_years, 0), 30) / 30;

        return self::WEIGHT_AVAILABILITY * $availability
            + self::WEIGHT_RATING * $rating
            + self::WEIGHT_CONSULTATIONS * $consultations
            + self::WEIGHT_EXPERIENCE * $experience;
    }
}
