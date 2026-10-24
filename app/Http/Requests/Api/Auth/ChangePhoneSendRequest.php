<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Api\Auth\Concerns\NormalizesPhone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Step 1 of changing the account's phone: a code goes to the new number (audit F12). */
class ChangePhoneSendRequest extends FormRequest
{
    use NormalizesPhone;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function messages(): array
    {
        return $this->phoneMessages() + [
            'phone.unique' => 'This number is already used by another account.',
        ];
    }

    public function rules(): array
    {
        return [
            'phone' => [
                'required', 'string', 'max:20', ...self::phoneRule(),
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($value === $this->user()->phone) {
                        $fail('This is already your number.');
                    }
                },
                'unique:users,phone',
            ],
        ];
    }
}
