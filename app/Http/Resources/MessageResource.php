<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Callers set the conversation relation; it lazy-loads otherwise.
        $conversation = $this->conversation;
        $fromPatient = (int) $this->sender_user_id === (int) $conversation->patient_user_id;
        $hideSender = $fromPatient && $conversation->hidesPatientFrom($request->user());

        return [
            'id'          => $this->id,
            'body'        => $this->body,
            'fromPatient' => $fromPatient,
            'sender'      => $hideSender ? null : new UserResource($this->whenLoaded('sender')),
            'readAt'      => $this->read_at?->toIso8601String(),
            'createdAt'   => $this->created_at?->toIso8601String(),
        ];
    }
}
