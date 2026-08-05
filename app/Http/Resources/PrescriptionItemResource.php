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
            'name'              => $this->medicine_name,
            'dosage'            => $this->dosage,
            'scheduleFormat'    => is_array($this->dosage_schedule) ? implode('/', array_keys(array_filter($this->dosage_schedule))) : null,
            'dosageSchedule'    => $this->dosage_schedule,
            'instructions'      => $this->instructions,
            'durationDays'      => $this->duration_days,
        ];
    }
}
