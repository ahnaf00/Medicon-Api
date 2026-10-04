<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AppointmentLifecycle;
use App\Services\LiveKitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

/**
 * In-app video consultation (Phase 6): recording consent, LiveKit join tokens,
 * and ending the call. The doctor starts the visit by fetching the first token;
 * the patient can only join once it is in progress.
 */
class ConsultationCallController extends Controller
{
    public function __construct(
        private readonly LiveKitService $livekit,
        private readonly AppointmentLifecycle $lifecycle,
    ) {}

    /**
     * Store the caller's recording consent and, if the call is live, push it to
     * their LiveKit attributes (clients cannot set it themselves).
     */
    public function consent(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'consent' => ['required', 'boolean'],
        ]);

        $appointment = Appointment::findOrFail($id);
        Gate::authorize('joinCall', $appointment);

        if (! in_array($appointment->status, ['scheduled', 'in_progress'], true)) {
            return response()->json([
                'message' => 'Recording consent can only be changed before or during the call.',
            ], 409);
        }

        $user = $request->user();
        $role = $this->roleOf($user, $appointment);
        $consent = (bool) $validated['consent'];

        $appointment->transcript()->firstOrCreate([])->update([
            "{$role}_consent" => $consent,
            "{$role}_consent_at" => now(),
        ]);

        // No room exists before the doctor starts the call; the stored choice
        // goes into the next token instead.
        $live = false;
        if ($appointment->status === 'in_progress') {
            try {
                $live = $this->livekit->setConsent(
                    $this->livekit->roomName($appointment),
                    $this->livekit->identity($user),
                    $consent,
                );
            } catch (Throwable $e) {
                report($e);

                return response()->json([
                    'message' => 'Your choice was saved, but the call could not be updated. Please try again.',
                    'role' => $role,
                    'consent' => $consent,
                ], 502);
            }
        }

        return response()->json([
            'role' => $role,
            'consent' => $consent,
            'live' => $live,
        ]);
    }

    /**
     * A join token for `appointment-{id}`. The doctor's first token starts the
     * visit (scheduled → in_progress); the patient waits until then.
     */
    public function token(Request $request, $id): JsonResponse
    {
        $user = $request->user();

        return DB::transaction(function () use ($user, $id) {
            $appointment = Appointment::lockForUpdate()->findOrFail($id);
            Gate::authorize('joinCall', $appointment);

            $role = $this->roleOf($user, $appointment);

            // Starting a visit is a doctor capability; keep the same bar as
            // PATCH /appointments/{id}/status (role:doctor + doctor.verified).
            if ($role === 'doctor' && ! $user->isVerifiedDoctor()) {
                return response()->json([
                    'message' => 'Your doctor account has not been verified yet.',
                ], 403);
            }

            $joinable = $role === 'doctor' ? ['scheduled', 'in_progress'] : ['in_progress'];
            if (! in_array($appointment->status, $joinable, true)) {
                return response()->json([
                    'message' => $role === 'patient' && $appointment->status === 'scheduled'
                        ? 'Your doctor has not started this consultation yet.'
                        : "This consultation is {$appointment->status}.",
                ], 409);
            }

            $transcript = $appointment->transcript()->firstOrCreate([]);

            // Mint before changing status so a misconfigured server never
            // leaves a visit "in progress" with no way to join it.
            try {
                $token = $this->livekit->token($user, $appointment, $role, $transcript->consentFor($role) === true);
            } catch (RuntimeException $e) {
                report($e);

                return response()->json([
                    'message' => 'Video calls are not available on this server right now.',
                ], 503);
            }

            if ($appointment->status === 'scheduled') {
                $this->lifecycle->transition($appointment, 'in_progress');
            }

            return response()->json([
                'token' => $token,
                'url' => $this->livekit->url(),
                'room' => $this->livekit->roomName($appointment),
            ]);
        });
    }

    /**
     * Doctor ends the consultation: close the room for everyone, then complete
     * the visit. If LiveKit can't close the room the visit stays in progress,
     * so the doctor can retry.
     */
    public function end(Request $request, $id): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);
        Gate::authorize('updateStatus', $appointment);
        Gate::authorize('joinCall', $appointment);

        if (! $this->lifecycle->canTransition($appointment, 'completed')) {
            return response()->json([
                'message' => $this->lifecycle->rejectionMessage($appointment, 'completed'),
            ], 409);
        }

        try {
            $this->livekit->deleteRoom($this->livekit->roomName($appointment));
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'The call could not be closed. Please try again.',
            ], 502);
        }

        $this->lifecycle->transition($appointment, 'completed');

        return response()->json([
            'message' => 'Consultation ended.',
            'appointment' => new AppointmentResource($appointment->load(['doctor.doctorProfile', 'patient.patientProfile'])),
        ]);
    }

    /** Only called after joinCall, so the user is one of the two participants. */
    private function roleOf(User $user, Appointment $appointment): string
    {
        return $user->id === (int) $appointment->doctor_user_id ? 'doctor' : 'patient';
    }
}
