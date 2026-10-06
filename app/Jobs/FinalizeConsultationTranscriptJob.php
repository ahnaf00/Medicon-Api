<?php

namespace App\Jobs;

use App\Models\ConsultationTranscript;
use App\Services\ConsultationTranscriptPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fallback queued (with a delay) when the doctor ends a call, for when the
 * transcriber agent never reports the room closed: it was not running, or it
 * crashed. A no-op if the agent's `/complete` already finalised the transcript.
 */
class FinalizeConsultationTranscriptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public ConsultationTranscript $transcript)
    {
    }

    public function handle(ConsultationTranscriptPipeline $pipeline): void
    {
        $pipeline->finalize($this->transcript);
    }
}
