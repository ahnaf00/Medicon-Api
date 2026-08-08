<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use App\Models\DoctorAvailability;
use App\Models\DoctorScheduleException;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DoctorAvailabilityController extends Controller
{
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
        $dateString = $date->toDateString();
        
        $dayOfWeek = $date->dayOfWeek; // 0 (Sunday) to 6 (Saturday)

        $availability = DoctorAvailability::where('doctor_user_id', $id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        // If doctor has no availability config, fallback to 9-5 weekday default to prevent breaking
        $startTime = '09:00:00';
        $endTime = '17:00:00';
        $isActive = !in_array($dayOfWeek, [5, 6]); // Default non-working: Fri, Sat

        if ($availability) {
            $startTime = $availability->start_time;
            $endTime = $availability->end_time;
            $isActive = $availability->is_active;
        }

        // Fetch daily exceptions
        $exceptions = DoctorScheduleException::where('doctor_user_id', $id)
            ->whereDate('date', $dateString)
            ->get();

        $disabledTimes = $exceptions->where('type', 'disabled')->pluck('time')->map(fn($t) => Carbon::parse($t)->format('H:i'))->toArray();
        $addedTimes = $exceptions->where('type', 'added')->pluck('time')->map(fn($t) => Carbon::parse($t)->format('H:i'))->toArray();

        // If clinic is NOT active today, only show the "added" custom slots (if any)
        $rawSlots = [];
        if ($isActive) {
            $rawSlots = $this->generateSlots($date, $startTime, $endTime);
        }

        // Add custom exception slots
        foreach ($addedTimes as $timeStr) {
            if (!in_array($timeStr, $rawSlots)) {
                $rawSlots[] = $timeStr;
            }
        }

        // Remove disabled slots
        $finalRawSlots = array_values(array_filter($rawSlots, function($timeStr) use ($disabledTimes) {
            return !in_array($timeStr, $disabledTimes);
        }));

        // Sort by time
        usort($finalRawSlots, function($a, $b) {
            return strtotime($a) - strtotime($b);
        });

        if (empty($finalRawSlots)) {
            return response()->json([
                'doctorId'        => $id,
                'doctorName'      => $doctor->name,
                'consultationFee' => (float) $doctor->doctorProfile?->consultation_fee,
                'date'            => $dateString,
                'slotDuration'    => self::SLOT_DURATION,
                'slots'           => [],
                'note'            => 'Doctor is not available on this day.',
            ]);
        }

        // Fetch booked datetimes
        $bookedTimes = Appointment::where('doctor_user_id', $id)
            ->whereDate('appointment_datetime', $dateString)
            ->whereIn('status', ['scheduled', 'completed', 'in-progress'])
            ->pluck('appointment_datetime')
            ->map(fn($dt) => Carbon::parse($dt)->format('H:i'))
            ->toArray();

        // Build response format
        $slots = [];
        foreach ($finalRawSlots as $timeStr) {
            // Re-parse time for iso string
            $parts = explode(':', $timeStr);
            $dt = $date->copy()->setTime((int)$parts[0], (int)$parts[1]);
            
            $slots[] = [
                'time'      => $timeStr,
                'datetime'  => $dt->toIso8601String(),
                'available' => !in_array($timeStr, $bookedTimes),
            ];
        }

        return response()->json([
            'doctorId'        => $id,
            'doctorName'      => $doctor->name,
            'consultationFee' => (float) $doctor->doctorProfile?->consultation_fee,
            'date'            => $dateString,
            'slotDuration'    => self::SLOT_DURATION,
            'slots'           => $slots,
        ]);
    }

    private function generateSlots(Carbon $date, string $startTime, string $endTime): array
    {
        $slots    = [];
        
        $startParts = explode(':', $startTime);
        $endParts = explode(':', $endTime);
        
        $current  = $date->copy()->setTime((int)$startParts[0], (int)$startParts[1]);
        $end      = $date->copy()->setTime((int)$endParts[0], (int)$endParts[1]);

        while ($current < $end) {
            $slots[] = $current->format('H:i');
            $current->addMinutes(self::SLOT_DURATION);
        }

        return $slots;
    }

    // --- Doctor endpoints ---

    public function mySlots(Request $request): JsonResponse
    {
        $doctorId = $request->user()->id;
        $availabilities = DoctorAvailability::where('doctor_user_id', $doctorId)->get();
        
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $schedule = [];

        foreach ($days as $index => $dayName) {
            $record = $availabilities->firstWhere('day_of_week', $index);
            
            $st = '09:00 AM';
            $et = '05:00 PM';
            if ($record) {
                $st = Carbon::parse('2000-01-01 ' . $record->start_time)->format('h:i A');
                $et = Carbon::parse('2000-01-01 ' . $record->end_time)->format('h:i A');
            }

            $schedule[] = [
                'id' => $record ? $record->id : "tmp-$index",
                'day' => $dayName,
                'startTime' => $st,
                'endTime' => $et,
                'isWorkingDay' => $record ? (bool)$record->is_active : !in_array($index, [5, 6]),
            ];
        }

        return response()->json($schedule);
    }

    public function updateSlots(Request $request): JsonResponse
    {
        $request->validate([
            'schedule' => 'required|array',
            'schedule.*.day' => 'required|string',
            'schedule.*.startTime' => 'required|string',
            'schedule.*.endTime' => 'required|string',
            'schedule.*.isWorkingDay' => 'required|boolean',
        ]);

        $doctorId = $request->user()->id;
        $daysMap = ['Sunday'=>0, 'Monday'=>1, 'Tuesday'=>2, 'Wednesday'=>3, 'Thursday'=>4, 'Friday'=>5, 'Saturday'=>6];

        DB::beginTransaction();
        try {
            foreach ($request->schedule as $item) {
                if (!isset($daysMap[$item['day']])) continue;
                
                $dayOfWeek = $daysMap[$item['day']];
                
                $startTime = Carbon::createFromFormat('h:i A', $item['startTime'])->format('H:i:s');
                $endTime = Carbon::createFromFormat('h:i A', $item['endTime'])->format('H:i:s');

                DoctorAvailability::updateOrCreate(
                    [
                        'doctor_user_id' => $doctorId,
                        'day_of_week' => $dayOfWeek,
                    ],
                    [
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'is_active' => $item['isWorkingDay'],
                    ]
                );
            }
            DB::commit();
            return response()->json(['message' => 'Schedule updated successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update schedule: ' . $e->getMessage()], 500);
        }
    }

    // --- Exceptions endpoints ---

    public function getExceptions(Request $request): JsonResponse
    {
        $doctorId = $request->user()->id;
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = DoctorScheduleException::where('doctor_user_id', $doctorId);
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        $exceptions = $query->get();

        // Group into a format the frontend likes
        // e.g. { "YYYY-MM-DD": { "disabled": ["09:00", "09:30"], "added": ["18:00"] } }
        $formatted = [];
        foreach ($exceptions as $ex) {
            $date = $ex->date;
            $timeStr = Carbon::parse($ex->time)->format('h:i A'); // Return matching format to frontend
            
            if (!isset($formatted[$date])) {
                $formatted[$date] = ['disabled' => [], 'added' => []];
            }

            $formatted[$date][$ex->type][] = $timeStr;
        }

        return response()->json($formatted);
    }

    public function toggleException(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'required|date',
            'time' => 'required|string', // e.g. "09:30 AM" or "09:30"
            'type' => 'required|in:disabled,added',
            'action' => 'required|in:add,remove',
        ]);

        $doctorId = $request->user()->id;
        $date = Carbon::parse($request->date)->toDateString();
        
        // Normalize time input
        try {
            if (str_contains($request->time, 'AM') || str_contains($request->time, 'PM')) {
                $time = Carbon::createFromFormat('h:i A', $request->time)->format('H:i:s');
            } else {
                $time = Carbon::parse($request->time)->format('H:i:s');
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Invalid time format'], 400);
        }

        if ($request->action === 'add') {
            DoctorScheduleException::updateOrCreate([
                'doctor_user_id' => $doctorId,
                'date' => $date,
                'time' => $time,
            ], [
                'type' => $request->type
            ]);
        } else {
            DoctorScheduleException::where('doctor_user_id', $doctorId)
                ->where('date', $date)
                ->where('time', $time)
                ->delete();
        }

        return response()->json(['message' => 'Exception updated']);
    }
}
