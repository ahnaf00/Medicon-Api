<?php

namespace App\Http\Resources;

use App\Models\LabResult;
use App\Models\MedicalRecordPage;
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
                ? self::signedUrl($this->file_path)
                : $this->file_url,
            'title'           => $this->title,
            'laboratoryName'  => $this->laboratory_name,
            // A calendar date, not an instant: no timezone attached.
            'reportDate'      => $this->report_date?->format('Y-m-d'),
            'analysisStatus'  => $this->analysis_status,
            'analysisError'   => $this->analysis_error,
            'aiSummary'       => $this->ai_summary,
            'analyzedAt'      => $this->analyzed_at?->toIso8601String(),
            'pageCount'       => $this->whenLoaded('pages', fn () => $this->pages->count()),
            'pages'           => $this->whenLoaded('pages', fn () => $this->pages->map(fn (MedicalRecordPage $page) => [
                'id'      => $page->id,
                'order'   => $page->page_order,
                'isPdf'   => $page->isPdf(),
                'fileUrl' => self::signedUrl($page->file_path),
            ])->values()),
            'labResults'      => $this->whenLoaded('labResults', fn () => $this->labResults->map(fn (LabResult $result) => [
                'id'            => $result->id,
                'panel'         => $result->panel,
                'subGroup'      => $result->sub_group,
                'name'          => $result->name,
                'value'         => $result->value,
                'unit'          => $result->unit,
                'referenceText' => $result->reference_text,
                'referenceLow'  => $result->reference_low,
                'referenceHigh' => $result->reference_high,
                'status'        => $result->status,
            ])->values()),
            'bloodPressure'   => $this->blood_pressure,
            'pulseRate'       => $this->pulse_rate,
            'glucoseLevel'    => $this->glucose_level ? (float) $this->glucose_level : null,
            'oxygenSaturation'=> $this->oxygen_saturation,
            'notes'           => $this->notes,
            'recordedBy'      => new UserResource($this->whenLoaded('recordedBy')),
            'createdAt'       => $this->created_at?->toIso8601String(),
        ];
    }

    private static function signedUrl(string $path): string
    {
        return Storage::disk('private')->temporaryUrl($path, now()->addMinutes(15));
    }
}
