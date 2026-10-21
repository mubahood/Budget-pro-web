<?php

namespace App\Services\Sacco\YearEnd;

use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\SaccoSetting;
use App\Services\Billing\Quotas;
use App\Services\Messaging\Messenger;
use Illuminate\Support\Facades\DB;

/**
 * The year-end's messages to members (04 §10, P4.8, P4.10): a dividend paid, and the notice that an account has gone
 * dormant. Sent after the commit, through Messenger (the log driver in dev and tests; demo SACCOs skipped), in the
 * SACCO's own name and channel, never naming another product (AK-89). A member who opted out gets nothing.
 */
final class YearEndNotices
{
    public const TEMPLATES = [
        'dividend_paid' => '{sacco}: dividend for {year} of {amount}: {to}. Thank you for your shares.',
        'dormancy_notice' => '{sacco}: savings account {account} has had no activity for {months} months and is now dormant. Visit us to use it again.',
    ];

    /** @param array<string, string|int> $vars */
    public static function text(string $key, array $vars): string
    {
        $text = self::TEMPLATES[$key];
        foreach ($vars as $k => $v) {
            $text = str_replace('{'.$k.'}', (string) $v, $text);
        }

        return $text;
    }

    /** Queue a message for after the current transaction commits. @param array<string, string|int> $vars */
    public static function toMember(int $companyId, int $memberId, string $key, array $vars): void
    {
        DB::afterCommit(fn () => self::deliver($companyId, $memberId, $key, $vars));
    }

    /** @param array<string, string|int> $vars */
    private static function deliver(int $companyId, int $memberId, string $key, array $vars): void
    {
        $member = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->find($memberId);
        $settings = SaccoSetting::for($companyId);
        $company = Company::withoutGlobalScopes()->find($companyId);
        if ($member === null || $settings === null || $company === null || $member->messages_opt_out_at !== null || blank($member->phone_e164)
            || in_array($settings->receipts_channel, ['none', 'app'], true)) {
            return;
        }
        // SMS only on a plan that sells it and within this month's allowance; otherwise WhatsApp (AK-38).
        $quotas = app(Quotas::class);
        $limit = $quotas->limit($company, 'sms');
        $sms = $settings->receipts_channel === 'sms' && $quotas->featureOn($company, 'sacco_sms_receipts') && ($limit === null || $quotas->used($company, 'sms') < $limit);
        app(Messenger::class)->send((string) $member->phone_e164, self::text($key, ['sacco' => (string) ($settings->short_name ?: $settings->legal_name)] + $vars),
            $sms ? ['sms'] : ['whatsapp'], ['company_id' => $companyId, 'purpose' => 'sacco_notice', 'sacco_member_id' => $memberId, 'context' => ['template' => $key]]);
    }
}
