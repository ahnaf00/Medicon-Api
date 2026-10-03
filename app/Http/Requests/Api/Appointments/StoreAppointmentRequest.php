<?php

namespace App\Http\Requests\Api\Appointments;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->hasRole('patient');
    }

    public function rules(): array
    {
        return [
            'doctor_user_id'        => ['required', 'exists:users,id'],
            // ISO-8601 with an offset (e.g. a slot's `datetime`); a value without an
            // offset is read as clinic time (Asia/Dhaka). Future/slot checks are in the controller.
            'appointment_datetime'  => ['required', 'date'],
            'format'                => ['required', 'in:video,in_person'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'doctor_user_id.exists'         => 'The selected doctor does not exist.',
        ];
    }
}
