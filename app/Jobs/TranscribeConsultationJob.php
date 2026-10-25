<?php

namespace App\Jobs;

use App\Models\ConsultationTranscript;
use App\Services\ConsultationTranscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Starts (or resumes) a transcript's transcription: one job per chunk that has
 * no result yet, so a long call never has to fit in one job and a retry does
 * not redo finished chunks (audit G8 / REC-003).
 */
class TranscribeConsultationJob implements ShouldQueue
{
    use Queueable;

    /** One attempt: the doctor retries explicitly, which re-queues the job. */
    public int $tries = 1;

    /** Only reads the database and queues jobs. */
    public int $timeout = 60;

    public function __construct(public ConsultationTranscript $transcript)
    {
    }

    public function handle(ConsultationTranscriptionService $service): void
    {
        $transcript = $this->transcript->fresh();

        // A stale or duplicate job must not redo a finished transcript.
        if ($transcript?->status !== 'transcribing') {
            return;
        }

        if (! $service->canStart($transcript)) {
            return;
        }

        $pending = $transcript->audioChunks()->whereNull('transcribed_at')->orderBy('id')->get();

        if ($pending->isEmpty()) {
            // Every chunk was done before (e.g. a retry after the last one failed late).
            if ($service->assembleIfComplete($transcript) === 'summarizing') {
                SummarizeTranscriptJob::dispatch($transcript->fresh());
            }

            return;
        }

        // In time order, so a synchronous queue (tests) processes them as a call would.
        $pending->sortBy(fn ($chunk) => [$chunk->absoluteStartMs(), $chunk->id])
            ->each(fn ($chunk) => TranscribeAudioChunkJob::dispatch($chunk));
    }

    /**
     * Covers what the service cannot catch itself (worker timeout, killed process).
     */
    public function failed(?Throwable $exception): void
    {
        app(ConsultationTranscriptionService::class)
            ->markFailed($this->transcript, ConsultationTranscriptionService::GENERIC_ERROR);
    }
}
