<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\Message;
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

    /**
     * Only the asking patient may edit their question, and only before anyone has replied:
     * a doctor's answer must not end up attached to different words.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $user->id === (int) $conversation->patient_user_id && ! $conversation->hasReply();
    }

    /** Same rule as editing: an answered question is part of the doctor's record too. */
    public function delete(User $user, Conversation $conversation): bool
    {
        return $this->update($user, $conversation);
    }

    /** A message may be edited only by the participant who sent it. */
    public function updateMessage(User $user, Conversation $conversation, Message $message): bool
    {
        return (int) $message->conversation_id === $conversation->id
            && $user->id === (int) $message->sender_user_id
            && $this->isParticipant($user, $conversation);
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
