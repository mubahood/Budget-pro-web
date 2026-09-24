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

    public function rules(): array
    {
        return RegistrationService::rules();
    }

    public function messages(): array
    {
        return RegistrationService::messages();
    }
}
