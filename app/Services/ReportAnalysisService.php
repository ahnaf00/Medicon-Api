<?php

namespace App\Services;

use App\Models\LabResult;
use App\Models\MedicalRecord;
use App\Models\MedicalRecordPage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads a patient's uploaded report pages with Gemini and stores the extracted
 * lab results, report metadata and a plain-language summary.
 *
 * The uploaded files are only ever read here. Any failure marks the record
 * `failed` with a short reason and leaves the original document untouched.
 */
class ReportAnalysisService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';

    /** Gemini rejects inline requests above ~20 MB; stay safely below it. */
    private const MAX_INLINE_BYTES = 18 * 1024 * 1024;

    private const MAX_RESULTS = 200;

    public const GENERIC_ERROR = 'The analysis could not be completed. Your document is saved and you can try again.';

    private const SYSTEM_PROMPT = <<<'PROMPT'
You read medical laboratory reports for patients in Bangladesh. The pages of one report are attached in order.

Extract:
- title: the report or test name as printed (for example "HAEMATOLOGY - CBC"), or null.
- laboratory_name: the laboratory or hospital that issued it, or null.
- report_date: the date the report was issued or the sample was collected, as YYYY-MM-DD, or null.
- results: every test result printed in the report, in the printed order. For each:
  panel (the test or section, e.g. "Complete Blood Count"), sub_group (a sub-heading such as
  "Red Blood Cells", or null), name, value exactly as printed, unit or null,
  reference_text exactly as printed (keep sex- or age-specific ranges such as "F 11.5-15.5, M 13.8-18.0"),
  reference_low and reference_high only when a single numeric range applies to this patient (otherwise null),
  and status: low, normal or high only when the report marks it or the applicable range makes it clear; otherwise unknown.
- summary: 3 to 6 short sentences in plain English for the patient explaining what the results show,
  naming any values outside their reference range with the value and unit. Do not diagnose,
  do not recommend medicines or doses, and do not add a disclaimer.

