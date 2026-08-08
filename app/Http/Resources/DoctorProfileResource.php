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
            'experience'            => $this->experience_years ? "{$this->experience_years}" : 'N/A',
            'experienceYears'       => (int) $this->experience_years,
            'consultationFee'       => (float) $this->consultation_fee,
            'rating'                => (float) $this->rating,
            'bio'                   => $this->bio,
            'verificationStatus'    => $this->verification_status,
            'followUpFee'           => (float) $this->follow_up_fee,
        ];
    }
}
