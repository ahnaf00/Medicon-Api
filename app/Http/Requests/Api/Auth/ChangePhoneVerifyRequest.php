<?php

namespace App\Http\Requests\Api\Auth;

/** Step 2 of changing the account's phone: the code sent to the new number. */
class ChangePhoneVerifyRequest extends ChangePhoneSendRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'otp' => ['required', 'string', 'size:6'],
        ];
    }
}
