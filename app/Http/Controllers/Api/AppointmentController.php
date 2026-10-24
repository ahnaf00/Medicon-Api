<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Appointments\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AppointmentLifecycle;
use App\Services\DoctorSlotService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function index(Request $request):AnonymousResourceCollection
    {
        $user = $request->user();

        $appointments = Appointment::with(['doctor.doctorProfile', 'patient.patientProfile'])
            ->withExists('consultationSummary')
            ->orderBy('appointment_datetime','asc');

        if($user->hasRole('doctor'))
        {
            $appointments->where('doctor_user_id',$user->id);
        }
        else
        {
            $appointments->where('patient_user_id',$user->id);
        }

        return AppointmentResource::collection($appointments->get());

    }

    public function store(StoreAppointmentRequest $request, DoctorSlotService $slots):JsonResponse
    {
        $validated = $request->validated();

        // An offset in the value wins; a bare "Y-m-d H:i:s" is clinic (Dhaka) time. Stored as UTC.
        $start = Carbon::parse($validated['appointment_datetime'], DoctorSlotService::timezone())->utc();

        if (! $start->isFuture()) {
            throw ValidationException::withMessages([
                'appointment_datetime' => 'Appointments must be scheduled for a future time.',
            ]);
        }

        $doctorId = (int) $validated['doctor_user_id'];

        // Lock the doctor's row so two patients can't take the same slot at once.
        $appointment = DB::transaction(function () use ($request, $validated, $doctorId, $start, $slots) {
            $doctor = User::verifiedDoctors()->whereKey($doctorId)->lockForUpdate()->first();

            if (! $doctor || ! $slots->isBookable($doctorId, $start)) {
                throw ValidationException::withMessages([
                    'appointment_datetime' => 'That time is not an available slot for this doctor.',
                ]);
            }

            $this->ensureNoOverlap($request->user()->id, $start);

            return Appointment::create([
                'patient_user_id'       => $request->user()->id,
                'doctor_user_id'        => $doctorId,
                'appointment_datetime'  => $start,
                'format'                => $validated['format'],
                'notes'                 => $validated['notes'] ?? null,
                'status'                => 'scheduled',
            ]);
        });

        return response()->json([
            'message'       => 'Appointment booked successfully.',
            'appointment'   => $appointment->load(['doctor', 'doctor.doctorProfile']),
        ], 201);
    }

    /**
     * Doctor moves a visit through its lifecycle. Starting stamps `started_at`;
     * completing stamps `ended_at` and writes `duration_minutes`.
     */
    public function updateStatus(Request $request, AppointmentLifecycle $lifecycle, $id):JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:in_progress,completed,no_show'],
        ]);

        $appointment = Appointment::findOrFail($id);

        Gate::authorize('updateStatus', $appointment);

        $to = $validated['status'];

        if (! $lifecycle->canTransition($appointment, $to)) {
            return response()->json([
                'message' => $lifecycle->rejectionMessage($appointment, $to),
            ], 409);
        }

        $lifecycle->transition($appointment, $to);

        return response()->json([
            'message' => 'Appointment status updated.',
            'appointment' => new AppointmentResource($appointment->load(['doctor.doctorProfile', 'patient.patientProfile'])),
        ]);
    }

    public function cancel(Request $request, $id):JsonResponse
    {
        $appointment = DB::transaction(function () use ($request, $id) {
            $appointment = Appointment::lockForUpdate()->findOrFail($id);

            Gate::authorize('cancel', $appointment);

            if ($appointment->status !== 'scheduled') {
                throw ValidationException::withMessages([
                    'status' => 'Only a scheduled appointment can be cancelled.',
                ]);
            }

            // The doctor may cancel any time; the patient only until the cut-off (audit F10).
            if ($request->user()->id === (int) $appointment->patient_user_id
                && ! AppointmentLifecycle::patientMayChange($appointment)) {
                throw ValidationException::withMessages(['status' => self::cutOffMessage()]);
            }

            $appointment->update(['status' => 'cancelled']);

            return $appointment;
        });

        return response()->json([
            'message' => 'Appointment cancelled successfully.',
            'appointment' => $appointment,
        ], 200);
    }

    /**
     * The patient moves a scheduled visit to another free slot with the same
     * doctor, until the cut-off. Same row, so its id and payment stay (audit F10).
     */
    public function reschedule(Request $request, DoctorSlotService $slots, $id):JsonResponse
    {
        $validated = $request->validate([
            'appointment_datetime' => ['required', 'date'],
        ]);

        $start = Carbon::parse($validated['appointment_datetime'], DoctorSlotService::timezone())->utc();

        $appointment = DB::transaction(function () use ($request, $slots, $id, $start) {
            $appointment = Appointment::lockForUpdate()->findOrFail($id);

            Gate::authorize('reschedule', $appointment);

            if ($appointment->status !== 'scheduled') {
                throw ValidationException::withMessages([
                    'status' => 'Only a scheduled appointment can be moved.',
                ]);
            }

            if (! AppointmentLifecycle::patientMayChange($appointment)) {
                throw ValidationException::withMessages(['status' => self::cutOffMessage()]);
            }

            if (! $start->isFuture() || $start->equalTo($appointment->appointment_datetime)) {
                throw ValidationException::withMessages([
                    'appointment_datetime' => 'Choose a different, future time.',
                ]);
            }

            // Same lock order as booking: the doctor's row first.
            User::whereKey($appointment->doctor_user_id)->lockForUpdate()->first();

            if (! $slots->isBookable((int) $appointment->doctor_user_id, $start)) {
                throw ValidationException::withMessages([
                    'appointment_datetime' => 'That time is not an available slot for this doctor.',
                ]);
            }

            $this->ensureNoOverlap((int) $appointment->patient_user_id, $start, $appointment->id);

            $appointment->update(['appointment_datetime' => $start]);

            return $appointment;
        });

        return response()->json([
            'message' => 'Appointment moved.',
            'appointment' => new AppointmentResource($appointment->load(['doctor.doctorProfile', 'patient.patientProfile'])),
        ]);
    }

    /**
     * A patient can't hold two visits at overlapping times, even with different
     * doctors (audit F10). Locks the patient's row so two bookings can't both pass.
     */
    private function ensureNoOverlap(int $patientId, Carbon $start, ?int $exceptId = null): void
    {
        User::whereKey($patientId)->lockForUpdate()->first();

        $length = DoctorSlotService::SLOT_DURATION;

        $clash = Appointment::where('patient_user_id', $patientId)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->where('appointment_datetime', '>', $start->copy()->subMinutes($length))
            ->where('appointment_datetime', '<', $start->copy()->addMinutes($length))
            ->with('doctor:id,name')
            ->first();

        if ($clash) {
            $at = $clash->appointment_datetime->copy()->setTimezone(DoctorSlotService::timezone())->format('g:i A');
            throw ValidationException::withMessages([
                'appointment_datetime' => "You already have a visit with {$clash->doctor?->name} at {$at}. Choose another time.",
            ]);
        }
    }

    private static function cutOffMessage(): string
    {
        $minutes = AppointmentLifecycle::PATIENT_CHANGE_CUTOFF_MINUTES;

        return "A visit can be cancelled or moved only until {$minutes} minutes before it starts.";
    }
}
