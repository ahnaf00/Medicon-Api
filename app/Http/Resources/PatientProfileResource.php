<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'dateOfBirth'           => $this->date_of_birth?->format('Y-m-d'),
            'gender'                => $this->gender,
            'bloodGroup'            => $this->blood_group,
            'emergencyContact'      => $this->emergency_contact,
            'address'               => $this->address,
        ];
    }
}
