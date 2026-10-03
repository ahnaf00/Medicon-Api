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
            'bmdcRegistrationNo'    => $this->bmdc_registration_no,
            'hospitalName'          => $this->hospital_name,
            'experience'            => $this->experience_years ? "{$this->experience_years}" : 'N/A',
            'experienceYears'       => (int) $this->experience_years,
            'consultationFee'       => (float) $this->consultation_fee,
            'rating'                => (float) $this->rating,
            'bio'                   => $this->bio,
            'verificationStatus'    => $this->verification_status,
            'followUpFee'           => (float) $this->follow_up_fee,
            'isOnline'              => $this->resource->isOnlineNow(),
            // Only present when computed for ranking (symptom search), so other endpoints don't pay for them.
            'completedConsultations' => $this->when(
                array_key_exists('completed_consultations_count', $this->resource?->getAttributes() ?? []),
                fn () => (int) $this->completed_consultations_count,
            ),
            'nextAvailableAt'       => $this->when(
                array_key_exists('next_available_at', $this->resource?->getAttributes() ?? []),
                fn () => $this->next_available_at,
            ),
        ];
    }
}
