<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
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
            'datetime'      => $this->appointment_datetime?->toIso8601String(),
            'format'        => $this->format,
            'status'        => $this->status,
            'notes'         => $this->notes,
            'doctor'        => new UserResource($this->whenLoaded('doctor')),
            'patient'       => new UserResource($this->whenLoaded('patient')),
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
