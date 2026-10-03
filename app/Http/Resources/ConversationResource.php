<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Messages need their conversation to know whether to hide an anonymous patient.
        foreach (['firstMessage', 'latestMessage'] as $relation) {
            if ($this->relationLoaded($relation) && $this->{$relation}) {
                $this->{$relation}->setRelation('conversation', $this->resource);
            }
        }

        $hidePatient = $this->resource->hidesPatientFrom($request->user());

        return [
                'id'            => $this->id,
                'subject'       => $this->subject,
                'status'        => $this->status,
                'department'    => $this->department,
                'isAnonymous'   => (bool) $this->is_anonymous,
                // Replies from anyone but the asking patient; only present on the inbox listing.
                'replyCount'    => $this->when(
                    array_key_exists('reply_count', $this->resource->getAttributes()),
                    fn () => (int) $this->reply_count,
                ),
                'patient'       => $hidePatient ? null : new UserResource($this->whenLoaded('patient')),
                'doctor'        => new UserResource($this->whenLoaded('doctor')),
                'firstMessage'  => new MessageResource($this->whenLoaded('firstMessage')),
                'latestMessage' => new MessageResource($this->whenLoaded('latestMessage')),
                'createdAt'     => $this->created_at?->toIso8601String(),
            ];
    }
}
