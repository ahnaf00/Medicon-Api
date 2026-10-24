<?php

namespace App\Http\Resources;

use App\Models\PrescriptionUploadPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PrescriptionUploadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'doctorName' => $this->doctor_name,
            // A calendar date, not an instant: no timezone attached.
            'issuedOn'   => $this->issued_on?->format('Y-m-d'),
            'notes'      => $this->notes,
            'createdAt'  => $this->created_at?->toIso8601String(),
            'pageCount'  => $this->whenLoaded('pages', fn () => $this->pages->count()),
            'pages'      => $this->whenLoaded('pages', fn () => $this->pages->map(fn (PrescriptionUploadPage $page) => [
                'id'      => $page->id,
                'order'   => $page->page_order,
                'isPdf'   => $page->isPdf(),
                // Short-lived signed URL to the private file.
                'fileUrl' => Storage::disk('private')->temporaryUrl($page->file_path, now()->addMinutes(15)),
            ])->values()),
        ];
    }
}
