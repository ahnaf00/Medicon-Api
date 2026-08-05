<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiChatSessionResource extends JsonResource
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
            'title'         => $this->title,
            'latestMessage' => new AiChatMessageResource($this->whenLoaded('latestMessage')),
            'createdAt'     => $this->created_at?->toIso8601String(),
        ];
    }
}
