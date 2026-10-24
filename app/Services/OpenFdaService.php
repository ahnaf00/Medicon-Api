<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OpenFdaService
{
    private const BASE_URL = 'https://api.fda.gov/drug/label.json';

    /**
     * openFDA only knows US names. Names used in Bangladesh that differ are looked
     * up by their US generic name: international (INN) generics, and a few common
     * local brands whose ingredient is unambiguous.
     */
    public const US_NAMES = [
        // INN -> US generic name
        'paracetamol' => 'acetaminophen',
        'salbutamol' => 'albuterol',
        'adrenaline' => 'epinephrine',
        'noradrenaline' => 'norepinephrine',
        'frusemide' => 'furosemide',
        'glibenclamide' => 'glyburide',
        'lignocaine' => 'lidocaine',
        'pethidine' => 'meperidine',
        'levosalbutamol' => 'levalbuterol',
        'chlorphenamine' => 'chlorpheniramine',
        // Bangladeshi brands -> generic
        'napa' => 'acetaminophen',
        'ace' => 'acetaminophen',
        'seclo' => 'omeprazole',
        'sergel' => 'esomeprazole',
        'fexo' => 'fexofenadine',
        'alatrol' => 'cetirizine',
        'monas' => 'montelukast',
        'zimax' => 'azithromycin',
        'ciprocin' => 'ciprofloxacin',
        'filmet' => 'metronidazole',
    ];

    public function searchDrug(string $query): array
    {
        // The text goes inside a quoted openFDA phrase, where `"` and `\` would break
        // the query (audit P11).
        $clean = trim(preg_replace('/\s+/', ' ', str_replace(['"', '\\'], ' ', $query)));
        if (mb_strlen($clean) < 2) {
            return [];
        }

        $term = self::US_NAMES[mb_strtolower($clean)] ?? $clean;
        $searchedAs = $term !== $clean ? $term : null;

        $searchQuery = 'openfda.brand_name:"'.$term.'" OR openfda.generic_name:"'.$term.'"';

        $response = Http::timeout(5)->get(self::BASE_URL, [
            'search' => $searchQuery,
            'limit'  => 10,
        ]);

        if ($response->failed() || empty($response->json('results'))) {
            return [];
        }

        return collect($response->json('results'))->map(function ($drug) use ($searchedAs) {
            $openFdaData = $drug['openfda'] ?? [];

            return [
                'brandName'    => $openFdaData['brand_name'][0] ?? 'Unknown',
                'genericName'  => $openFdaData['generic_name'][0] ?? null,
                'manufacturer' => $openFdaData['manufacturer_name'][0] ?? null,
                'purpose'      => $drug['purpose'][0] ?? $drug['indications_and_usage'][0] ?? null,
                'warnings'     => $drug['warnings'][0] ?? $drug['warnings_and_cautions'][0] ?? null,
                // The US name actually searched, when it differs from what the user typed.
                'searchedAs'   => $searchedAs,
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
