<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AiUsageEvent;
use App\Models\ConsultationTranscript;
use App\Services\AccountDeletionService;
use App\Services\ConsultationTranscriptPipeline;
use App\Services\DoctorSlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Admin System health (audit L2b step 3). Shows that something failed and
 * lets an admin start it again, never what it was about: no transcript text,
 * no job payload and no full error message (those can hold patient details;
 * the full error stays in the server log).
 */
class SystemHealthController extends Controller
{
    private const CHART_DAYS = 14;

    public function __construct(private readonly FailedJobProviderInterface $failer) {}

    /** Calls to the AI service per Dhaka day and per feature, last 14 days. */
    public function aiUsage(): JsonResponse
    {
        $tz = DoctorSlotService::timezone();
        $todayStart = now($tz)->startOfDay();
        $chartStart = $todayStart->copy()->subDays(self::CHART_DAYS - 1);

        // Grouped in PHP so the Dhaka day is right on any database.
        $events = AiUsageEvent::where('created_at', '>=', $chartStart->copy()->utc())
            ->get(['feature', 'succeeded', 'duration_ms', 'created_at']);
        $byDay = $events->groupBy(fn (AiUsageEvent $e) => $e->created_at->copy()->setTimezone($tz)->toDateString());

        $days = collect(range(0, self::CHART_DAYS - 1))->map(function ($i) use ($chartStart, $byDay) {
            $date = $chartStart->copy()->addDays($i)->toDateString();
            $day = $byDay->get($date, collect());

            return ['date' => $date, 'total' => $day->count(), 'failed' => $day->where('succeeded', false)->count()];
        });

        $features = $events->groupBy('feature')
            ->map(fn ($group, $feature) => [
                'feature' => $feature,
                'total' => $group->count(),
                'failed' => $group->where('succeeded', false)->count(),
                'averageMs' => ($avg = $group->whereNotNull('duration_ms')->avg('duration_ms')) !== null ? (int) round($avg) : null,
            ])
            // Busiest first; equal counts by name so the order is stable.
            ->sortBy([['total', 'desc'], ['feature', 'asc']])
            ->values();

        return response()->json([
            'days' => $days,
            'features' => $features,
            'today' => $days->last(),
            // Counting started with this release; the chart is empty before it.
            'countingSince' => AiUsageEvent::oldest('created_at')->value('created_at')?->toIso8601String(),
            'retentionDays' => AiUsageEvent::RETENTION_DAYS,
        ]);
    }

    public function failedTranscripts(): JsonResponse
    {
        $page = ConsultationTranscript::where('status', 'failed')
            ->with(['appointment.doctor'])
            ->withExists(['segments', 'audioChunks'])
            ->latest('updated_at')
            ->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (ConsultationTranscript $t) => [
                'id' => $t->id,
                'visitId' => $t->appointment_id,
                'visitAt' => $t->appointment?->appointment_datetime?->toIso8601String(),
                'doctor' => $t->appointment?->doctor
                    ? ($t->appointment->doctor->purged_at ? AccountDeletionService::DELETED_NAME : $t->appointment->doctor->name)
                    : null,
                // A short plain reason the doctor also sees, never transcript text.
                'error' => $t->error,
                'failedAt' => $t->updated_at?->toIso8601String(),
                'canRetry' => $t->audio_chunks_exists || ($t->transcribed_at !== null && $t->segments_exists),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Same retry the doctor has; it re-runs only the step that failed. */
    public function retryTranscript(Request $request, ConsultationTranscriptPipeline $pipeline, int $id): JsonResponse
    {
        $transcript = ConsultationTranscript::with('appointment')->findOrFail($id);

        if (! $pipeline->retry($transcript)) {
            return response()->json(['message' => 'Only a failed transcript with its recording can be retried.'], 409);
        }

        AdminAuditLog::record($request->user(), AdminAuditLog::TRANSCRIPT_RETRIED, $transcript->appointment);

        return response()->json(['message' => 'Transcript queued again.', 'status' => $transcript->fresh()->status], 202);
    }

    public function failedJobs(): JsonResponse
    {
        $page = DB::table(config('queue.failed.table', 'failed_jobs'))
            ->select(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn ($row) => [
                'id' => $row->uuid,
                'job' => self::jobName($row->payload),
                'queue' => $row->queue,
                'error' => self::exceptionClass($row->exception),
                'failedAt' => $row->failed_at ? Carbon::parse($row->failed_at, 'UTC')->toIso8601String() : null,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function retryJob(Request $request, string $uuid): JsonResponse
    {
        $job = $this->failer->find($uuid) ?? abort(404, 'This failed job no longer exists.');

        try {
            Artisan::call('queue:retry', ['id' => [$uuid]]);
        } catch (Throwable $e) {
            // A payload the queue cannot read again (e.g. from an older release).
            report($e);

            return response()->json(['message' => 'This job cannot be started again. Discard it instead.'], 422);
        }

        AdminAuditLog::record($request->user(), AdminAuditLog::JOB_RETRIED, null, null, [
            'job' => self::jobName($job->payload), 'id' => $uuid,
        ]);

        return response()->json(['message' => 'Job queued again.'], 202);
    }

    public function discardJob(Request $request, string $uuid): JsonResponse
    {
        $job = $this->failer->find($uuid) ?? abort(404, 'This failed job no longer exists.');

        $this->failer->forget($uuid);
        AdminAuditLog::record($request->user(), AdminAuditLog::JOB_DISCARDED, null, null, [
            'job' => self::jobName($job->payload), 'id' => $uuid,
        ]);

        return response()->json(['message' => 'Failed job removed.']);
    }

    /** The job's class name only, e.g. "AnalyzeReportJob"; the payload itself is never returned. */
    private static function jobName(?string $payload): string
    {
        $name = json_decode((string) $payload, true)['displayName'] ?? null;

        return is_string($name) && $name !== '' ? class_basename($name) : 'Unknown job';
    }

    /** "ConnectionException" from "Illuminate\…\ConnectionException: cURL error 28 … in /var/www/…". */
    private static function exceptionClass(?string $exception): string
    {
        return preg_match('/^([A-Za-z_][\w\\\\]*)(?::|\s|$)/', (string) $exception, $m)
            ? class_basename($m[1])
            : 'Error';
    }
}
