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

    private function isParticipant(User $user, Appointment $appointment): bool
    {
        return $user->id === (int) $appointment->patient_user_id
            || $user->id === (int) $appointment->doctor_user_id;
    }
}
