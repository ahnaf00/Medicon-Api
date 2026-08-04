<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HospitalResource extends JsonResource
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
            'name'              => $this->name,
            'address'           => $this->address,
            'latitude'          => $this->latitude ? (float) $this->latitude : null,
            'longitude'         => $this->longitude ? (float) $this->longitude : null,
            'emergencyPhone'    => $this->emergency_phone,
            'is247'             => (bool) $this->is_24_7,
        ];
    }
}
