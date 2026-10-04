<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One speaker-labelled line of a transcript. `start_ms` is approximate (see Phase 6.B). */
class ConsultationTranscriptSegment extends Model
{
    protected $fillable = [
        'consultation_transcript_id',
        'speaker_role',
        'start_ms',
        'text',
        'order',
    ];

    protected $casts = [
        'start_ms' => 'integer',
        'order' => 'integer',
    ];

    public function transcript(): BelongsTo
    {
        return $this->belongsTo(ConsultationTranscript::class, 'consultation_transcript_id');
    }
}
