<?php

namespace App\Http\Requests\Api\Auth;

use App\Services\SymptomTriageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function messages(): array
    {
        return [
            'bmdc_registration_no.prohibited' => 'Your BMDC registration number was verified and cannot be changed here. Contact MediCon support.',
            'bmdc_registration_no.regex' => 'Enter your BMDC registration number, e.g. A-12345.',
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        $user = $this->user();
        $rules = [
            // User fields
            'name'  => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:20', 'unique:users,phone,' . $user->id],
        ];
        // Patient-specific fields
        if ($user->hasRole('patient')) {
            $rules['date_of_birth']     = ['sometimes', 'date', 'before:today'];
            $rules['gender']            = ['sometimes', 'in:male,female,other'];
            $rules['blood_group']       = ['sometimes', 'in:A+,A-,B+,B-,AB+,AB-,O+,O-'];
            $rules['emergency_contact'] = ['sometimes', 'string', 'max:20'];
            $rules['address']           = ['sometimes', 'string', 'max:500'];
        }
        // Doctor-specific fields
        if ($user->hasRole('doctor')) {
            // Same department list as registration (audit P13).
            $rules['specialty']         = ['sometimes', 'string', Rule::in(SymptomTriageService::SPECIALTIES)];
            $rules['qualification']     = ['sometimes', 'string', 'max:255'];
            // Printed on prescriptions (audit D2). The registration number is the
            // credential checked at verification, so it is fixed once verified.
            $rules['bmdc_registration_no'] = $user->isVerifiedDoctor()
                ? ['prohibited']
                : ['sometimes', 'string', 'max:30', 'regex:/\d/'];
            $rules['hospital_name']     = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules['experience_years']  = ['sometimes', 'integer', 'min:0', 'max:60'];
            $rules['consultation_fee']  = ['sometimes', 'numeric', 'min:0'];
            $rules['bio']               = ['sometimes', 'string', 'max:2000'];
        }
        return $rules;
    }
}
