<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Billing;
use App\Services\DoctorSlotService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Ensure user is a doctor
        if (!$user->hasRole('doctor')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $doctorProfile = $user->doctorProfile;

        // "Today" and "this month" are clinic (Dhaka) calendar periods. Timestamps are
        // stored in UTC, so each period becomes a UTC [start, end) range.
        $today = DoctorSlotService::today();
        $todayRange = $this->utcRange($today, $today->copy()->addDay());
        $thisMonthRange = $this->utcRange($today->copy()->startOfMonth(), $today->copy()->startOfMonth()->addMonth());
        $previousMonthRange = $this->utcRange($today->copy()->startOfMonth()->subMonth(), $today->copy()->startOfMonth());

        // 1. Fees
        $fees = [
            'consultation_fee' => $doctorProfile?->consultation_fee ?? 0,
            'follow_up_fee'    => $doctorProfile?->follow_up_fee ?? 0,
        ];

        // 2. Time Metrics (Average Consultation Time)
        // Average over all appointments that have a duration_minutes set
        $allTimeAvg = Appointment::where('doctor_user_id', $user->id)
            ->whereNotNull('duration_minutes')
            ->avg('duration_minutes');

        $thisMonthAvg = Appointment::where('doctor_user_id', $user->id)
            ->whereNotNull('duration_minutes')
            ->where('appointment_datetime', '>=', $thisMonthRange[0])
            ->where('appointment_datetime', '<', $thisMonthRange[1])
            ->avg('duration_minutes');

        $timeMetrics = [
            'avg_all_time_mins' => $allTimeAvg ? round($allTimeAvg) : 0,
            'avg_this_month_mins' => $thisMonthAvg ? round($thisMonthAvg) : 0,
        ];

        // 3. Earnings Overview
        // Earnings are calculated from Billings associated with this doctor's appointments
        $earnings = [
            'today' => $this->paidEarnings($user->id, $todayRange),
            'this_month' => $this->paidEarnings($user->id, $thisMonthRange),
            'previous_month' => $this->paidEarnings($user->id, $previousMonthRange),
        ];

        // 4. Quick Appointment Stats (Bonus)
        $todayAppointmentsCount = Appointment::where('doctor_user_id', $user->id)
            ->where('appointment_datetime', '>=', $todayRange[0])
            ->where('appointment_datetime', '<', $todayRange[1])
            ->where('status', '!=', 'cancelled')
            ->count();

        return response()->json([
            'fees' => $fees,
            'time_metrics' => $timeMetrics,
            'earnings' => $earnings,
            'today_appointments_count' => $todayAppointmentsCount,
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function utcRange(Carbon $start, Carbon $end): array
    {
        return [$start->copy()->utc(), $end->copy()->utc()];
    }

    private function paidEarnings(int $doctorId, array $range): float
    {
        return (float) Billing::whereHas('appointment', function ($query) use ($doctorId) {
                $query->where('doctor_user_id', $doctorId);
            })
            ->where('created_at', '>=', $range[0])
            ->where('created_at', '<', $range[1])
            ->where('payment_status', 'paid')
            ->sum('amount');
    }
}
