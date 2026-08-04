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
            'appointment_id'    => $this->appointment_id,
            'diagnosis_summary' => $this->diagnosis_summary,
            'status'            => $this->status,
            'doctor'            => new UserResource($this->whenLoaded('doctor')),
            'patient'           => new UserResource($this->whenLoaded('patient')),
            'medicines'         => PrescriptionItemResource::collection($this->whenLoaded('items')),
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
