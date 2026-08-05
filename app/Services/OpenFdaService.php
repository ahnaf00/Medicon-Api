<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OpenFdaService
{
    // Fix: Set the direct path to the JSON endpoint
    private const BASE_URL = 'https://fda.gov';

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
            'disclaimer' => 'Interaction checking is currently powered by a basic heuristic. Consult a pharmacist for clinical decisions.',
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
                foreach ($knownInteractions as [$drugA, $drugB, $warning]) {
                    $matchA = str_contains(strtolower($nameA), $drugA) || str_contains(strtolower($nameB), $drugA);
                    $matchB = str_contains(strtolower($nameA), $drugB) || str_contains(strtolower($nameB), $drugB);

                    if ($matchA && $matchB) {
                        $results[] = [
                            'pair' => [$nameA, $nameB],
                            'warning' => $warning,
                            'severity' => 'high'
                        ];
                    }
                }
            }
        }

        return $results ?: [['pair' => $names, 'warning' => 'No known interactions found.', 'severity' => 'none']];
    }
}
