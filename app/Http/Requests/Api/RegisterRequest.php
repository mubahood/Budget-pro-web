<?php

namespace App\Http\Requests\Api;

use App\Services\Onboarding\RegistrationService;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A known email is answered by RegistrationService (email_taken), which the controller turns into "log in to add this app"
     * (known_identity), never a bare "already registered" from the validator.
     */
    public function rules(): array
    {
        $rules = RegistrationService::rules();
        $rules['email'] = array_values(array_filter($rules['email'], fn ($r) => ! (is_string($r) && str_starts_with($r, 'unique:'))));
        $rules['app'] = ['nullable', 'string', 'max:40'];

        return $rules;
    }

    public function messages(): array
    {
        return RegistrationService::messages();
    }
}
