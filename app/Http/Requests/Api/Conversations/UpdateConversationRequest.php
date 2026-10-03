<?php

namespace App\Http\Requests\Api\Conversations;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'body'         => ['sometimes', 'string', 'max:5000'],
            'department'   => ['sometimes', 'string', 'max:255'],
            'is_anonymous' => ['sometimes', 'boolean'],
        ];
    }
}
