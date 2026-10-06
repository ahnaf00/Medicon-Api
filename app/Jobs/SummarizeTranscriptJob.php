<?php

namespace App\Jobs;

use App\Models\ConsultationTranscript;
use App\Services\TranscriptSummaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SummarizeTranscriptJob implements ShouldQueue
{
    use Queueable;

    /** One attempt: the doctor retries explicitly, which re-queues the job. */
    public int $tries = 1;

    /** Above the service's 90 s HTTP timeout plus one retry. */
    public int $timeout = 300;

    public function __construct(public ConsultationTranscript $transcript)
    {
    }

    public function handle(TranscriptSummaryService $service): void
    {
        if ($this->transcript->fresh()?->status !== 'summarizing') {
            return;
        }

        $service->summarize($this->transcript);
    }

    public function failed(?Throwable $exception): void
    {
        app(TranscriptSummaryService::class)
            ->markFailed($this->transcript, TranscriptSummaryService::GENERIC_ERROR);
    }
}
