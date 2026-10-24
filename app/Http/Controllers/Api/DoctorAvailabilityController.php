<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\DoctorAvailability;
use App\Models\DoctorScheduleException;
use App\Services\DoctorSlotService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DoctorAvailabilityController extends Controller
{
    public function slots(Request $request, int $id, DoctorSlotService $slotService): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date'],
        ]);

        // The date is a clinic-timezone (Asia/Dhaka) calendar day, so "today" is Dhaka's today.
        $today = DoctorSlotService::today();
        $date = Carbon::parse($request->query('date'), DoctorSlotService::timezone())->startOfDay();
        if ($date->lt($today) || $date->gte($today->copy()->addDays(30))) {
            throw ValidationException::withMessages([
                'date' => 'The date must be between today and 30 days from now.',
            ]);
        }

        $doctor = User::verifiedDoctors()
            ->with('doctorProfile')
            ->findOrFail($id);

        $slots = $slotService->slotsOn($id, $date->toDateString());

        $payload = [
            'doctorId'        => $id,
            'doctorName'      => $doctor->name,
            'consultationFee' => (float) $doctor->doctorProfile?->consultation_fee,
            'date'            => $date->toDateString(),
            'slotDuration'    => DoctorSlotService::SLOT_DURATION,
            'slots'           => $slots,
        ];

        if (empty($slots)) {
            $payload['note'] = 'Doctor is not available on this day.';
        }

        return response()->json($payload);
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
        $daysMap = ['Sunday'=>0, 'Monday'=>1, 'Tuesday'=>2, 'Wednesday'=>3, 'Thursday'=>4, 'Friday'=>5, 'Saturday'=>6];

        // Times arrive as "09:00 AM", the format getSlots returns (audit SEC-010).
        $request->validate([
            'schedule' => 'required|array|max:7',
            'schedule.*.day' => ['required', 'string', Rule::in(array_keys($daysMap))],
            'schedule.*.startTime' => 'required|date_format:h:i A',
            'schedule.*.endTime' => 'required|date_format:h:i A',
            'schedule.*.isWorkingDay' => 'required|boolean',
        ]);

        // A working day must end after it starts.
        foreach ($request->schedule as $i => $item) {
            $start = Carbon::createFromFormat('h:i A', $item['startTime']);
            $end = Carbon::createFromFormat('h:i A', $item['endTime']);
            if ($item['isWorkingDay'] && $end->lte($start)) {
                throw ValidationException::withMessages([
                    "schedule.{$i}.endTime" => "{$item['day']}: the end time must be after the start time.",
                ]);
            }
        }

        $doctorId = $request->user()->id;

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
            // The exception text can carry SQL and internals; log it, don't return it.
            Log::error('Doctor schedule update failed ('.class_basename($e).')', ['doctor_user_id' => $doctorId]);

            return response()->json(['message' => 'Could not save your schedule. Please try again.'], 500);
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
