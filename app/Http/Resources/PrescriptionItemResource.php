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
        // The prescribing doctor also sees what they hid; the patient never does.
        $isPrescriber = $this->relationLoaded('prescription')
            && $request->user()?->id === (int) $this->prescription->doctor_user_id;
        $showExplanation = $isPrescriber || ! $this->explanation_hidden;

        return [
            'id'                => $this->id,
            'name'              => $this->medicine_name,
            'dosage'            => $this->dosage,
            'scheduleFormat'    => is_array($this->dosage_schedule) ? implode('/', array_keys(array_filter($this->dosage_schedule))) : null,
            'dosageSchedule'    => $this->dosage_schedule,
            'instructions'      => $this->instructions,
            'durationDays'      => $this->duration_days,
            'explanation'       => $showExplanation ? $this->explanation : null,
            // ai | doctor — the app labels AI text as AI-generated (audit CLIN-006).
            'explanationSource' => $showExplanation && $this->explanation !== null ? ($this->explanation_source ?? 'ai') : null,
            'explanationHidden' => $this->when($isPrescriber, (bool) $this->explanation_hidden),
        ];
    }
}
