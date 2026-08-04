<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Prescriptions\StorePrescriptionRequest;
use App\Models\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrescriptionController extends Controller
{
    public function index(Request $request):JsonResponse
    {
        $user = $request->user();

        if($user->hasRole('doctor'))
        {
            $prescriptions = Prescription::where('doctor_user_id',$user->id)
                            ->with(['items','patient'])
                            ->latest()
                            ->get();
        }else
        {
            $prescriptions = Prescription::where('patient_user_id',$user->id)
                            ->with(['items','doctor','doctor.doctorProfile'])
                            ->latest()
                            ->get();
        }

        return response()->json(['prescriptions' => $prescriptions],200);
    }

    public function store(StorePrescriptionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $doctor = $request->user();
        $prescription = DB::transaction(function () use ($validated, $doctor) {
            $prescription = Prescription::create([
                'appointment_id'        => $validated['appointment_id'] ?? null,
                'patient_user_id'       => $validated['patient_user_id'],
                'doctor_user_id'        => $doctor->id,
                'diagnosis_summary'     => $validated['diagnosis_summary'],
                'status'                => 'active',
            ]);
            foreach ($validated['medicines'] as $med) {
                $prescription->items()->create([
                    'medicine_name'     => $med['medicine_name'],
                    'dosage'            => $med['dosage'],
                    'dosage_schedule'   => $med['dosage_schedule'] ?? null,
                    'instructions'      => $med['instructions'] ?? null,
                    'duration_days'     => $med['duration_days'],
                ]);
            }
            return $prescription;
        });
        return response()->json([
            'message'       => 'Prescription issued successfully.',
            'prescription'  => $prescription->load('items'),
        ], 201);
    }
}
