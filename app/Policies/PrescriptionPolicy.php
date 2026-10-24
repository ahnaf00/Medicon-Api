<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PrescriptionPolicy
{
    public function view(User $user, Prescription $prescription): bool
    {
        return $user->id === (int) $prescription->patient_user_id
            || $user->id === (int) $prescription->doctor_user_id;
    }

    /** Only the prescribing doctor reviews what the patient reads about it (audit CLIN-006). */
    public function update(User $user, Prescription $prescription): bool
    {
        return $user->isVerifiedDoctor() && $user->id === (int) $prescription->doctor_user_id;
    }

    /** A visit that has actually taken place (or is taking place) — the only basis for a prescription. */
    public const PRESCRIBABLE_VISIT_STATUSES = ['in_progress', 'completed'];

    /**
     * A verified doctor may prescribe only after seeing the patient: the referenced
     * appointment, or else some appointment between them, must be in progress or
     * completed — never one that is merely booked, cancelled or missed (audit SEC-009).
     */
    public function create(User $user, int $patientUserId, ?int $appointmentId = null): Response
    {
        if (! $user->isVerifiedDoctor()) {
            return Response::deny('Only verified doctors can issue prescriptions.');
        }

        $seen = Appointment::where('doctor_user_id', $user->id)
            ->where('patient_user_id', $patientUserId)
            ->whereIn('status', self::PRESCRIBABLE_VISIT_STATUSES)
            ->when($appointmentId !== null, fn ($q) => $q->where('id', $appointmentId))
            ->exists();

        return $seen
            ? Response::allow()
            : Response::deny('You can prescribe only for a consultation with this patient that has started or finished.');
    }
}
