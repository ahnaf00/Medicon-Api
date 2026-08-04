<?php

namespace App\Http\Requests\Api\Vitals;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVitalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }


    public function rules(): array
    {
        return [
            'blood_pressure'    => ['nullable', 'string', 'regex:/^\d{2,3}\/\d{2,3}$/'], // e.g. "120/80"
            'pulse_rate'        => ['nullable', 'integer', 'between:30,220'],
            'glucose_level'     => ['nullable', 'numeric', 'min:0', 'max:50'],
            'oxygen_saturation' => ['nullable', 'integer', 'between:50,100'],
            'logged_at'         => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'blood_pressure.regex'      => 'Blood pressure format must be SYS/DIA (e.g., 120/80).',
            'oxygen_saturation.between' => 'Oxygen saturation must be a percentage between 50 and 100.',
        ];
    }
}
