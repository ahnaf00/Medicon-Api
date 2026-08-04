<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Appointments\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentController extends Controller
{
    public function index(Request $request):AnonymousResourceCollection
    {
        $user = $request->user();

        $appointments = Appointment::with(['doctor.doctorProfile', 'patient.patientProfile'])->orderBy('appointment_datetime','asc');

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

    public function store(StoreAppointmentRequest $request):JsonResponse
    {
        $validated = $request->validated();

         $appointment = Appointment::create([
            'patient_user_id'       => $request->user()->id,
            'doctor_user_id'        => $validated['doctor_user_id'],
            'appointment_datetime'  => $validated['appointment_datetime'],
            'format'                => $validated['format'],
            'notes'                 => $validated['notes'] ?? null,
            'status'                => 'scheduled',
        ]);

        return response()->json([
            'message'       => 'Appointment booked successfully.',
            'appointment'   => $appointment->load(['doctor', 'doctor.doctorProfile']),
        ], 201);
    }

    public function cancel(Request $request, $id):JsonResponse
    {
        $user = $request->user();

        $appointment = Appointment::where('id',$id)
                        ->where(function ($q) use ($user) {
                            $q->where('patient_user_id',$user->id)
                            ->orWhere('doctor_user_id',$user->id);
                        })->firstOrFail();

        $appointment->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Appointment cancelled successfully.',
            'appointment' => $appointment,
        ], 200);
    }
}
