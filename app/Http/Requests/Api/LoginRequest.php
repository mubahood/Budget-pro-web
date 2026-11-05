<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required_without:identifier', 'nullable', 'email'],
            'identifier' => ['required_without:email', 'nullable', 'string', 'max:191'], // phone or email
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'app' => ['nullable', 'string', 'max:40'], // the app signing in: a person with an account beside their first gets that app's
        ];
    }
}
