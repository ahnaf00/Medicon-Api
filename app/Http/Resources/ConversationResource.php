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
        return [
                'id'            => $this->id,
                'subject'       => $this->subject,
                'status'        => $this->status,
                'department'    => $this->department,
                'patient'       => new UserResource($this->whenLoaded('patient')),
                'doctor'        => new UserResource($this->whenLoaded('doctor')),
                'firstMessage'  => new MessageResource($this->whenLoaded('firstMessage')),
                'latestMessage' => new MessageResource($this->whenLoaded('latestMessage')),
                'createdAt'     => $this->created_at?->toIso8601String(),
            ];
    }
}
