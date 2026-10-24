<?php

namespace App\Http\Requests\Api\Conversations;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreConversationRequest extends FormRequest
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
            // Only a verified doctor can be asked directly, never any user id (audit SEC-011).
            'doctor_user_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! User::verifiedDoctors()->whereKey($value)->exists()) {
                    $fail('The selected doctor is not available.');
                }
            }],
            'subject'        => ['nullable', 'string', 'max:255'],
            'department'     => ['required', 'string', 'max:255'],
            'is_anonymous'   => ['sometimes', 'boolean'],
        ];
    }
}
