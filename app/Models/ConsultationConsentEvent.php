<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recording-consent choice. Append-only: the full history is what lets an
 * audio chunk be checked against consent at the time it was recorded.
 */
class ConsultationConsentEvent extends Model
{
    protected $fillable = [
        'consultation_transcript_id',
        'role',
        'consent',
        'occurred_at_ms',
    ];

    protected $casts = [
        'consent' => 'boolean',
        'occurred_at_ms' => 'integer',
    ];

    public function transcript(): BelongsTo
    {
        return $this->belongsTo(ConsultationTranscript::class, 'consultation_transcript_id');
    }
}
