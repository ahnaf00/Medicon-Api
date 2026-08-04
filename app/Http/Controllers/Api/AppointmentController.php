<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Appointments\StoreAppointmentRequest;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function index(Request $request):JsonResponse
    {
        $user = $request->user();

        if($user->hasRole('doctor'))
        {
            $appointments = Appointment::where('doctor_user_id',$user->id)
                            ->with(['patient','patient.patientProfile'])
                            ->orderBy('appointment_datetime','asc')
                            ->get();
        }

        return response()->json(['appointments' => $appointments],200);
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

        return response()->json([
            'message' => 'Appointment cancelled successfully.',
            'appointment' => $appointment,
        ], 200);
    }
}
