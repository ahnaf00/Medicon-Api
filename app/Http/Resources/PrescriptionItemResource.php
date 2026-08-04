<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionItemResource extends JsonResource
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
            'medicine_name'     => $this->medicine_name,
            'dosage'            => $this->dosage,
            'dosage_schedule'   => $this->dosage_schedule,
            'instructions'      => $this->instructions,
            'duration_days'     => $this->duration_days,
        ];
    }
}
