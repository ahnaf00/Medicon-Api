<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VitalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => (string) $this->id,
            'bloodPressure'     => $this->blood_pressure,
            'pulseRate'         => $this->pulse_rate ? (int) $this->pulse_rate : null,
            'glucoseLevel'      => $this->glucose_level ? (float) $this->glucose_level : null,
            'oxygenSaturation'  => $this->oxygen_saturation ? (int) $this->oxygen_saturation : null,
            'loggedAt'          => $this->logged_at?->toIso8601String(),
            'createdAt'         => $this->created_at?->toIso8601String(),
        ];
    }
}
