<?php

namespace App\Http\Requests\Api\MedicalRecords;

use Illuminate\Foundation\Http\FormRequest;

class CheckInteractionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'medicines'        => ['required', 'array', 'min:2', 'max:5'],
            'medicines.*.name' => ['required', 'string', 'max:100'],
        ];
    }
}
