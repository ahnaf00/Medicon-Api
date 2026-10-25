<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsageEvent;
use App\Models\Appointment;
use App\Models\ConsultationTranscript;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\DoctorSlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for the admin Overview tab (audit L2b). Counts only: no names and
 * no medical content. Days are clinic-timezone (Asia/Dhaka) calendar days.
 */
class OverviewController extends Controller
{
    private const CHART_DAYS = 14;

    private const VISIT_STATUSES = ['scheduled', 'in_progress', 'completed', 'cancelled', 'no_show', 'expired'];

    public function show(): JsonResponse
    {
        $tz = DoctorSlotService::timezone();
        $todayStart = now($tz)->startOfDay();
        $todayStartUtc = $todayStart->copy()->utc();
        $tomorrowStartUtc = $todayStart->copy()->addDay()->utc();
        $chartStart = $todayStart->copy()->subDays(self::CHART_DAYS - 1);

        $doctorCounts = DoctorProfile::whereHas('user')
            ->select('verification_status', DB::raw('count(*) as total'))
            ->groupBy('verification_status')
            ->pluck('total', 'verification_status');

        $todayByStatus = Appointment::whereBetween('appointment_datetime', [$todayStartUtc, $tomorrowStartUtc])
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        $visitsToday = collect(self::VISIT_STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($todayByStatus[$s] ?? 0)]);

        // Grouped in PHP so the Dhaka day is right on any database.
        $perDay = Appointment::whereBetween('appointment_datetime', [$chartStart->copy()->utc(), $tomorrowStartUtc])
            ->pluck('appointment_datetime')
            ->countBy(fn ($at) => $at->copy()->setTimezone($tz)->toDateString());
        $visitsByDay = collect(range(0, self::CHART_DAYS - 1))->map(function ($i) use ($chartStart, $perDay) {
            $date = $chartStart->copy()->addDays($i)->toDateString();

            return ['date' => $date, 'total' => (int) ($perDay[$date] ?? 0)];
        });

        return response()->json([
            'users' => [
                'patients' => User::role('patient')->count(),
                'doctors' => User::role('doctor')->count(),
                'newThisWeek' => User::where('created_at', '>=', $todayStart->copy()->subDays(6)->utc())->count(),
                'suspended' => User::where('status', 'suspended')->count(),
                'pendingDeletion' => User::onlyTrashed()->whereNull('purged_at')->count(),
            ],
            'doctors' => [
                'pending' => (int) ($doctorCounts['pending'] ?? 0),
                'verified' => (int) ($doctorCounts['verified'] ?? 0),
                'rejected' => (int) ($doctorCounts['rejected'] ?? 0),
                'onlineNow' => DoctorProfile::where('is_online', true)->whereHas('user')->count(),
            ],
            'visitsToday' => $visitsToday->put('total', $visitsToday->sum()),
            'visitsByDay' => $visitsByDay,
            'transcripts' => [
                'failed' => ConsultationTranscript::where('status', 'failed')->count(),
            ],
            'jobs' => [
                'failed' => DB::table(config('queue.failed.table', 'failed_jobs'))->count(),
            ],
            // Every call to the AI service from any feature (audit L2b step 3).
            'ai' => [
                'requestsToday' => AiUsageEvent::where('created_at', '>=', $todayStartUtc)->count(),
            ],
            'generatedAt' => now()->toIso8601String(),
        ]);
    }
}
