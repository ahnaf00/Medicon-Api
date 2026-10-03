<?php

namespace App\Jobs;

use App\Models\MedicalRecord;
use App\Services\ReportAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeReportJob implements ShouldQueue
{
    use Queueable;

    /** One attempt: the patient retries from the app, which re-queues explicitly. */
    public int $tries = 1;

    /** Below the database queue's default 90 s retry_after, above the 60 s HTTP timeout. */
    public int $timeout = 80;

    public function __construct(public MedicalRecord $record)
    {
    }

    public function handle(ReportAnalysisService $service): void
    {
        $service->analyze($this->record);
    }

    /**
     * Covers what the service cannot catch itself (worker timeout, killed process).
     */
    public function failed(?Throwable $exception): void
    {
        app(ReportAnalysisService::class)->markFailed($this->record, ReportAnalysisService::GENERIC_ERROR);
    }
}
