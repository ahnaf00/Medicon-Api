<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\Prescription;
use App\Models\User;

class PrescriptionPolicy
{
    public function view(User $user, Prescription $prescription): bool
    {
        return $user->id === (int) $prescription->patient_user_id
            || $user->id === (int) $prescription->doctor_user_id;
    }

    /**
     * A verified doctor may prescribe only to a patient who has booked with them.
     * If an appointment is referenced, it must be between this doctor and patient.
     */
    public function create(User $user, int $patientUserId, ?int $appointmentId = null): bool
    {
        if (! $user->isVerifiedDoctor() || ! $user->hasAppointmentWithPatient($patientUserId)) {
            return false;
        }

        if ($appointmentId === null) {
            return true;
        }

        return Appointment::where('id', $appointmentId)
            ->where('doctor_user_id', $user->id)
            ->where('patient_user_id', $patientUserId)
            ->exists();
    }
}
