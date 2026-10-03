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
            'id'              => $this->id,
            'datetime'        => $this->appointment_datetime?->toIso8601String(),
            'format'          => $this->format === 'in_person' ? 'in-person' : $this->format,
            'status'          => $this->status,
            'notes'           => $this->notes,
            'durationMinutes' => $this->duration_minutes,
            'startedAt'       => $this->started_at?->toIso8601String(),
            'endedAt'         => $this->ended_at?->toIso8601String(),
            // Only present where the list query loads it (GET /appointments).
            'hasSummary'      => $this->when(
                array_key_exists('consultation_summary_exists', $this->resource?->getAttributes() ?? []),
                fn () => (bool) $this->consultation_summary_exists,
            ),
            'doctor'          => new UserResource($this->whenLoaded('doctor')),
            'patient'         => new UserResource($this->whenLoaded('patient')),
            'createdAt'       => $this->created_at?->toIso8601String(),
        ];
    }
}
