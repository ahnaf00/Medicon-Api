<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use App\Http\Requests\Api\Auth\Concerns\NormalizesPhone;
use Illuminate\Foundation\Http\FormRequest;

class SendOtpRequest extends FormRequest
{
    use NormalizesPhone;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function messages(): array
    {
        return $this->phoneMessages();
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20', ...self::phoneRule()],
        ];
    }
}
