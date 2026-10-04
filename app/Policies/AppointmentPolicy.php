<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AppointmentPolicy
{
    public function view(User $user, Appointment $appointment): bool
    {
        return $this->isParticipant($user, $appointment);
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $this->isParticipant($user, $appointment);
    }

    /** Only the appointment's own doctor moves it through the visit lifecycle. */
    public function updateStatus(User $user, Appointment $appointment): bool
    {
        return $user->id === (int) $appointment->doctor_user_id;
    }

    public function viewSummary(User $user, Appointment $appointment): bool
    {
        return $this->isParticipant($user, $appointment);
    }

    /** The summary is the doctor's clinical note: only that appointment's doctor writes it. */
    public function writeSummary(User $user, Appointment $appointment): bool
    {
        return $user->id === (int) $appointment->doctor_user_id;
    }

    /** The consultation chat is the patient's: only that appointment's patient may use it. */
    public function consultationChat(User $user, Appointment $appointment): bool
    {
        return $user->id === (int) $appointment->patient_user_id;
    }

    /** Only the two participants join, and only a video appointment has a call (422 otherwise). */
    public function joinCall(User $user, Appointment $appointment): Response
    {
        if (! $this->isParticipant($user, $appointment)) {
            return Response::deny();
        }

        return $appointment->format === 'video'
            ? Response::allow()
            : Response::denyWithStatus(422, 'This appointment is not a video consultation.');
    }

    /**
     * The doctor may always see the transcript. The patient sees it only once the
     * doctor has reviewed it and saved a transcript-based summary.
     */
    public function viewTranscript(User $user, Appointment $appointment): bool
    {
        if ($user->id === (int) $appointment->doctor_user_id) {
            return true;
        }

        return $user->id === (int) $appointment->patient_user_id
            && $appointment->consultationSummary()->where('source', 'transcript')->exists();
    }

    private function isParticipant(User $user, Appointment $appointment): bool
    {
        return $user->id === (int) $appointment->patient_user_id
            || $user->id === (int) $appointment->doctor_user_id;
    }
}
