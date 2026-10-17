<?php

namespace App\Support\Rules;

use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Validation\Rules\Password;

/**
 * Laravel's Password rule with PasswordPolicy's plain words: one message for "too short, or no
 * letters, or no numbers" (the hint the screens show), and one for a leaked password. Used through
 * {@see PasswordPolicy::rule()}, in the kernel (Laravel 10) and the web portal (Laravel 12) alike.
 *
 * An empty value passes here: "required" or "nullable" on the field decides about empty.
 */
final class PasswordRule extends Password
{
    public function passes($attribute, $value)
    {
        $this->messages = [];
        if ($value === null || $value === '') {
            return true;
        }
        if (! is_string($value) || mb_strlen($value) < $this->min
            || ($this->letters && ! preg_match('/\pL/u', $value))
            || ($this->numbers && ! preg_match('/\pN/u', $value))
            || ($this->mixedCase && ! preg_match('/(\p{Ll}+.*\p{Lu})|(\p{Lu}+.*\p{Ll})/u', $value))
            || ($this->symbols && ! preg_match('/\p{Z}|\p{S}|\p{P}/u', $value))) {
            $this->messages = [PasswordPolicy::error()];

            return false;
        }
        if ($this->uncompromised && ! Container::getInstance()->make(UncompromisedVerifier::class)->verify([
            'value' => $value,
            'threshold' => $this->compromisedThreshold,
        ])) {
            $this->messages = [PasswordPolicy::LEAKED];

            return false;
        }

        return true;
    }
}
