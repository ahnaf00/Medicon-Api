<?php

namespace App\Http\Resources;

use App\Models\ConsultationTranscriptSegment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A consultation's call transcript. The AI draft summary is the doctor's
 * working material and is only included for the appointment's doctor; the
 * patient reads the summary the doctor saved instead.
 */
class ConsultationTranscriptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isDoctor = $request->user()?->id === (int) $this->appointment?->doctor_user_id;

        return [
            'appointmentId' => $this->appointment_id,
            'status'        => $this->status,
            'skipReason'    => $this->skip_reason,
            'error'         => $this->status === 'failed' ? $this->error : null,
            'language'      => $this->language,
            'transcribedAt' => $this->transcribed_at?->toIso8601String(),
            'draftSummary'  => $this->when($isDoctor, fn () => $this->draft_summary ? [
                'chiefComplaint' => $this->draft_summary['chief_complaint'] ?? null,
                'findings'       => $this->draft_summary['findings'] ?? null,
                'advice'         => $this->draft_summary['advice'] ?? null,
                'redFlags'       => $this->draft_summary['red_flags'] ?? [],
            ] : null),
            'segments' => $this->segments->map(fn (ConsultationTranscriptSegment $s) => [
                'speaker' => $s->speaker_role,
                'startMs' => $s->start_ms,
                'text'    => $s->text,
            ])->values(),
        ];
    }
}
