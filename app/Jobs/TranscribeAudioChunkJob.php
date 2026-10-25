<?php

namespace App\Jobs;

use App\Models\ConsultationAudioChunk;
use App\Services\ConsultationTranscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Transcribes one audio chunk and stores the result on it. The job that stores
 * the last missing result assembles the transcript (audit G8 / REC-003).
 */
class TranscribeAudioChunkJob implements ShouldQueue
{
    use Queueable;

    /** One attempt: the doctor retries explicitly, which re-queues only unfinished chunks. */
    public int $tries = 1;

    /** One Gemini call of up to 120 s, plus one retry. Keep the queue's retry_after above this. */
    public int $timeout = 300;

    public function __construct(public ConsultationAudioChunk $chunk)
    {
    }

    public function handle(ConsultationTranscriptionService $service): void
    {
        $chunk = $this->chunk->fresh();
        $transcript = $chunk?->transcript;

        // Failed or finished meanwhile: don't spend another Gemini call.
        if (! $chunk || $transcript?->status !== 'transcribing') {
            return;
        }

        if ($chunk->transcribed_at === null && ! $service->transcribeChunk($chunk)) {
            return;
        }

        if ($service->assembleIfComplete($transcript) === 'summarizing') {
            SummarizeTranscriptJob::dispatch($transcript->fresh());
        }
    }

    /**
     * Covers what the service cannot catch itself (worker timeout, killed process).
     */
    public function failed(?Throwable $exception): void
    {
        if ($transcript = $this->chunk->fresh()?->transcript) {
            app(ConsultationTranscriptionService::class)
                ->markFailed($transcript, ConsultationTranscriptionService::GENERIC_ERROR);
        }
    }
}
