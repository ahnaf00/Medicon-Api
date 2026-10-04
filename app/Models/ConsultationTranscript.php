<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recording consent and transcription state for one video consultation.
 *
 * Consent is null until the participant chooses. Audio is only kept while
 * both `doctor_consent` and `patient_consent` are true.
 */
class ConsultationTranscript extends Model
{
    protected $fillable = [
        'appointment_id',
        'patient_consent',
        'patient_consent_at',
        'doctor_consent',
        'doctor_consent_at',
        'status',
        'error',
        'draft_summary',
        'language',
        'transcribed_at',
    ];

    protected $casts = [
        'patient_consent' => 'boolean',
        'patient_consent_at' => 'datetime',
        'doctor_consent' => 'boolean',
        'doctor_consent_at' => 'datetime',
        'draft_summary' => 'array',
        'transcribed_at' => 'datetime',
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

    public function consentFor(string $role): ?bool
    {
        return $this->{$role.'_consent'};
    }

    public function bothConsented(): bool
    {
        return $this->doctor_consent === true && $this->patient_consent === true;
    }
}
