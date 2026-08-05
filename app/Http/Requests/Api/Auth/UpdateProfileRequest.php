<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
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
            $rules['specialty']         = ['sometimes', 'string', 'max:255'];
            $rules['qualification']     = ['sometimes', 'string', 'max:255'];
            $rules['experience_years']  = ['sometimes', 'integer', 'min:0', 'max:60'];
            $rules['consultation_fee']  = ['sometimes', 'numeric', 'min:0'];
            $rules['bio']               = ['sometimes', 'string', 'max:2000'];
        }
        return $rules;
    }
}
