<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Transcriber\ChunkUploadRequest;
use App\Models\Appointment;
use App\Models\ConsultationAudioChunk;
use App\Models\ConsultationTranscript;
use App\Services\ConsultationTranscriptPipeline;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Endpoints for the transcriber agent (tools/transcriber), behind
 * VerifyTranscriberSecret. The agent decides when to record; this controller
 * independently re-checks who is speaking and that both participants had
 * consented while the chunk was recorded, and stores nothing otherwise.
 *
 * Answers the agent relies on: 2xx = stored (the agent deletes its copy);
 * any other 4xx = refused for good (the agent keeps the file and stops).
 */
class TranscriberController extends Controller
{
    /** Visit statuses in which audio can still arrive (uploads finish after the call ends). */
    private const ACCEPTING_VISIT_STATUSES = ['in_progress', 'completed'];

    public function __construct(private readonly ConsultationTranscriptPipeline $pipeline) {}

    public function chunks(ChunkUploadRequest $request, string $room): JsonResponse
    {
        $appointment = $this->appointmentForRoom($room);

        $userId = $request->userId();
        $role = match ($userId) {
            (int) $appointment->doctor_user_id => 'doctor',
            (int) $appointment->patient_user_id => 'patient',
            default => null,
        };
        if ($role === null) {
            return response()->json(['message' => 'This identity is not a participant of the consultation.'], 403);
        }

        $transcript = $appointment->transcript;
        if (! $transcript || ! in_array($appointment->status, self::ACCEPTING_VISIT_STATUSES, true) || ! $transcript->isOpen()) {
            return response()->json(['message' => 'This consultation is not accepting audio.'], 409);
        }

        $sessionStartedAtMs = $request->sessionStartedAtMs();
        $startedAtMs = (int) $request->validated('started_at_ms');
        $durationMs = (int) $request->validated('duration_ms');

        $key = [
            'consultation_transcript_id' => $transcript->id,
            'user_id' => $userId,
            'session_started_at_ms' => $sessionStartedAtMs,
            'started_at_ms' => $startedAtMs,
        ];

        // An agent retry after a lost response: already stored, nothing to do.
        if ($existing = ConsultationAudioChunk::where($key)->first()) {
            return response()->json(['id' => $existing->id, 'duplicate' => true]);
        }

        $chunkStartMs = $sessionStartedAtMs + $startedAtMs;
        $graceMs = max(0, (int) config('services.transcriber.consent_grace_ms'));
        if (! $transcript->consentHeldThroughout($chunkStartMs, $chunkStartMs + $durationMs, $graceMs)) {
            return response()->json(['message' => 'Both participants had not consented while this audio was recorded.'], 403);
        }

        $disk = Storage::disk('private');
        $path = $request->file('file')->storeAs(
            "consultations/{$appointment->id}/audio",
            "{$sessionStartedAtMs}-user-{$userId}-{$startedAtMs}.flac",
            'private',
        );
        if ($path === false) {
            return response()->json(['message' => 'The audio could not be stored.'], 500);
        }

        try {
            $chunk = DB::transaction(function () use ($transcript, $key, $role, $path, $durationMs) {
                $chunk = ConsultationAudioChunk::create($key + [
                    'speaker_role' => $role,
                    'file_path' => $path,
                    'duration_ms' => $durationMs,
                ]);

                ConsultationTranscript::whereKey($transcript->id)
                    ->where('status', 'awaiting_call')
                    ->update(['status' => 'recording']);

                return $chunk;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent retry stored the same chunk first; it owns the file
            // (same deterministic path), so leave it in place.
            $existing = ConsultationAudioChunk::where($key)->firstOrFail();

            return response()->json(['id' => $existing->id, 'duplicate' => true]);
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        return response()->json(['id' => $chunk->id], 201);
    }

    /**
     * The agent's room session closed. A visit can have several sessions
     * (everyone left and rejoined), so this only finalises once the visit is
     * completed; the fallback queued by `call/end` covers the other order.
     */
    public function complete(Request $request, string $room): JsonResponse
    {
        $request->validate([
            'session_started_at' => ['required', 'date'],
        ]);

        $appointment = $this->appointmentForRoom($room);
        $transcript = $appointment->transcript()->firstOrCreate([]);

        $transcript->update(['agent_completed_at' => now()]);
        $this->pipeline->finalize($transcript);

        return response()->json(['status' => $transcript->fresh()->status]);
    }

    /** `appointment-{id}` of a video appointment, or 404. */
    private function appointmentForRoom(string $room): Appointment
    {
        abort_unless(preg_match('/^appointment-(\d+)$/', $room, $m) === 1, 404);

        return Appointment::where('format', 'video')->findOrFail((int) $m[1]);
    }
}
