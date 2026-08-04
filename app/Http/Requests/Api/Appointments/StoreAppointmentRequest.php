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
            'appointment_datetime'  => ['required', 'date', 'after:now'],
            'format'                => ['required', 'in:video,in_person'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'doctor_user_id.exists'         => 'The selected doctor does not exist.',
            'appointment_datetime.after'    => 'Appointments must be scheduled for a future time.',
        ];
    }
}
