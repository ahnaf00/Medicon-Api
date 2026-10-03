<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Appointments\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\User;
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
            $doctor = User::role('doctor')->whereKey($doctorId)->lockForUpdate()->first();

            if (! $doctor || ! $slots->isBookable($doctorId, $start)) {
                throw ValidationException::withMessages([
                    'appointment_datetime' => 'That time is not an available slot for this doctor.',
                ]);
            }

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

    /** Allowed status moves: from => [to, ...]. */
    private const STATUS_TRANSITIONS = [
        'scheduled'   => ['in_progress', 'no_show'],
        'in_progress' => ['completed'],
    ];

    /**
     * Doctor moves a visit through its lifecycle. Starting stamps `started_at`;
     * completing stamps `ended_at` and writes `duration_minutes`.
     */
    public function updateStatus(Request $request, $id):JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:in_progress,completed,no_show'],
        ]);

        $appointment = Appointment::findOrFail($id);

        Gate::authorize('updateStatus', $appointment);

        $to = $validated['status'];
        $allowed = self::STATUS_TRANSITIONS[$appointment->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            return response()->json([
                'message' => "An appointment that is {$appointment->status} cannot be marked {$to}.",
            ], 409);
        }

        $changes = ['status' => $to];

        if ($to === 'in_progress') {
            $changes['started_at'] = now();
        }

        if ($to === 'completed') {
            $endedAt = now();
            $changes['ended_at'] = $endedAt;
            $changes['duration_minutes'] = $appointment->started_at
                ? max(1, (int) round($appointment->started_at->diffInMinutes($endedAt, true)))
                : null;
        }

        $appointment->update($changes);

        return response()->json([
            'message' => 'Appointment status updated.',
            'appointment' => new AppointmentResource($appointment->load(['doctor.doctorProfile', 'patient.patientProfile'])),
        ]);
    }

    public function cancel(Request $request, $id):JsonResponse
    {
        $appointment = Appointment::findOrFail($id);

        Gate::authorize('cancel', $appointment);

        if ($appointment->status !== 'scheduled') {
            throw ValidationException::withMessages([
                'status' => 'Only a scheduled appointment can be cancelled.',
            ]);
        }

        $appointment->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Appointment cancelled successfully.',
            'appointment' => $appointment,
        ], 200);
    }
}
