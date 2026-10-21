<?php

namespace App\Services\Sacco\Loans;

use App\Models\SaccoMember;
use App\Models\SaccoSetting;
use App\Services\Messaging\Messenger;
use Illuminate\Support\Facades\DB;

/**
 * The loan messages (04 §10, P2.20): sent only after the transaction that caused them commits, so a rolled-back
 * approval or posting never tells a member anything; through Messenger (the log driver in dev and tests, demo SACCOs
 * skipped); never to anyone but the borrower and their guarantors (SI 108 reg. 13). A member who opted out gets
 * nothing; reminders also need their consent to messages. Placeholders only, each text an SMS (≤ 160 characters
 * once filled for ordinary amounts), in the SACCO's own short name.
 */
final class LoanNotices
{
    public const TEMPLATES = [
        'loan_approved' => '{sacco}: loan {loan} of {amount} approved. Sign within {days} days. Total cost {cost}.',
        'loan_disbursed' => '{sacco}: {amount} paid to {channel}. First instalment {due} on {date}. You may cancel within {cooling} working days.',
        'loan_rejected' => '{sacco}: loan application {loan} was not approved. You may appeal to the board.',
        'receipt_repayment' => '{sacco}: loan {loan} repayment {amount}. Still owed {outstanding}. Rct {receipt}.',
        'loan_closed' => '{sacco}: loan {loan} is fully paid. Thank you.',
        'due_reminder' => '{sacco}: instalment {due} on loan {loan} is due {date}.',
        'overdue_notice' => '{sacco}: loan {loan} is {days} days overdue: {arrears}. Please pay or talk to us.',
        'guarantor_request' => '{sacco}: {borrower} asks you to guarantee {amount} on a loan of {loan_amount}. You may be liable for up to {amount}. Open {link} to read and reply.',
        'guarantor_exposure' => "{sacco}: {borrower}'s loan is in arrears. Up to {amount} of your savings may be used.",
        'guarantor_recovery' => "{sacco}: {amount} was taken from your savings for {borrower}'s loan {loan}, which you guaranteed.",
    ];

    /** Reminders are not receipts: they need the member's consent to messages, not just no opt-out. */
    public const REMINDERS = ['due_reminder', 'overdue_notice', 'guarantor_exposure'];

    /** @param  array<string, string|int>  $vars */
    public static function text(string $key, array $vars): string
    {
        $text = self::TEMPLATES[$key];
        foreach ($vars as $k => $v) {
            $text = str_replace('{'.$k.'}', (string) $v, $text);
        }

        return $text;
    }

    /** Queue a message to a member for after the current transaction commits. @param array<string, string|int> $vars */
    public static function toMember(int $companyId, int $memberId, string $key, array $vars): void
    {
        DB::afterCommit(fn () => self::deliver($companyId, $memberId, $key, $vars));
    }

    /** @param  array<string, string|int>  $vars */
    private static function deliver(int $companyId, int $memberId, string $key, array $vars): void
    {
        $member = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->find($memberId);
        $settings = SaccoSetting::for($companyId);
        if ($member === null || $settings === null || $member->messages_opt_out_at !== null) {
            return;
        }
        if (in_array($key, self::REMINDERS, true) && $member->consent_messages_at === null) {
            return;
        }
        $channels = match ($settings->receipts_channel) {
            'sms' => ['sms'],
            'whatsapp' => ['whatsapp', 'sms'],
            default => [],
        };
        $to = (string) ($member->phone_e164 ?: '');
        if ($channels === [] || $to === '') {
            return;
        }
        app(Messenger::class)->send($to, self::text($key, ['sacco' => $settings->short_name] + $vars), $channels,
            ['company_id' => $companyId, 'purpose' => 'sacco_'.$key, 'sacco_member_id' => $memberId]);
    }
}
