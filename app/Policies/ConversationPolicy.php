<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->isParticipant($user, $conversation)
            || $this->canClaim($user, $conversation);
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $this->isParticipant($user, $conversation)
            || $this->canClaim($user, $conversation);
    }

    private function isParticipant(User $user, Conversation $conversation): bool
    {
        return $user->id === (int) $conversation->patient_user_id
            || ($conversation->doctor_user_id !== null && $user->id === (int) $conversation->doctor_user_id);
    }

    /**
     * An unassigned question is open only to verified doctors of the matching department.
     */
    private function canClaim(User $user, Conversation $conversation): bool
    {
        return $conversation->doctor_user_id === null
            && $user->isVerifiedDoctor()
            && $conversation->department === $user->doctorProfile->specialty;
    }
}
