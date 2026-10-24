<?php

namespace App\Http\Requests\Api\Vitals;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Only readings a person could actually have (audit CLIN-007). Ranges are wide
 * on purpose — they reject typos and wrong units, not worrying values.
 */
class StoreVitalRequest extends FormRequest
{
    private const FIELDS = ['blood_pressure', 'pulse_rate', 'glucose_level', 'oxygen_saturation'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $atLeastOne = 'required_without_all:'.implode(',', self::FIELDS);

        return [
            'blood_pressure' => ['nullable', 'string', 'regex:/^\d{2,3}\/\d{2,3}$/', $atLeastOne, $this->plausibleBloodPressure(...)],
            'pulse_rate' => ['nullable', 'integer', 'between:30,220', $atLeastOne],
            // mmol/L, as the app asks. Meters read about 1.1 – 33.3; mg/dL values (e.g. 110) land above 35.
            'glucose_level' => ['nullable', 'numeric', 'between:1,35', $atLeastOne],
            'oxygen_saturation' => ['nullable', 'integer', 'between:50,100', $atLeastOne],
            // A few minutes' grace for a phone clock that runs fast.
            'logged_at' => ['nullable', 'date', 'after:2000-01-01', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
        ];
    }

    private function plausibleBloodPressure(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^(\d{2,3})\/(\d{2,3})$/', $value, $m)) {
            return; // the regex rule reports the format
        }

        [$systolic, $diastolic] = [(int) $m[1], (int) $m[2]];

        if ($systolic < 60 || $systolic > 260 || $diastolic < 30 || $diastolic > 160) {
            $fail('Blood pressure must be between 60/30 and 260/160 mmHg.');
        } elseif ($systolic <= $diastolic) {
            $fail('The first (systolic) number must be higher than the second (diastolic), e.g. 120/80.');
        }
    }

    public function messages(): array
    {
        $atLeastOne = 'Enter at least one reading.';

        return [
            'blood_pressure.regex' => 'Blood pressure format must be SYS/DIA (e.g., 120/80).',
            'blood_pressure.required_without_all' => $atLeastOne,
            'pulse_rate.required_without_all' => $atLeastOne,
            'glucose_level.required_without_all' => $atLeastOne,
            'oxygen_saturation.required_without_all' => $atLeastOne,
            'glucose_level.between' => 'Enter glucose in mmol/L, between 1 and 35 (100 mg/dL is about 5.6 mmol/L).',
            'oxygen_saturation.between' => 'Oxygen saturation must be a percentage between 50 and 100.',
            'logged_at.before_or_equal' => 'A reading cannot be in the future.',
        ];
    }
}
