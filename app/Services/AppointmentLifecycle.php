<?php

namespace App\Services;

use App\Jobs\FinalizeConsultationTranscriptJob;
use App\Models\Appointment;
use LogicException;

/**
 * The visit lifecycle, shared by `PATCH /appointments/{id}/status` and the
 * video-call endpoints so both apply the same rules and timestamps.
 * Authorization is the caller's job (AppointmentPolicy::updateStatus).
 */
class AppointmentLifecycle
{
    /** Allowed status moves: from => [to, ...]. */
    private const TRANSITIONS = [
        'scheduled'   => ['in_progress', 'no_show', 'expired'],
        'in_progress' => ['completed'],
    ];

    /** The doctor may start a visit this many minutes before its time (audit F10)... */
    public const START_EARLY_MINUTES = 15;

    /** ...and until this many minutes after it; an unstarted visit then expires. */
    public const START_LATE_MINUTES = 120;

    /** A visit still "in progress" this long after it started is closed automatically. */
    public const MAX_VISIT_MINUTES = 240;

    /** The patient may cancel or move a visit until this many minutes before it. */
    public const PATIENT_CHANGE_CUTOFF_MINUTES = 60;

    public function canTransition(Appointment $appointment, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$appointment->status] ?? [], true)
            && $this->timeProblem($appointment, $to) === null;
    }

    public function rejectionMessage(Appointment $appointment, string $to): string
    {
        if (! in_array($to, self::TRANSITIONS[$appointment->status] ?? [], true)) {
            return "An appointment that is {$appointment->status} cannot be marked {$to}.";
        }

        return $this->timeProblem($appointment, $to) ?? '';
    }

    /** Whether the patient is still early enough to cancel or move this visit. */
    public static function patientMayChange(Appointment $appointment): bool
    {
        return now()->lt($appointment->appointment_datetime->copy()
            ->subMinutes(self::PATIENT_CHANGE_CUTOFF_MINUTES));
    }

    /** Why `$to` is not allowed at this moment, or null when the time is fine. */
    private function timeProblem(Appointment $appointment, string $to): ?string
    {
        $start = $appointment->appointment_datetime;
        if (! $start) {
            return null;
        }

        $clock = fn ($time) => $time->copy()->setTimezone(DoctorSlotService::timezone())->format('g:i A');

        return match ($to) {
            'in_progress' => match (true) {
                now()->lt($start->copy()->subMinutes(self::START_EARLY_MINUTES)) =>
                    "This visit is at {$clock($start)}. It can be started from "
                    .$clock($start->copy()->subMinutes(self::START_EARLY_MINUTES)).'.',
                now()->gt($start->copy()->addMinutes(self::START_LATE_MINUTES)) =>
                    'This visit can no longer be started: it was due at '.$clock($start).'.',
                default => null,
            },
            'no_show' => now()->lt($start)
                ? "A visit can be marked no-show only after its time ({$clock($start)})."
                : null,
            'expired' => now()->lte($start->copy()->addMinutes(self::START_LATE_MINUTES))
                ? 'This visit can still be started.'
                : null,
            default => null,
        };
    }

    /**
     * Starting stamps `started_at`; completing stamps `ended_at`, writes
     * `duration_minutes` and queues the transcript fallback (video visits).
     */
    public function transition(Appointment $appointment, string $to): Appointment
    {
        if (! $this->canTransition($appointment, $to)) {
            throw new LogicException($this->rejectionMessage($appointment, $to));
        }

        $changes = ['status' => $to];

        if ($to === 'in_progress') {
            $changes['started_at'] = now();
        }

        if ($to === 'completed') {
            $endedAt = now();
            $changes['ended_at'] = $endedAt;
            $changes['duration_minutes'] = $appointment->started_at
                ? max(1, (int) round($appointment->started_at->diffInMinutes($endedAt, true)))
                : null;
        }

        $appointment->update($changes);

        // A video visit's transcript is normally started by the transcriber
        // agent's /complete once its uploads are done; this covers an agent
        // that never reports back, however the visit was completed.
        if ($to === 'completed' && $transcript = $appointment->transcript) {
            FinalizeConsultationTranscriptJob::dispatch($transcript)
                ->delay(now()->addSeconds((int) config('services.transcriber.finalize_delay_seconds')));
        }

        return $appointment;
    }
}
