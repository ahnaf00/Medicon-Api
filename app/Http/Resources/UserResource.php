<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'name'              => $this->name,
            'email'             => $this->email,
            'phone'             => $this->phone,
            'status'            => $this->status,
            'role'              => $this->getRoleNames()->first(),
            'doctorProfile'     => new DoctorProfileResource($this->whenLoaded('doctorProfile')),
            'patientProfile'    => new PatientProfileResource($this->whenLoaded('patientProfile')),
            'createdAt'         => $this->created_at?->toIso8601String(),
        ];
    }
}
