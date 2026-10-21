<?php

namespace App\Services\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoSetting;
use App\Support\Rules\SaccoRules;
use Illuminate\Support\Facades\DB;

/**
 * Changes to a SACCO's settings (PUT sacco/settings, the portal's Settings screen; permission `sacco_settings`):
 * the fields of SaccoRules::settings() only. The model keeps the rest: the mode after the first posting, the
 * currency always, the regulated line without a licence, one-officer approval outside group mode.
 */
final class SaccoSettingsService
{
    /** @param  array<string, mixed>  $data */
    public function update(int $companyId, array $data, ?int $userId = null): SaccoSetting
    {
        $validator = validator($data, SaccoRules::settings() + ['mode' => ['sometimes', \Illuminate\Validation\Rule::in(SaccoSetting::MODES)]]);
        if ($validator->fails()) {
            throw BusinessRuleException::make('invalid_settings', (string) $validator->errors()->first(), ['errors' => $validator->errors()->toArray()]);
        }

        return DB::transaction(function () use ($companyId, $validator, $userId) {
            $settings = SaccoSetting::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->first()
                ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
            $before = $settings->only(['mode', 'withdrawal_approval_above_minor', 'single_officer_mode', 'licence_no']);
            $settings->fill($validator->validated())->save();
            SaccoAudit::record('sacco.settings.changed', $settings, $companyId, ['before' => $before, 'after' => $settings->only(array_keys($before))], $userId);

            return $settings;
        });
    }
}
