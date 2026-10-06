<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One speaker's FLAC chunk (private disk).
 *
 * `started_at_ms` is relative to the agent joining the room, and restarts at 0
 * for every room session; `session_started_at_ms` (epoch ms) is that join time.
 */
class ConsultationAudioChunk extends Model
{
    protected $fillable = [
        'consultation_transcript_id',
        'user_id',
        'speaker_role',
        'session_started_at_ms',
        'file_path',
        'started_at_ms',
        'duration_ms',
    ];

    protected $casts = [
        'session_started_at_ms' => 'integer',
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

    /** When the chunk's first sample was recorded, in epoch milliseconds. */
    public function absoluteStartMs(): int
    {
        return (int) $this->session_started_at_ms + $this->started_at_ms;
    }

    public function absoluteEndMs(): int
    {
        return $this->absoluteStartMs() + $this->duration_ms;
    }
}
