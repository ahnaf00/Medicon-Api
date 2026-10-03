<?php

namespace App\Policies;

use App\Models\PatientProfile;
use App\Models\User;

class PatientProfilePolicy
{
    public function view(User $user, PatientProfile $profile): bool
    {
        if ($user->id === (int) $profile->user_id) {
            return true;
        }

        return $user->isVerifiedDoctor()
            && $user->hasAppointmentWithPatient((int) $profile->user_id);
    }
}
