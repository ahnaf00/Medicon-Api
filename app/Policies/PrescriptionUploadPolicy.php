<?php

namespace App\Policies;

use App\Models\PrescriptionUpload;
use App\Models\User;

/** Only the patient who uploaded it; sharing with a doctor is a separate decision. */
class PrescriptionUploadPolicy
{
    public function view(User $user, PrescriptionUpload $upload): bool
    {
        return $user->id === (int) $upload->patient_user_id;
    }

    public function delete(User $user, PrescriptionUpload $upload): bool
    {
        return $user->id === (int) $upload->patient_user_id;
    }
}
