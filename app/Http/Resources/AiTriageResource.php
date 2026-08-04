<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiTriageResource extends JsonResource
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
            'symptomsSummary'   => $this->symptoms_summary,
            'urgencyLevel'      => $this->urgency_level,
            'recommendedAction' => $this->recommended_action,
            'createdAt'         => $this->created_at?->toIso8601String(),
        ];
    }
}
