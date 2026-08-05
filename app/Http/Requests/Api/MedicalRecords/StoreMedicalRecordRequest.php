<?php

namespace App\Http\Requests\Api\MedicalRecords;

use Illuminate\Foundation\Http\FormRequest;

class StoreMedicalRecordRequest extends FormRequest
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
            'file'      => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'notes'     => ['nullable', 'string', 'max:2000'],
        ];
    }
}
