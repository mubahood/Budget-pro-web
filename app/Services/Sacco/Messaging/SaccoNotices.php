<?php

namespace App\Services\Sacco\Messaging;

use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\SaccoSetting;
use App\Models\SaccoTransaction;
use App\Services\Billing\Quotas;
use App\Services\Messaging\Messenger;
use Illuminate\Support\Facades\DB;

/**
 * Messages a SACCO sends its members (docs/akiba/04-architecture.md §10, P1.17), always through Messenger (the log
 * driver in development and tests: message_log rows only), always after the posting's transaction commits
 * (DB::afterCommit), so a rolled-back posting never sends a receipt.
 *
 *   - The channel is the SACCO's `receipts_channel`: SMS only on a plan with `sacco_sms_receipts` and within its
 *     monthly SMS allowance, otherwise WhatsApp (an exhausted allowance falls back, it never fails); `app` means the
 *     member reads the receipt in the app (nothing is sent); `none` sends nothing.
 *   - Consent (Messenger::consentRefusal via `meta.sacco_member_id`): receipts and notices go unless the member opted
 *     out; reminders and offers also need `consent_messages_at`.
 *   - Signed with the SACCO's own name; the platform is never named. Demo SACCOs are skipped by Messenger.
 */
final class SaccoNotices
{
    /** Purposes members may refuse only by opting out (the rest need their consent to messages). */
    public const TRANSACTIONAL = ['sacco_receipt', 'sacco_notice'];

    /** Queue a posted receipt's message for after the commit. */
    public static function receipt(SaccoTransaction $tx): void
    {
        $id = (int) $tx->id;
        DB::afterCommit(fn () => (new self())->sendReceipt($id));
    }

    /** @return ?int the message_log id, or null when nothing is to be sent */
    public function sendReceipt(int $transactionId): ?int
    {
        $tx = SaccoTransaction::withoutGlobalScopes()->find($transactionId);
        if ($tx === null || $tx->status !== 'posted' || $tx->member_id === null) {
            return null;
        }
        $member = SaccoMember::withoutGlobalScopes()->find($tx->member_id);
        $message = SaccoMessages::forReceipt($tx, SaccoMessages::langOf($member));

        return $message === null || $member === null ? null : $this->send($member, $message, 'sacco_receipt', ['transaction_id' => $transactionId]);
    }

    /** Tell each holder of a product its new rate, after the commit (product_terms_change). */
    public static function termsChange(int $companyId, int $productId, string $productName, int $rateBp, string $effectiveFrom): void
    {
        DB::afterCommit(function () use ($companyId, $productId, $productName, $rateBp, $effectiveFrom) {
            $s = SaccoSetting::for($companyId);
            if ($s === null) {
                return;
            }
            $self = new self();
            foreach (\App\Services\Sacco\Savings\SavingsProducts::holders($companyId, $productId) as $memberId) {
                $member = SaccoMember::withoutGlobalScopes()->find($memberId);
                if ($member === null) {
                    continue;
                }
                $text = SaccoMessages::render('terms_notice', SaccoMessages::langOf($member), ['sacco' => (string) ($s->short_name ?: $s->legal_name), 'product' => $productName,
                    'rate' => rtrim(rtrim(number_format($rateBp / 100, 2, '.', ''), '0'), '.').' %', 'date' => \Illuminate\Support\Carbon::parse($effectiveFrom)->format('d/m/Y')]);
                $self->send($member, ['key' => 'terms_notice'] + $text, 'sacco_notice', ['product_id' => $productId]);
            }
        });
    }

    /**
     * Why Messenger must not send this to the member, or null (Messenger::consentRefusal's member branch).
     *
     * @return array{0: string, 1: string}|null [status, reason]
     */
    public static function consentRefusal(int $memberId, ?string $purpose): ?array
    {
        $m = SaccoMember::withoutGlobalScopes()->find($memberId);
        if ($m === null) {
            return ['skipped', 'No such member.'];
        }
        if ($m->messages_opt_out_at !== null) {
            return ['opted_out', $m->full_name.' asked for no messages.'];
        }
        if (! in_array($purpose, self::TRANSACTIONAL, true) && $m->consent_messages_at === null) {
            return ['no_consent', $m->full_name.' has not agreed to reminders.'];
        }

        return null;
    }

    /**
     * @param  array{key: string, sms: string, whatsapp: string}  $message
     * @param  array<string, mixed>  $context
     */
    private function send(SaccoMember $member, array $message, string $purpose, array $context): ?int
    {
        $company = Company::withoutGlobalScopes()->find($member->company_id);
        $s = SaccoSetting::for((int) $member->company_id);
        if ($company === null || $s === null || in_array($s->receipts_channel, ['none', 'app'], true)) {
            return null;
        }
        $to = $member->phone_e164;
        if (blank($to)) {
            return null;
        }
        $channels = ['whatsapp'];
        $text = $message['whatsapp'];
        if ($s->receipts_channel === 'sms' && $this->smsAllowed($company)) {
            $channels = ['sms'];
            $text = $message['sms'];
        }

        return app(Messenger::class)->send((string) $to, $text, $channels, ['company_id' => (int) $company->id, 'purpose' => $purpose, 'sacco_member_id' => (int) $member->id,
            'context' => $context + ['template' => $message['key']]]);
    }

    /** SMS on this plan and still within this month's allowance. */
    private function smsAllowed(Company $company): bool
    {
        $quotas = app(Quotas::class);
        if (! $quotas->featureOn($company, 'sacco_sms_receipts')) {
            return false;
        }
        $limit = $quotas->limit($company, 'sms');

        return $limit === null || $quotas->used($company, 'sms') < $limit;
    }
}
