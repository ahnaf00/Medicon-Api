<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorProfileResource extends JsonResource
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
            'specialty'             => $this->specialty,
            'qualification'         => $this->qualification,
            'experience_years'      => $this->experience_years,
            'consultation_fee'      => (float) $this->consultation_fee,
            'rating'                => (float) $this->rating,
            'bio'                   => $this->bio,
            'verification_status'   => $this->verification_status,
        ];
    }
}
