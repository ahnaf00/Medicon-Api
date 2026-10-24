<?php

namespace App\Http\Requests\Api\Auth;

use App\Services\SymptomTriageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'bmdc_registration_no.required_if' => 'Enter your BMDC registration number.',
            'bmdc_registration_no.regex' => 'Enter your BMDC registration number, e.g. A-12345.',
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['required', 'string', 'in:patient,doctor'],
            // Issued by POST /auth/verify-otp for a phone with no account yet.
            'registration_token' => ['required', 'string'],

            // Doctor-specific optional onboarding fields
            // One of the departments patients browse and symptom search recommends from.
            'specialty' => ['required_if:role,doctor', 'nullable', 'string', Rule::in(SymptomTriageService::SPECIALTIES)],
            'qualification' => ['required_if:role,doctor', 'nullable', 'string', 'max:255'],
            // Printed on every prescription and checked when the doctor is verified (audit D2).
            'bmdc_registration_no' => ['required_if:role,doctor', 'nullable', 'string', 'max:30', 'regex:/\d/'],
            'hospital_name' => ['nullable', 'string', 'max:255'],
            'consultation_fee' => ['nullable', 'numeric', 'min:0'],

            // Patient-specific optional onboarding fields
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female,other'],
            'blood_group' => ['nullable', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'],
            'height_cm' => ['nullable', 'numeric', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'allergies' => ['nullable', 'string'],
            'chronic_conditions' => ['nullable', 'string'],
        ];
    }
}
