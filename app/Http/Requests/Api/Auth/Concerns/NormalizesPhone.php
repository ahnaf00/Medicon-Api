<?php

namespace App\Http\Requests\Api\Auth\Concerns;

use App\Support\PhoneNumber;

/**
 * Turns the request's phone into the stored +880 form before validation, so the
 * lookups and unique checks compare like with like (audit F13). A number that
 * can't be converted is left as typed and fails the phone rule.
 */
trait NormalizesPhone
{
    protected function prepareForValidation(): void
    {
        foreach ($this->phoneFields() as $field) {
            if ($this->filled($field) && ($phone = PhoneNumber::normalize($this->input($field)))) {
                $this->merge([$field => $phone]);
            }
        }
    }

    /** @return list<string> */
    protected function phoneFields(): array
    {
        return ['phone'];
    }

    /** @return list<string> */
    protected static function phoneRule(): array
    {
        return ['regex:'.PhoneNumber::PATTERN];
    }

    /** @return array<string, string> */
    protected function phoneMessages(): array
    {
        return ['phone.regex' => PhoneNumber::INVALID_MESSAGE];
    }
}
