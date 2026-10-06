<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConsultationTranscriptResource;
use App\Models\Appointment;
use App\Models\ConsultationTranscript;
use App\Services\ConsultationTranscriptPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The call transcript of a video consultation (Phase 6).
 *
 * The doctor can always read it (with the AI draft summary). The patient can
 * read it only after the doctor has reviewed it and saved a transcript-based
 * summary (AppointmentPolicy::viewTranscript).
 */
class ConsultationTranscriptController extends Controller
{
    public function __construct(private readonly ConsultationTranscriptPipeline $pipeline) {}

    public function show($appointmentId): ConsultationTranscriptResource
    {
        $appointment = Appointment::findOrFail($appointmentId);
        Gate::authorize('viewTranscript', $appointment);

        return new ConsultationTranscriptResource($this->transcriptOf($appointment));
    }

    /** Doctor re-runs a failed transcription or summary from the kept audio. */
    public function retry($appointmentId): JsonResponse
    {
        $appointment = Appointment::findOrFail($appointmentId);
        Gate::authorize('writeSummary', $appointment);

        $transcript = $this->transcriptOf($appointment);

        if (! $this->pipeline->retry($transcript)) {
            return response()->json([
                'message' => 'Only a failed transcript can be retried.',
            ], 409);
        }

        return (new ConsultationTranscriptResource($transcript->fresh()))
            ->response()
            ->setStatusCode(202);
    }

    private function transcriptOf(Appointment $appointment): ConsultationTranscript
    {
        $transcript = $appointment->transcript;
        abort_if($transcript === null, 404, 'This consultation has no call transcript.');

        return $transcript->setRelation('appointment', $appointment)->load('segments');
    }
}
