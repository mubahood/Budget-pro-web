<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191', 'unique:admin_users,email'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6', 'max:100'],
            'company_name' => ['required', 'string', 'max:191'],
            'currency' => ['required', 'string', Rule::in(config('saas.currencies'))],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already registered.',
            'currency.in' => 'Please choose a supported currency.',
        ];
    }
}
