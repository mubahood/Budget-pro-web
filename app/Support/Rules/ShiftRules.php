<?php

namespace App\Support\Rules;

/**
 * Till shifts change only by opening and closing (ShiftService). These are the inputs each takes,
 * for the mobile API and the new web interface alike.
 */
class ShiftRules
{
    /** @return array<string, array<int, mixed>> */
    public static function open(): array
    {
        return [
            'opening_float' => ['required', 'numeric', 'min:0'],
            'client_uuid' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function close(): array
    {
        return [
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
