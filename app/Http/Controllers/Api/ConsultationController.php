<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Consultations\UpsertConsultationSummaryRequest;
use App\Http\Resources\ConsultationSummaryResource;
use App\Models\AiChatSession;
use App\Models\Appointment;
use App\Models\ConsultationSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConsultationController extends Controller
{
    /** Statuses in which the doctor may write or edit the summary. */
    private const WRITABLE_STATUSES = ['in_progress', 'completed'];

    /**
     * The consultation's summary plus the context the patient's chat screen
     * needs (doctor, date, status). `summary` is null until the doctor writes it.
     */
    public function show(Request $request, $appointmentId): JsonResponse
    {
        $appointment = Appointment::with(['doctor.doctorProfile', 'consultationSummary'])->findOrFail($appointmentId);

        Gate::authorize('viewSummary', $appointment);

        return response()->json($this->payload($appointment) + [
            // The patient's existing consultation chat, so the app can load its history.
            'chatSessionId' => AiChatSession::where('user_id', $request->user()->id)
                ->where('appointment_id', $appointment->id)
                ->value('id'),
        ]);
    }

    public function upsert(UpsertConsultationSummaryRequest $request, $appointmentId): JsonResponse
    {
        $appointment = Appointment::with('doctor.doctorProfile')->findOrFail($appointmentId);

        Gate::authorize('writeSummary', $appointment);

        if (! in_array($appointment->status, self::WRITABLE_STATUSES, true)) {
            return response()->json([
                'message' => 'Start the consultation before writing its summary.',
            ], 409);
        }

        $validated = $request->validated();

        if (($validated['source'] ?? null) === 'transcript' && $appointment->transcript?->status !== 'ready') {
            return response()->json([
                'message' => 'This consultation has no finished call transcript.',
                'errors'  => ['source' => ['This consultation has no finished call transcript.']],
            ], 422);
        }

        // An edit that does not say otherwise keeps where the summary came from,
        // so the patient keeps access to the transcript it was based on.
        $source = $validated['source']
            ?? ConsultationSummary::where('appointment_id', $appointment->id)->value('source')
            ?? 'doctor_note';

        $summary = ConsultationSummary::updateOrCreate(
            ['appointment_id' => $appointment->id],
            [
                'doctor_user_id'  => $appointment->doctor_user_id,
                'patient_user_id' => $appointment->patient_user_id,
                'chief_complaint' => $validated['chief_complaint'],
                'findings'        => $validated['findings'] ?? null,
                'advice'          => $validated['advice'] ?? null,
                'red_flags'       => array_values($validated['red_flags'] ?? []),
                'source'          => $source,
            ],
        );

        $appointment->setRelation('consultationSummary', $summary);

        return response()->json($this->payload($appointment), $summary->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Appointment $appointment): array
    {
        $doctor = $appointment->doctor;
        $summary = $appointment->consultationSummary;

        return [
            'appointment' => [
                'id'              => $appointment->id,
                'datetime'        => $appointment->appointment_datetime?->toIso8601String(),
                'status'          => $appointment->status,
                'durationMinutes' => $appointment->duration_minutes,
            ],
            'doctor' => [
                'id'        => $doctor?->id,
                'name'      => $doctor?->name,
                'specialty' => $doctor?->doctorProfile?->specialty,
                'avatarUrl' => $doctor?->avatar_url,
            ],
            'summary' => $summary ? (new ConsultationSummaryResource($summary))->resolve() : null,
        ];
    }
}
