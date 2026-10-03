<?php

namespace App\Services;

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the "Why take this medicine?" text for prescription items.
 *
 * Generated once, when the prescription is issued, and stored on
 * `prescription_items.explanation`. An explanation already written for the same
 * medicine + dosage is reused instead of calling the model again. If generation
 * fails the field stays null and the app simply hides the expander.
 */
class MedicineExplainerService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';

    private const SYSTEM_PROMPT = <<<'PROMPT'
You explain a prescribed medicine to a patient in Bangladesh in plain, friendly English.
Write 3 to 5 short sentences (under 110 words), no headings or lists:
what the medicine is commonly used for, how it works in simple terms, general timing advice
(for example with or after food), and why the full course should not be stopped early
without asking the prescribing doctor.
Do not suggest a different dose, schedule or medicine, do not diagnose, and do not
mention brand comparisons. If you do not recognise the medicine, reply with exactly: UNKNOWN
PROMPT;

    public function explainPrescription(Prescription $prescription): void
    {
        foreach ($prescription->items()->whereNull('explanation')->get() as $item) {
            $this->explain($item);
        }
    }

    public function explain(PrescriptionItem $item): ?string
    {
        if ($item->explanation !== null) {
            return $item->explanation;
        }

        $explanation = $this->findCached($item) ?? $this->generate($item);

        if ($explanation !== null) {
            $item->forceFill(['explanation' => $explanation])->save();
        }

        return $explanation;
    }

    private function findCached(PrescriptionItem $item): ?string
    {
        return PrescriptionItem::query()
            ->whereKeyNot($item->getKey())
            ->whereRaw('LOWER(TRIM(medicine_name)) = ?', [mb_strtolower(trim($item->medicine_name))])
            ->whereRaw('LOWER(TRIM(dosage)) = ?', [mb_strtolower(trim($item->dosage))])
            ->whereNotNull('explanation')
            ->value('explanation');
    }

    private function generate(PrescriptionItem $item): ?string
    {
        $apiKey = config('services.gemini.api_key');
        if (empty($apiKey)) {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post(self::ENDPOINT, [
                    'system_instruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
                    'contents' => [[
                        'parts' => [['text' => "Medicine: {$item->medicine_name}\nStrength: {$item->dosage}"]],
                    ]],
                ]);

            if (! $response->successful()) {
                Log::warning('Medicine explainer: Gemini returned '.$response->status());

                return null;
            }

            $text = trim((string) $response->json('candidates.0.content.parts.0.text', ''));

            return ($text === '' || $text === 'UNKNOWN') ? null : $text;
        } catch (Throwable $e) {
            Log::warning('Medicine explainer failed: '.$e->getMessage());

            return null;
        }
    }
}
