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
            'date_of_birth'         => $this->date_of_birth?->format('Y-m-d'),
            'gender'                => $this->gender,
            'blood_group'           => $this->blood_group,
            'emergency_contact'     => $this->emergency_contact,
            'address'               => $this->address,
        ];
    }
}
