<?php

namespace App\Http\Requests\Api\Prescriptions;

use App\Http\Requests\Api\MedicalRecords\StoreMedicalRecordRequest;
use Illuminate\Foundation\Http\FormRequest;

class StorePrescriptionUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'files'       => ['required', 'array', 'min:1', 'max:'.StoreMedicalRecordRequest::MAX_PAGES],
            'files.*'     => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'title'       => ['nullable', 'string', 'max:150'],
            'doctor_name' => ['nullable', 'string', 'max:150'],
            // A calendar date the patient reads off the paper: "today" is the clinic's
            // (Dhaka) today, not UTC's, or it is refused just after local midnight.
            'issued_on'   => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now(config('app.clinic_timezone', 'Asia/Dhaka'))->toDateString()],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ];
    }
}
