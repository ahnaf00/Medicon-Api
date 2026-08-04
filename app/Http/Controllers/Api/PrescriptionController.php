<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Prescriptions\StorePrescriptionRequest;
use App\Http\Resources\PrescriptionResource;
use App\Models\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PrescriptionController extends Controller
{
    public function index(Request $request):AnonymousResourceCollection
    {
        $prescriptions = Prescription::with(['items', 'doctor.doctorProfile', 'patient.patientProfile'])->get();
        return PrescriptionResource::collection($prescriptions);
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
