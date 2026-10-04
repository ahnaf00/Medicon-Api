<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One speaker's FLAC chunk (private disk). `started_at_ms` is relative to the agent joining. */
class ConsultationAudioChunk extends Model
{
    protected $fillable = [
        'consultation_transcript_id',
        'user_id',
        'speaker_role',
        'file_path',
        'started_at_ms',
        'duration_ms',
    ];

    protected $casts = [
        'started_at_ms' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function transcript(): BelongsTo
    {
        return $this->belongsTo(ConsultationTranscript::class, 'consultation_transcript_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
