<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
         return [
            'id'                => $this->id,
            'appointmentId'     => $this->appointment_id,
            'diagnosisSummary'  => $this->diagnosis_summary,
            'status'            => $this->status,
            'doctor'            => new UserResource($this->whenLoaded('doctor')),
            'patient'           => new UserResource($this->whenLoaded('patient')),
            'medicines'         => PrescriptionItemResource::collection($this->whenLoaded('items')),
            'createdAt'         => $this->created_at?->toIso8601String(),
        ];
    }
}
