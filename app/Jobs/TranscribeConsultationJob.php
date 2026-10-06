<?php

namespace App\Jobs;

use App\Models\ConsultationTranscript;
use App\Services\ConsultationTranscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TranscribeConsultationJob implements ShouldQueue
{
    use Queueable;

    /** One attempt: the doctor retries explicitly, which re-queues the job. */
    public int $tries = 1;

    /**
     * Every chunk is a separate Gemini call (up to 120 s each). Run the worker
     * with `--timeout=600` and keep DB_QUEUE_RETRY_AFTER above this value.
     */
    public int $timeout = 600;

    public function __construct(public ConsultationTranscript $transcript)
    {
    }

    public function handle(ConsultationTranscriptionService $service): void
    {
        // A stale or duplicate job must not redo a finished transcript.
        if ($this->transcript->fresh()?->status !== 'transcribing') {
            return;
        }

        $service->transcribe($this->transcript);

        if ($this->transcript->fresh()?->status === 'summarizing') {
            SummarizeTranscriptJob::dispatch($this->transcript);
        }
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
