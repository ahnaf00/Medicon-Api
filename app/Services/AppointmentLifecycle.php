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
        'scheduled'   => ['in_progress', 'no_show'],
        'in_progress' => ['completed'],
    ];

    public function canTransition(Appointment $appointment, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$appointment->status] ?? [], true);
    }

    public function rejectionMessage(Appointment $appointment, string $to): string
    {
        return "An appointment that is {$appointment->status} cannot be marked {$to}.";
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
