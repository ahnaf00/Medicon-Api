<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Billing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

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
            ->whereMonth('appointment_datetime', Carbon::now()->month)
            ->whereYear('appointment_datetime', Carbon::now()->year)
            ->avg('duration_minutes');

        $timeMetrics = [
            'avg_all_time_mins' => $allTimeAvg ? round($allTimeAvg) : 0,
            'avg_this_month_mins' => $thisMonthAvg ? round($thisMonthAvg) : 0,
        ];

        // 3. Earnings Overview
        // Earnings are calculated from Billings associated with this doctor's appointments
        $todayEarnings = Billing::whereHas('appointment', function ($query) use ($user) {
                $query->where('doctor_user_id', $user->id);
            })
            ->whereDate('created_at', Carbon::today())
            ->where('payment_status', 'paid')
            ->sum('amount');

        $thisMonthEarnings = Billing::whereHas('appointment', function ($query) use ($user) {
                $query->where('doctor_user_id', $user->id);
            })
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->where('payment_status', 'paid')
            ->sum('amount');

        $previousMonthEarnings = Billing::whereHas('appointment', function ($query) use ($user) {
                $query->where('doctor_user_id', $user->id);
            })
            ->whereMonth('created_at', Carbon::now()->subMonth()->month)
            ->whereYear('created_at', Carbon::now()->subMonth()->year)
            ->where('payment_status', 'paid')
            ->sum('amount');

        $earnings = [
            'today' => $todayEarnings,
            'this_month' => $thisMonthEarnings,
            'previous_month' => $previousMonthEarnings,
        ];

        // 4. Quick Appointment Stats (Bonus)
        $todayAppointmentsCount = Appointment::where('doctor_user_id', $user->id)
            ->whereDate('appointment_datetime', Carbon::today())
            ->where('status', '!=', 'cancelled')
            ->count();

        return response()->json([
            'fees' => $fees,
            'time_metrics' => $timeMetrics,
            'earnings' => $earnings,
            'today_appointments_count' => $todayAppointmentsCount,
        ]);
    }
}
