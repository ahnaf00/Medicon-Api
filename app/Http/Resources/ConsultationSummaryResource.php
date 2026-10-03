<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsultationSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'appointmentId'  => $this->appointment_id,
            'chiefComplaint' => $this->chief_complaint,
            'findings'       => $this->findings,
            'advice'         => $this->advice,
            'redFlags'       => $this->red_flags ?? [],
            'source'         => $this->source,
            'createdAt'      => $this->created_at?->toIso8601String(),
            'updatedAt'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
