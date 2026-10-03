<?php

namespace App\Services;

use App\Http\Resources\PrescriptionDocumentResource;
use App\Models\Prescription;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders the prescription letterhead as a PDF. The image download is rendered
 * on the device from the same document payload (PrescriptionDocument.tsx),
 * because this server has no GD/Imagick to rasterise PDFs.
 */
class PrescriptionPdfService
{
    /**
     * @param  Prescription  $prescription  with items, tests, doctor.doctorProfile and patient.patientProfile loaded
     */
    public function renderPdf(Prescription $prescription): string
    {
        $doc = (new PrescriptionDocumentResource($prescription))->resolve();

        return Pdf::loadView('prescriptions.document', ['doc' => $doc])
            ->setPaper('a4')
            // Embed only the glyphs used; the full DejaVu fonts make the file ~5x larger.
            ->setOption('isFontSubsettingEnabled', true)
            ->output();
    }

    public function filename(Prescription $prescription): string
    {
        $date = $prescription->created_at
            ?->copy()
            ->setTimezone(PrescriptionDocumentResource::DISPLAY_TIMEZONE)
            ->format('Y-m-d');

        return "Prescription_{$prescription->id}_{$date}.pdf";
    }
}
