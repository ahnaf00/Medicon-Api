<?php

namespace App\Http\Requests\Api\Consultations;

use Illuminate\Foundation\Http\FormRequest;

class UpsertConsultationSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership of the appointment is checked in the controller via AppointmentPolicy.
        return true;
    }

    public function rules(): array
    {
        return [
            'chief_complaint' => ['required', 'string', 'max:2000'],
            'findings'        => ['nullable', 'string', 'max:5000'],
            'advice'          => ['nullable', 'string', 'max:5000'],
            'red_flags'       => ['nullable', 'array', 'max:10'],
            'red_flags.*'     => ['required', 'string', 'max:300'],
        ];
    }
}
