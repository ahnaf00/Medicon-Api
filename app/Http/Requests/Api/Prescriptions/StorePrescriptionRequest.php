<?php

namespace App\Http\Requests\Api\Prescriptions;
use Illuminate\Foundation\Http\FormRequest;

class StorePrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->hasRole('doctor');
    }

    public function rules(): array
    {
        return [
            'appointment_id'                => ['nullable', 'exists:appointments,id'],
            'patient_user_id'               => ['required', 'exists:users,id'],
            'diagnosis_summary'             => ['required', 'string', 'max:2000'],
            'medicines'                     => ['required', 'array', 'min:1'],
            'medicines.*.medicine_name'     => ['required', 'string', 'max:255'],
            'medicines.*.dosage'            => ['required', 'string', 'max:100'],
            'medicines.*.dosage_schedule'   => ['nullable', 'array'],
            'medicines.*.instructions'      => ['nullable', 'string', 'max:255'],
            'medicines.*.duration_days'     => ['required', 'integer', 'min:1'],
        ];
    }
}
