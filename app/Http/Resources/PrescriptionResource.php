<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionResource extends JsonResource
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
            'appointmentId'     => $this->appointment_id,
            'diagnosisSummary'  => $this->diagnosis_summary,
            'followUpDate'      => $this->follow_up_date?->format('Y-m-d'),
            'advice'            => $this->advice,
            'status'            => $this->status,
            'doctor'            => new UserResource($this->whenLoaded('doctor')),
            'patient'           => new UserResource($this->whenLoaded('patient')),
            'medicines'         => $this->whenLoaded('items', fn () => PrescriptionItemResource::collection(
                // Items need their prescription to know who is the prescriber.
                $this->items->each->setRelation('prescription', $this->resource)
            )),
            'tests'             => $this->whenLoaded('tests', fn () => $this->tests->map(fn ($test) => [
                'id'            => $test->id,
                'name'          => $test->name,
                'instructions'  => $test->instructions,
            ])->values()),
            'createdAt'         => $this->created_at?->toIso8601String(),
        ];
    }
}
