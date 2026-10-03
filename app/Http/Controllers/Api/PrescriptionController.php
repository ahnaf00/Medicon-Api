<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Prescriptions\StorePrescriptionRequest;
use App\Http\Resources\PrescriptionDocumentResource;
use App\Http\Resources\PrescriptionResource;
use App\Models\Prescription;
use App\Services\MedicineExplainerService;
use App\Services\PrescriptionPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

use function Illuminate\Support\defer;

class PrescriptionController extends Controller
{
    public function index(Request $request):AnonymousResourceCollection
    {
        $user = $request->user();

        $prescriptions = Prescription::with(['items', 'tests', 'doctor.doctorProfile', 'patient.patientProfile'])->latest();

        if($user->hasRole('doctor'))
        {
            $prescriptions->where('doctor_user_id',$user->id);
        }
        else
        {
            $prescriptions->where('patient_user_id',$user->id);
        }
        return PrescriptionResource::collection($prescriptions->get());
    }

    public function store(StorePrescriptionRequest $request, MedicineExplainerService $explainer): JsonResponse
    {
        $validated = $request->validated();
        $doctor = $request->user();

        Gate::authorize('create', [
            Prescription::class,
            (int) $validated['patient_user_id'],
            isset($validated['appointment_id']) ? (int) $validated['appointment_id'] : null,
        ]);

        $prescription = DB::transaction(function () use ($validated, $doctor) {
            $prescription = Prescription::create([
                'appointment_id'        => $validated['appointment_id'] ?? null,
                'patient_user_id'       => $validated['patient_user_id'],
                'doctor_user_id'        => $doctor->id,
                'diagnosis_summary'     => $validated['diagnosis_summary'],
                'follow_up_date'        => $validated['follow_up_date'] ?? null,
                'advice'                => $validated['advice'] ?? null,
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
            foreach ($validated['tests'] ?? [] as $order => $test) {
                $prescription->tests()->create([
                    'name'              => $test['name'],
                    'instructions'      => $test['instructions'] ?? null,
                    'order'             => $order,
                ]);
            }
            return $prescription;
        });

        // Write the "Why take this medicine?" text after the response is sent,
        // so the doctor never waits on the model.
        defer(fn () => $explainer->explainPrescription($prescription));

        return response()->json([
            'message'       => 'Prescription issued successfully.',
            'prescription'  => $prescription->load(['items', 'tests']),
        ], 201);
    }

    public function show(Request $request, $id): PrescriptionResource
    {
        return new PrescriptionResource($this->findViewable($id));
    }

    public function document(Request $request, $id): PrescriptionDocumentResource
    {
        return new PrescriptionDocumentResource($this->findViewable($id));
    }

    /**
     * Stream the rendered prescription. Only `pdf` is rendered server-side; the
     * image download is captured on the device from the document payload.
     */
    public function download(Request $request, PrescriptionPdfService $pdfService, $id): Response
    {
        $request->validate(['format' => ['sometimes', 'in:pdf']]);

        $prescription = $this->findViewable($id);

        return response($pdfService->renderPdf($prescription), 200, [
            'Content-Type'          => 'application/pdf',
            'Content-Disposition'   => 'attachment; filename="'.$pdfService->filename($prescription).'"',
            'Cache-Control'         => 'private, no-store',
        ]);
    }

    private function findViewable($id): Prescription
    {
        $prescription = Prescription::with(['items', 'tests', 'doctor.doctorProfile', 'patient.patientProfile'])->findOrFail($id);

        // Only the prescription's patient or prescribing doctor may read it.
        Gate::authorize('view', $prescription);

        return $prescription;
    }
}
