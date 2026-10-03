<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

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

    private function isParticipant(User $user, Appointment $appointment): bool
    {
        return $user->id === (int) $appointment->patient_user_id
            || $user->id === (int) $appointment->doctor_user_id;
    }
}
