<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Prescriptions\StorePrescriptionRequest;
use App\Http\Resources\PrescriptionDocumentResource;
use App\Http\Resources\PrescriptionResource;
use App\Models\Prescription;
use App\Http\Requests\Api\Prescriptions\UpdateExplanationRequest;
use App\Services\AllergyCheck;
use App\Services\MedicineExplainerService;
use App\Services\PrescriptionPdfService;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

class PrescriptionController extends Controller
{
    /** Everything PrescriptionResource reads, loaded up front. */
    private const RELATIONS = ['items', 'tests', 'doctor.doctorProfile', 'patient.patientProfile', 'replacedBy:id,replaces_prescription_id'];

    public const ALLERGY_WARNING = 'A medicine matches an allergy on the patient\'s profile. Check it, then confirm to prescribe anyway.';

    public function index(Request $request):AnonymousResourceCollection
    {
        $user = $request->user();

        $prescriptions = Prescription::with(self::RELATIONS)->latest();

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

        // Reissue after cancelling (audit G1): the replacement belongs to the same
        // doctor, patient and visit as the cancelled one, and replaces it once.
        if (! empty($validated['replaces_prescription_id'])) {
            $replaced = Prescription::findOrFail($validated['replaces_prescription_id']);
            $sameVisit = ($validated['appointment_id'] ?? $replaced->voided_appointment_id) == $replaced->voided_appointment_id;
            if ((int) $replaced->doctor_user_id !== $doctor->id
                || (int) $replaced->patient_user_id !== (int) $validated['patient_user_id']
                || $replaced->status !== 'cancelled'
                || ! $sameVisit
                || $replaced->replacedBy()->exists()) {
                throw ValidationException::withMessages([
                    'replaces_prescription_id' => ['Only a cancelled prescription of yours, for this patient and visit, can be replaced, and only once.'],
                ]);
            }
            $validated['appointment_id'] ??= $replaced->voided_appointment_id;
        }

        Gate::authorize('create', [
            Prescription::class,
            (int) $validated['patient_user_id'],
            isset($validated['appointment_id']) ? (int) $validated['appointment_id'] : null,
        ]);

        // Name match against the patient's recorded allergies (audit G1). Not a
        // drug-class check: the app says so next to the warning.
        $patient = User::with('patientProfile')->find($validated['patient_user_id']);
        $conflicts = AllergyCheck::conflicts(
            $patient?->patientProfile?->allergies,
            array_column($validated['medicines'], 'medicine_name'),
        );
        if ($conflicts !== [] && ! ($validated['allergy_acknowledged'] ?? false)) {
            return response()->json([
                'message'           => self::ALLERGY_WARNING,
                'errors'            => ['allergy_acknowledged' => [self::ALLERGY_WARNING]],
                'allergyConflicts'  => $conflicts,
            ], 422);
        }
        $validated['allergy_conflicts'] = $conflicts ?: null;

        try {
            $prescription = $this->createPrescription($validated, $doctor);
        } catch (UniqueConstraintViolationException) {
            // Two saves for the same visit at once: the validation rule passed for
            // both, the unique index stopped the second (audit G1).
            throw ValidationException::withMessages([
                'appointment_id' => [StorePrescriptionRequest::ONE_PER_VISIT],
            ]);
        }

        // Write the "Why take this medicine?" text after the response is sent,
        // so the doctor never waits on the model.
        defer(fn () => $explainer->explainPrescription($prescription));

        return response()->json([
            'message'       => 'Prescription issued successfully.',
            'prescription'  => $prescription->load(['items', 'tests']),
        ], 201);
    }

    private function createPrescription(array $validated, User $doctor): Prescription
    {
        return DB::transaction(function () use ($validated, $doctor) {
            $prescription = Prescription::create([
                'appointment_id'        => $validated['appointment_id'] ?? null,
                'patient_user_id'       => $validated['patient_user_id'],
                'doctor_user_id'        => $doctor->id,
                'diagnosis_summary'     => $validated['diagnosis_summary'],
                'follow_up_date'        => $validated['follow_up_date'] ?? null,
                'advice'                => $validated['advice'] ?? null,
                'status'                => 'active',
                'replaces_prescription_id' => $validated['replaces_prescription_id'] ?? null,
                'allergy_conflicts'     => $validated['allergy_conflicts'],
                'allergy_acknowledged_at' => $validated['allergy_conflicts'] ? now() : null,
            ]);
            foreach ($validated['medicines'] as $med) {
                $prescription->items()->create([
                    'medicine_name'     => $med['medicine_name'],
                    'dosage'            => $med['dosage'],
                    'dosage_schedule'   => $med['dosage_schedule'] ?? null,
                    'dose_quantities'   => array_filter($med['dose_quantities'] ?? [], fn ($q) => $q !== null) ?: null,
                    'route'             => $med['route'] ?? null,
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
    }

    /**
     * POST /prescriptions/{id}/void: the prescribing doctor cancels a prescription
     * written in error, with a reason (audit G1). It stays in both histories marked
     * cancelled, and the visit is freed so a corrected one can be issued.
     */
    public function void(Request $request, $id): PrescriptionResource
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $prescription = Prescription::findOrFail($id);
        Gate::authorize('void', $prescription);

        DB::transaction(function () use ($id, $validated, $request) {
            $prescription = Prescription::lockForUpdate()->findOrFail($id);
            if ($prescription->status === 'cancelled') {
                throw ValidationException::withMessages(['reason' => ['This prescription is already cancelled.']]);
            }

            $prescription->forceFill([
                'status'                => 'cancelled',
                'voided_at'             => now(),
                'void_reason'           => trim($validated['reason']),
                'voided_by_user_id'     => $request->user()->id,
                'voided_appointment_id' => $prescription->appointment_id,
                'appointment_id'        => null,
            ])->save();
        });

        return new PrescriptionResource($this->findViewable($id));
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

    /**
     * PATCH /prescriptions/{id}/items/{itemId}/explanation — the prescribing doctor
     * rewrites a medicine's explanation (it then reads "from your doctor") and/or
     * hides it from the patient (audit CLIN-006).
     */
    public function updateExplanation(UpdateExplanationRequest $request, $id, $itemId): PrescriptionResource
    {
        $prescription = Prescription::findOrFail($id);

        Gate::authorize('update', $prescription);

        $item = $prescription->items()->findOrFail($itemId);
        $validated = $request->validated();

        if (array_key_exists('explanation', $validated)) {
            $item->explanation = trim($validated['explanation']);
            $item->explanation_source = 'doctor';
        }
        if (array_key_exists('hidden', $validated)) {
            $item->explanation_hidden = (bool) $validated['hidden'];
        }
        $item->save();

        return new PrescriptionResource($this->findViewable($id));
    }

    private function findViewable($id): Prescription
    {
        $prescription = Prescription::with(self::RELATIONS)->findOrFail($id);

        // Only the prescription's patient or prescribing doctor may read it.
        Gate::authorize('view', $prescription);

        return $prescription;
    }
}
