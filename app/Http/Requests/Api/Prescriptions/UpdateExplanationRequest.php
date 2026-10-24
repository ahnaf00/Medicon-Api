<?php

namespace App\Http\Requests\Api\Prescriptions;

use Illuminate\Foundation\Http\FormRequest;

/** The prescribing doctor rewrites or hides one medicine's explanation (audit CLIN-006). */
class UpdateExplanationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // ownership: PrescriptionPolicy::update
    }

    public function rules(): array
    {
        return [
            'explanation' => ['required_without:hidden', 'string', 'min:3', 'max:1000'],
            'hidden' => ['required_without:explanation', 'boolean'],
        ];
    }
}
