<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorAvailabilityController extends Controller
{
    // Clinic hours: 09:00 - 17:00, 30-minute slots
    private const CLINIC_START  = 9;   // 9 AM
    private const CLINIC_END    = 17;  // 5 PM
    private const SLOT_DURATION = 30;  // minutes

    public function slots(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today', 'before:+30 days'],
        ]);

        $doctor = User::role('doctor')
            ->with('doctorProfile')
            ->findOrFail($id);

        $date = Carbon::parse($request->query('date'));

        // No slots on weekends
        if ($date->isWeekend()) {
            return response()->json([
                'date'  => $date->toDateString(),
                'slots' => [],
                'note'  => 'Doctor is not available on weekends.',
            ]);
        }

        // Fetch booked datetimes for that doctor on that date
        $bookedTimes = Appointment::where('doctor_user_id', $id)
            ->whereDate('appointment_datetime', $date->toDateString())
            ->whereIn('status', ['scheduled', 'completed'])
            ->pluck('appointment_datetime')
            ->map(fn($dt) => Carbon::parse($dt)->format('H:i'))
            ->toArray();

        $slots = $this->generateSlots($date, $bookedTimes);

        return response()->json([
            'doctorId'        => $id,
            'doctorName'      => $doctor->name,
            'consultationFee' => (float) $doctor->doctorProfile?->consultation_fee,
            'date'            => $date->toDateString(),
            'slotDuration'    => self::SLOT_DURATION,
            'slots'           => $slots,
        ]);
    }

    private function generateSlots(Carbon $date, array $bookedTimes): array
    {
        $slots    = [];
        $current  = $date->copy()->setTime(self::CLINIC_START, 0);
        $end      = $date->copy()->setTime(self::CLINIC_END, 0);

        while ($current < $end) {
            $timeStr = $current->format('H:i');
            $slots[] = [
                'time'      => $timeStr,
                'datetime'  => $current->toIso8601String(),
                'available' => ! in_array($timeStr, $bookedTimes),
            ];
            $current->addMinutes(self::SLOT_DURATION);
        }

        return $slots;
    }
}
