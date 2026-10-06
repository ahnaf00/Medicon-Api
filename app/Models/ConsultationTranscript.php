<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recording consent and transcription state for one video consultation.
 *
 * Consent is null until the participant chooses. Audio is only kept while
 * both `doctor_consent` and `patient_consent` are true; every change is also
 * logged in `consentEvents` so chunks can be checked against the history.
 *
 * Status: awaiting_call → recording (first chunk) → transcribing →
 * summarizing → ready (`draft_summary` set; null when nothing was said),
 * or failed (audio kept, can be retried) / skipped (`skip_reason`).
 */
class ConsultationTranscript extends Model
{
    public const SKIP_NO_CONSENT = 'no_consent';

    public const SKIP_NO_AUDIO = 'no_audio';

    /** Statuses in which the call may still produce audio. */
    public const OPEN_STATUSES = ['awaiting_call', 'recording'];

    protected $fillable = [
        'appointment_id',
        'patient_consent',
        'patient_consent_at',
        'doctor_consent',
        'doctor_consent_at',
        'status',
        'error',
        'skip_reason',
        'draft_summary',
        'language',
        'transcribed_at',
        'agent_completed_at',
    ];

    protected $casts = [
        'patient_consent' => 'boolean',
        'patient_consent_at' => 'datetime',
        'doctor_consent' => 'boolean',
        'doctor_consent_at' => 'datetime',
        'draft_summary' => 'array',
        'transcribed_at' => 'datetime',
        'agent_completed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'awaiting_call',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function audioChunks(): HasMany
    {
        return $this->hasMany(ConsultationAudioChunk::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(ConsultationTranscriptSegment::class)->orderBy('order');
    }

    public function consentEvents(): HasMany
    {
        return $this->hasMany(ConsultationConsentEvent::class);
    }

    public function consentFor(string $role): ?bool
    {
        return $this->{$role.'_consent'};
    }

    public function bothConsented(): bool
    {
        return $this->doctor_consent === true && $this->patient_consent === true;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Store a participant's choice and append it to the consent log.
     */
    public function recordConsent(string $role, bool $consent): void
    {
        $now = now();

        $this->update([
            "{$role}_consent" => $consent,
            "{$role}_consent_at" => $now,
        ]);

        $this->consentEvents()->create([
            'role' => $role,
            'consent' => $consent,
            'occurred_at_ms' => $now->getTimestampMs(),
        ]);
    }

    /**
     * Whether both participants had consented for the whole of [$fromMs, $toMs]
     * (epoch milliseconds).
     *
     * The window is shrunk by `$graceMs` at each end: the agent only learns of
     * a grant or a withdrawal a moment after it is stored, so a chunk may start
     * just before a grant is visible to it, or end just after a withdrawal.
     * A chunk shorter than twice the grace is checked at its midpoint.
     */
    public function consentHeldThroughout(int $fromMs, int $toMs, int $graceMs): bool
    {
        $from = $fromMs + $graceMs;
        $to = $toMs - $graceMs;
        if ($to < $from) {
            $from = $to = intdiv($fromMs + $toMs, 2);
        }

        $events = $this->consentEvents()->orderBy('occurred_at_ms')->orderBy('id')->get();

        foreach (['doctor', 'patient'] as $role) {
            $state = null;

            foreach ($events->where('role', $role) as $event) {
                if ($event->occurred_at_ms <= $from) {
                    $state = $event->consent;
                } elseif ($event->occurred_at_ms <= $to && ! $event->consent) {
                    return false;
                }
            }

            if ($state !== true) {
                return false;
            }
        }

        return true;
    }

    /** Whether both participants ever agreed at the same time. */
    public function everBothConsented(): bool
    {
        $state = ['doctor' => false, 'patient' => false];

        foreach ($this->consentEvents()->orderBy('occurred_at_ms')->orderBy('id')->get() as $event) {
            $state[$event->role] = $event->consent;
            if ($state['doctor'] && $state['patient']) {
                return true;
            }
        }

        return false;
    }
}
