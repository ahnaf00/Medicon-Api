<?php

namespace App\Http\Requests\Api\AiTriage;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProcessTriageRequest extends FormRequest
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
            'symptoms_summary' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'symptoms_summary.required' => 'Please provide a description of your symptoms.',
            'symptoms_summary.min'      => 'Symptom description must be at least 10 characters long.',
        ];
    }
}
