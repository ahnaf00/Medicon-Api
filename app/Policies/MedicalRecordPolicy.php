<?php

namespace App\Policies;

use App\Models\MedicalRecord;
use App\Models\User;

class MedicalRecordPolicy
{
    public function view(User $user, MedicalRecord $record): bool
    {
        return $user->id === (int) $record->patient_user_id;
    }

    public function delete(User $user, MedicalRecord $record): bool
    {
        return $user->id === (int) $record->patient_user_id;
    }
}