Never invent values, units or ranges that are not printed. If the document is not a lab report,
return an empty results list and briefly describe the document in summary.
PROMPT;

    public function analyze(MedicalRecord $record): void
    {
        try {
            $this->run($record);
        } catch (ReportAnalysisException $e) {
            $this->markFailed($record, $e->getMessage());
        } catch (Throwable $e) {
            Log::warning("Report analysis failed for record {$record->id}: ".$e->getMessage());
            $this->markFailed($record, self::GENERIC_ERROR);
        }
    }

    public function markFailed(MedicalRecord $record, string $reason): void
    {
        $record->forceFill([
            'analysis_status' => 'failed',
            'analysis_error' => $reason,
        ])->save();
    }

    private function run(MedicalRecord $record): void
    {
        $apiKey = config('services.gemini.api_key');
        if (empty($apiKey)) {
            throw new ReportAnalysisException('Automatic report analysis is not available right now.');
        }

        $response = Http::timeout(60)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->post(self::ENDPOINT, [
                'system_instruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
                'contents' => [['parts' => $this->pageParts($record)]],
                'generationConfig' => [
                    'temperature' => 0,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => self::responseSchema(),
                ],
            ]);

        if (! $response->successful()) {
            Log::warning("Report analysis: Gemini returned {$response->status()} for record {$record->id}");
            throw new ReportAnalysisException(self::GENERIC_ERROR);
        }

        $data = json_decode((string) $response->json('candidates.0.content.parts.0.text', ''), true);
        if (! is_array($data)) {
            throw new ReportAnalysisException('We could not read results from this document.');
        }

        $summary = $this->text($data['summary'] ?? null, 5000);
        $results = $this->normaliseResults($data['results'] ?? []);

        if ($summary === null && $results === []) {
            throw new ReportAnalysisException('We could not read results from this document.');
        }

        DB::transaction(function () use ($record, $data, $summary, $results) {
            $record->labResults()->delete();
            $record->labResults()->createMany($results);

            // Fill metadata from the report, but never blank out a value we already have.
            $record->forceFill(array_filter([
                'title' => $this->text($data['title'] ?? null),
                'laboratory_name' => $this->text($data['laboratory_name'] ?? null),
                'report_date' => $this->reportDate($data['report_date'] ?? null),
            ], fn ($value) => $value !== null));

            $record->forceFill([
                'analysis_status' => 'completed',
                'analysis_error' => null,
                'ai_summary' => $summary,
                'analyzed_at' => now(),
            ])->save();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pageParts(MedicalRecord $record): array
    {
        $pages = $record->pages()->get();
        if ($pages->isEmpty()) {
            throw new ReportAnalysisException('This record has no document to analyse.');
        }

        $disk = Storage::disk('private');
        $parts = [];
        $total = 0;

        foreach ($pages as $page) {
            /** @var MedicalRecordPage $page */
            $contents = $disk->get($page->file_path);
            if ($contents === null) {
                throw new ReportAnalysisException('The document file could not be read.');
            }

            $total += strlen($contents);
            if ($total > self::MAX_INLINE_BYTES) {
                throw new ReportAnalysisException('This document is too large to analyse automatically. It is still saved.');
            }

            $parts[] = ['inline_data' => [
                'mime_type' => $page->mimeType(),
                'data' => base64_encode($contents),
            ]];
        }

        $parts[] = ['text' => 'Extract this report.'];

        return $parts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normaliseResults(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $results = [];
        foreach (array_slice($rows, 0, self::MAX_RESULTS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $this->text($row['name'] ?? null);
            $value = $this->text(is_scalar($row['value'] ?? null) ? (string) $row['value'] : null);
            if ($name === null || $value === null) {
                continue;
            }

            $low = is_numeric($row['reference_low'] ?? null) ? (float) $row['reference_low'] : null;
            $high = is_numeric($row['reference_high'] ?? null) ? (float) $row['reference_high'] : null;
            if ($low !== null && $high !== null && $low > $high) {
                $low = $high = null;
            }

            $results[] = [
                'panel' => $this->text($row['panel'] ?? null) ?? 'Results',
                'sub_group' => $this->text($row['sub_group'] ?? null),
                'name' => $name,
                'value' => $value,
                'unit' => $this->text($row['unit'] ?? null),
                'reference_text' => $this->text($row['reference_text'] ?? null),
                'reference_low' => $low,
                'reference_high' => $high,
                'status' => $this->status($value, $low, $high, $row['status'] ?? null),
                'order' => count($results),
            ];
        }

        return $results;
    }

    /**
     * When the value and a numeric range are both known, the status is computed
     * here rather than taken from the model.
     */
    private function status(string $value, ?float $low, ?float $high, mixed $modelStatus): string
    {
        if (is_numeric($value) && ($low !== null || $high !== null)) {
            $number = (float) $value;
            if ($low !== null && $number < $low) {
                return 'low';
            }
            if ($high !== null && $number > $high) {
                return 'high';
            }

            return 'normal';
        }

        return in_array($modelStatus, LabResult::STATUSES, true) ? $modelStatus : 'unknown';
    }

    private function reportDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = Carbon::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value || $date->isAfter(now()->endOfDay())) {
            return null;
        }

        return $value;
    }

    private function text(mixed $value, int $max = 255): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @return array<string, mixed>
     */
    private static function responseSchema(): array
    {
        $nullableString = ['type' => 'STRING', 'nullable' => true];
        $nullableNumber = ['type' => 'NUMBER', 'nullable' => true];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'title' => $nullableString,
                'laboratory_name' => $nullableString,
                'report_date' => $nullableString + ['description' => 'YYYY-MM-DD'],
                'summary' => ['type' => 'STRING'],
                'results' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'panel' => ['type' => 'STRING'],
                            'sub_group' => $nullableString,
                            'name' => ['type' => 'STRING'],
                            'value' => ['type' => 'STRING'],
                            'unit' => $nullableString,
                            'reference_text' => $nullableString,
                            'reference_low' => $nullableNumber,
                            'reference_high' => $nullableNumber,
                            'status' => ['type' => 'STRING', 'enum' => LabResult::STATUSES],
                        ],
                        'required' => ['panel', 'name', 'value', 'status'],
                    ],
                ],
            ],
            'required' => ['summary', 'results'],
        ];
    }
}
