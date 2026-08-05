<?php

namespace App\Http\Requests\Api\Conversations;

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
            'doctor_user_id' => ['required', 'exists:users,id'],
            'subject'        => ['nullable', 'string', 'max:255'],
        ];
    }
}
