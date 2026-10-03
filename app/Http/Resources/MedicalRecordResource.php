<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MedicalRecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            // Short-lived signed URL to the private file; legacy rows fall back to file_url.
            'fileUrl'         => $this->file_path
                ? Storage::disk('private')->temporaryUrl($this->file_path, now()->addMinutes(15))
                : $this->file_url,
            'bloodPressure'   => $this->blood_pressure,
            'pulseRate'       => $this->pulse_rate,
            'glucoseLevel'    => $this->glucose_level ? (float) $this->glucose_level : null,
            'oxygenSaturation'=> $this->oxygen_saturation,
            'notes'           => $this->notes,
            'recordedBy'      => new UserResource($this->whenLoaded('recordedBy')),
            'createdAt'       => $this->created_at?->toIso8601String(),
        ];
    }
}
