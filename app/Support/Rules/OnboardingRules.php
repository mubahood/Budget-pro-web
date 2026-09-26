<?php

namespace App\Support\Rules;

use Illuminate\Validation\Rule;

/**
 * Setup wizard input (plan C2, POWER_PLAN §3.2): the same rules for the phone app's
 * /api/v1/onboarding endpoints and the new web wizard.
 */
class OnboardingRules
{
    public static function business(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'business_type' => ['required', Rule::in(array_keys(config('onboarding.business_types')))],
            'country' => ['required', Rule::in(array_keys(config('onboarding.countries')))],
            'currency' => ['nullable', Rule::in(config('saas.currencies'))],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', Rule::in(['en', 'sw', 'lg'])],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'logo' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'modules' => ['nullable', 'array'],
            'modules.*' => [Rule::in(array_keys(config('onboarding.modules')))],
            'seconds' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public static function money(): array
    {
        return [
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => [Rule::in(array_keys(config('onboarding.payment_methods')))],
            'momo_providers' => ['nullable', 'array'],
            'momo_providers.*' => ['string', 'max:30'],
            'opening_float' => ['nullable', 'numeric', 'min:0'],
            'receipt_channels' => ['nullable', 'array'],
            'receipt_channels.*' => [Rule::in(['whatsapp', 'print', 'sms'])],
            'negative_stock_policy' => ['nullable', Rule::in(['allow', 'flag', 'block'])],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'require_shift' => ['nullable', 'boolean'],
            'seconds' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
