<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OpenFdaService
{
    private const BASE_URL = 'https://api.fda.gov/drug/label.json';

    public function searchDrug(string $query): array
    {
        $searchQuery = 'openfda.brand_name:"' . $query . '" OR openfda.generic_name:"' . $query . '"';

        $response = Http::timeout(5)->get(self::BASE_URL, [
            'search' => $searchQuery,
            'limit'  => 10,
        ]);

        if ($response->failed() || empty($response->json('results'))) {
            return [];
        }

        return collect($response->json('results'))->map(function ($drug) {
            $openFdaData = $drug['openfda'] ?? [];

            return [
                'brandName'    => $openFdaData['brand_name'][0] ?? 'Unknown',
                'genericName'  => $openFdaData['generic_name'][0] ?? null,
                'manufacturer' => $openFdaData['manufacturer_name'][0] ?? null,
                'purpose'      => $drug['purpose'][0] ?? $drug['indications_and_usage'][0] ?? null,
                'warnings'     => $drug['warnings'][0] ?? $drug['warnings_and_cautions'][0] ?? null,
            ];
        })->toArray();
    }

    public function checkInteractions(array $medicineNames): array
    {
        return [
            'disclaimer' => 'Only a very small list of known combinations is recognised. A pair marked "not checked" has not been confirmed safe. Consult a doctor or pharmacist.',
            'pairs'      => $this->basicHeuristicCheck($medicineNames),
        ];
    }

    private function basicHeuristicCheck(array $names): array
    {
        $knownInteractions = [
            ['warfarin', 'aspirin', 'Increased bleeding risk when combined.'],
            ['ssri', 'tramadol', 'Risk of serotonin syndrome.'],
            ['metformin', 'alcohol', 'Risk of lactic acidosis.'],
        ];

        $results = [];
        foreach ($names as $i => $nameA) {
            foreach (array_slice($names, $i + 1) as $nameB) {
                $warning = null;
                foreach ($knownInteractions as [$drugA, $drugB, $text]) {
                    $matchA = str_contains(strtolower($nameA), $drugA) || str_contains(strtolower($nameB), $drugA);
                    $matchB = str_contains(strtolower($nameA), $drugB) || str_contains(strtolower($nameB), $drugB);

                    if ($matchA && $matchB) {
                        $warning = $text;
                        break;
                    }
                }

                // No rule matched: the pair was not checked, which is not the same as safe.
                $results[] = [
                    'pair' => [$nameA, $nameB],
                    'warning' => $warning ?? 'Not checked — ask your doctor or pharmacist.',
                    'severity' => $warning !== null ? 'high' : 'unknown',
                    'checked' => $warning !== null,
                ];
            }
        }

        return $results;
    }
}
