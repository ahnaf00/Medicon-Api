<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or edit a hospital (audit L2b step 3). The route is already behind
 * `role:admin`; this checks the fields patients will rely on in an emergency.
 */
class HospitalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name' => [
                'required', 'string', 'min:3', 'max:150',
                // The same hospital twice would show twice in the patient list.
                Rule::unique('hospitals', 'name')
                    ->where('address', (string) $this->input('address'))
                    ->ignore($id),
            ],
            'address' => ['required', 'string', 'min:5', 'max:500'],
            // Hotlines (999, 16263) and full numbers (+880 2-55165088) are both valid.
            'emergency_phone' => ['required', 'string', 'max:30', 'regex:/^\+?\d[\d\s\-()]{1,28}$/'],
            'is_24_7' => ['required', 'boolean'],
            // Optional, but a map pin needs both halves.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A hospital with this name and address already exists.',
            'emergency_phone.regex' => 'Enter a phone number using digits, spaces, dashes or brackets, e.g. 999 or +880 2-55165088.',
            'latitude.required_with' => 'Enter both latitude and longitude, or leave both empty.',
            'longitude.required_with' => 'Enter both latitude and longitude, or leave both empty.',
        ];
    }
}
