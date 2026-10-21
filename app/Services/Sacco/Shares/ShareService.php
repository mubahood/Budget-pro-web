<?php

namespace App\Services\Sacco\Shares;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoMember;
use App\Models\SaccoSetting;
use App\Models\SaccoShareAccount;
use App\Models\SaccoTransaction;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\PostingRules;
use App\Services\Sacco\Ledger\SubRef;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Shop\NumberSequencer;
use App\Support\LocalDate;
use App\Support\Rules\SaccoRules;
use App\Support\Sacco\Amount;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;

/**
 * Members' shares (docs/akiba/04-architecture.md §3.3, §4, P1.11): buying shares and paying the entrance fee (money
 * documents through SaccoTransactions, cash or bank), and moving shares between members (always approved).
 *
 *   - A share costs the SACCO's par value; an amount that is not a whole number of shares is refused online
 *     (`not_whole_shares`) and held offline (AK-24).
 *   - No member holds more than the SACCO's maximum (one third by default, Tier 4 s.48), checked under the ledger
 *     lock, so two purchases racing at the cap cannot both pass (`share_cap_exceeded`). With fewer than three
 *     shareholders the rule is arithmetically impossible, so it applies from the third (AK-64).
 *   - Shares are capital: there is no withdrawal of shares in office mode (no route, no method). A member who leaves
 *     is paid through the exit settlement (P2) or sells to another member (transfer).
 *   - Activation (applicant → active, office mode) needs the entrance fee paid and the minimum shares held; more than
 *     one nominee needs more than one share (Regs 1992 reg. 10).
 */
final class ShareService
{
    /** The cap applies once this many members hold shares. */
    public const CAP_MIN_HOLDERS = 3;

    /**
     * Buy shares: `amount_minor` (what the phone sends) or `shares_count` (the counter screen), cash or bank.
     *
     * @param  array<string, mixed>  $data  uuid?, member_id, amount_minor | shares_count, channel?, value_date?, reference?, narration?
     * @param  array<string, mixed>  $opts  SaccoTransactions::record options (entry_date for history)
     * @return array{transaction: SaccoTransaction, replayed: bool}
     */
    public function purchase(Company $company, array $data, ?User $by, array $opts = []): array
    {
        if (! isset($data['amount_minor']) && isset($data['shares_count'])) {
            $settings = SaccoSetting::for((int) $company->id) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
            if (! is_int($data['shares_count']) || $data['shares_count'] < 1) {
                throw BusinessRuleException::make('invalid_transaction', 'shares_count must be a whole number above zero.');
            }
            $data['amount_minor'] = $data['shares_count'] * (int) $settings->share_par_minor;
        }
        unset($data['shares_count']);

        return app(SaccoTransactions::class)->record($company, ['kind' => 'share_purchase'] + $data, $by, $opts);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $opts
     * @return array{transaction: SaccoTransaction, replayed: bool}
     */
    public function payEntranceFee(Company $company, array $data, ?User $by, array $opts = []): array
    {
        if (! isset($data['amount_minor'])) {
            $data['amount_minor'] = (int) (SaccoSetting::for((int) $company->id)?->entrance_fee_minor ?? 0);
        }

        return app(SaccoTransactions::class)->record($company, ['kind' => 'entrance_fee'] + $data, $by, $opts);
    }

    /** Ask for shares to move from one member to another (maker sacco_teller, checker sacco_approve). */
    public function transfer(Company $company, int $fromMemberId, int $toMemberId, int $shares, string $reason, User $by, ?string $uuid = null): SaccoApproval
    {
        return app(SaccoApprovals::class)->request($company, 'share_transfer', ['from_member_id' => $fromMemberId, 'to_member_id' => $toMemberId,
            'shares_count' => $shares, 'reason' => $reason, 'uuid' => $uuid], $by, $uuid);
    }

    /** A member's share account, opened at the first purchase (call under the ledger head lock). */
    public function account(int $companyId, int $memberId, bool $lock = true): SaccoShareAccount
    {
        $q = SaccoShareAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('member_id', $memberId);
        $account = ($lock ? $q->lockForUpdate() : $q)->first();
        if ($account !== null) {
            return $account;
        }

        return SaccoShareAccount::create(['company_id' => $companyId, 'member_id' => $memberId, 'account_no' => NumberSequencer::next($companyId, 'shr'),
            'ledger_account_id' => ChartOfAccounts::id($companyId, 'shares.capital'), 'opened_on' => LocalDate::today($companyId)->toDateString(), 'status' => 'active']);
    }

    // ---------------------------------------------------------------- recorders (SaccoTransactions::recorder)

    /** @param array<string, mixed> $d @param array<string, mixed> $opts */
    public function recordPurchase(Company $company, array $d, ?User $by, array $opts, SaccoSetting $settings): SaccoTransaction
    {
        $cid = (int) $company->id;
        $tx = app(SaccoTransactions::class);
        if (empty($d['member_id']) && ! empty($d['share_account_id'])) {
            $d['member_id'] = SaccoShareAccount::withoutGlobalScopes()->where('company_id', $cid)->whereKey((int) $d['share_account_id'])->value('member_id');
        }
        $member = $tx->payingMember($cid, (int) ($d['member_id'] ?? 0));
        $account = $this->account($cid, (int) $member->id);
        if (! empty($d['share_account_id']) && (int) $d['share_account_id'] !== (int) $account->id) {
            throw BusinessRuleException::make('not_own_account', 'That share account belongs to another member.');
        }
        $shares = self::wholeShares($settings, (int) $d['amount_minor']);
        self::assertCap($cid, $settings, $account, $shares);
        $d = ['member_id' => $member->id, 'share_account_id' => $account->id, 'shares_count' => $shares, 'channel' => $d['channel'] ?? 'cash'] + $d;
        $doc = $tx->document($cid, $d, $by, $opts, 'posted');
        $draft = (new PostingRules($cid, $settings->mode))->sharePurchase('txn:'.$doc->uuid, self::ref($account), $tx->amount($cid, (int) $doc->amount_minor),
            PostingRules::moneyChannel((string) $doc->channel));
        self::addShares((int) $account->id, $shares);

        return $tx->post($doc, $draft, $by?->id, $opts);
    }

    /** @param array<string, mixed> $d @param array<string, mixed> $opts */
    public function recordEntranceFee(Company $company, array $d, ?User $by, array $opts, SaccoSetting $settings): SaccoTransaction
    {
        $cid = (int) $company->id;
        $tx = app(SaccoTransactions::class);
        $member = $tx->payingMember($cid, (int) ($d['member_id'] ?? 0));
        $fee = (int) $settings->entrance_fee_minor;
        if ($fee === 0) {
            throw BusinessRuleException::make('no_entrance_fee', 'This SACCO charges no entrance fee.');
        }
        if (self::entranceFeePaid($cid, (int) $member->id)) {
            throw BusinessRuleException::make('entrance_fee_paid', "{$member->full_name} has already paid the entrance fee.");
        }
        if ((int) $d['amount_minor'] !== $fee) {
            throw BusinessRuleException::make('wrong_entrance_fee', 'The entrance fee is '.Amount::of($fee, $settings->currency)->toMajorString().'.', ['entrance_fee_minor' => $fee]);
        }
        $d = ['member_id' => $member->id, 'channel' => $d['channel'] ?? 'cash'] + $d;
        $doc = $tx->document($cid, $d, $by, $opts, 'posted');
        $draft = (new PostingRules($cid, $settings->mode))->entranceFee('txn:'.$doc->uuid, (int) $member->id, $tx->amount($cid, $fee), PostingRules::moneyChannel((string) $doc->channel));

        return $tx->post($doc, $draft, $by?->id, $opts);
    }

    // ---------------------------------------------------------------- transfer (ShareTransferExecutor)

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, subject_type: ?string, subject_id: ?int, amount_minor: ?int}
     */
    public static function prepareTransfer(int $companyId, array $payload): array
    {
        $n = $payload['shares_count'] ?? null;
        if (! is_int($n) || $n < 1) {
            throw BusinessRuleException::make('invalid_request', 'shares_count must be a whole number above zero.');
        }
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            throw BusinessRuleException::make('reason_required', 'Say why the shares move (a sale between members, an inheritance).');
        }
        $from = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->find((int) ($payload['from_member_id'] ?? 0));
        $to = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->find((int) ($payload['to_member_id'] ?? 0));
        if ($from === null || $to === null) {
            throw BusinessRuleException::make('member_not_found', 'Both members must be in this SACCO.');
        }
        if ((int) $from->id === (int) $to->id) {
            throw BusinessRuleException::make('same_account', 'Choose two different members.');
        }
        if (! in_array($to->status, ['applicant', 'active', 'dormant'], true)) {
            throw BusinessRuleException::make('member_not_active', "{$to->full_name} is {$to->status}.");
        }
        $held = (int) SaccoShareAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('member_id', $from->id)->value('shares_count');
        if ($held < $n) {
            throw BusinessRuleException::make('not_enough_shares', "{$from->full_name} holds {$held} shares.");
        }

        return ['payload' => ['from_member_id' => (int) $from->id, 'from_member_no' => $from->member_no, 'to_member_id' => (int) $to->id, 'to_member_no' => $to->member_no,
            'shares_count' => $n, 'reason' => mb_substr($reason, 0, 200), 'uuid' => isset($payload['uuid']) && is_string($payload['uuid']) ? strtolower($payload['uuid']) : null],
            'subject_type' => 'sacco_members', 'subject_id' => (int) $from->id, 'amount_minor' => null];
    }

    public function executeTransfer(SaccoApproval $approval, User $checker): SaccoTransaction
    {
        $cid = (int) $approval->company_id;
        $p = $approval->payload;
        $uuid = $p['uuid'] ?? null ?: SyncSequence::childUuid('shr', (string) $approval->uuid);
        $existing = SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first();
        if ($existing !== null) {
            return $existing;
        }
        $settings = SaccoSetting::for($cid) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
        SaccoTransactions::lockHead($cid);
        $fromAccount = $this->account($cid, (int) $p['from_member_id']);
        $toAccount = $this->account($cid, (int) $p['to_member_id']);
        [$first, $second] = $fromAccount->id < $toAccount->id ? [$fromAccount, $toAccount] : [$toAccount, $fromAccount];
        SaccoShareAccount::withoutGlobalScopes()->whereKey([$first->id, $second->id])->orderBy('id')->lockForUpdate()->get();
        $fromAccount->refresh();
        $toAccount->refresh();
        $n = (int) $p['shares_count'];
        $count = (int) $fromAccount->shares_count;
        if ($count < $n) {
            throw BusinessRuleException::make('not_enough_shares', "The member now holds {$count} shares.");
        }
        $balance = (int) $fromAccount->balance_minor;
        if ($n !== $count && $balance > intdiv(PHP_INT_MAX, $n)) {
            throw BusinessRuleException::make('amount_too_large', 'That transfer is too large to compute exactly.');
        }
        $amount = $n === $count ? $balance : intdiv($balance * $n + intdiv($count, 2), $count); // the shares' share of what was paid for them, half up
        self::assertCap($cid, $settings, $toAccount, $n, false);
        $tx = app(SaccoTransactions::class);
        $doc = $tx->document($cid, ['uuid' => $uuid, 'kind' => 'share_transfer', 'channel' => 'internal', 'member_id' => (int) $p['from_member_id'],
            'share_account_id' => (int) $fromAccount->id, 'counter_account_id' => (int) $toAccount->id, 'shares_count' => $n, 'amount_minor' => $amount,
            'value_date' => LocalDate::today($cid)->toDateString(), 'narration' => mb_substr('To '.($p['to_member_no'] ?? '').': '.$p['reason'], 0, 255)],
            $approval->requested_by_id === null ? null : User::withoutGlobalScopes()->find($approval->requested_by_id), [], 'posted');
        $draft = (new PostingRules($cid, $settings->mode))->shareTransfer('txn:'.$doc->uuid, self::ref($fromAccount), self::ref($toAccount), $tx->amount($cid, $amount))
            ->approval((int) $approval->id);
        self::addShares((int) $fromAccount->id, -$n);
        self::addShares((int) $toAccount->id, $n);
        $doc->forceFill(['approval_id' => $approval->id])->save();

        return $tx->post($doc, $draft, $approval->requested_by_id === null ? null : (int) $approval->requested_by_id, []);
    }

    // ---------------------------------------------------------------- rules other parts call

    /** Shares an amount buys at par, or `not_whole_shares`. */
    public static function wholeShares(SaccoSetting $settings, int $amountMinor): int
    {
        $par = (int) $settings->share_par_minor;
        if ($par <= 0 || $amountMinor % $par !== 0) {
            throw BusinessRuleException::make('not_whole_shares', 'A share costs '.Amount::of($par, $settings->currency)->toMajorString().': pay a whole number of shares.', ['share_par_minor' => $par]);
        }

        return intdiv($amountMinor, $par);
    }

    /**
     * No member above the SACCO's maximum holding (one third by default) once three members hold shares. Read with
     * locking reads; call under the ledger head lock.
     */
    public static function assertCap(int $companyId, SaccoSetting $settings, SaccoShareAccount $account, int $adding, bool $newShares = true): void
    {
        $rows = DB::table('sacco_share_accounts')->where('company_id', $companyId)->where('shares_count', '>', 0)->sharedLock()->get(['id', 'shares_count']);
        $total = (int) $rows->sum('shares_count') + ($newShares ? $adding : 0);
        $holders = $rows->count() + ($rows->contains('id', $account->id) ? 0 : 1);
        $mine = (int) ($rows->firstWhere('id', $account->id)->shares_count ?? 0) + $adding;
        $cap = (int) $settings->max_holding_bp;
        if (! SaccoRules::strict($companyId)) {
            return; // Tier 4 s.48 binds a regulated SACCO only (AK-69)
        }
        if ($holders >= self::CAP_MIN_HOLDERS && $cap > 0 && $total > 0 && intdiv($mine * 10000, $total) > $cap) { // the holding in whole basis points: exactly a third is 3333
            throw BusinessRuleException::make('share_cap_exceeded', 'No member may hold more than '.rtrim(rtrim(number_format($cap / 100, 2), '0'), '.').' % of the shares.',
                ['max_holding_bp' => $cap, 'max_shares' => intdiv($cap * $total, 10000)]);
        }
    }

    public static function entranceFeePaid(int $companyId, int $memberId): bool
    {
        return SaccoTransaction::withoutGlobalScopes()->where('company_id', $companyId)->where('member_id', $memberId)->where('kind', 'entrance_fee')->where('status', 'posted')
            ->whereNotIn('id', SaccoTransaction::withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('reverses_id')->select('reverses_id'))->exists();
    }

    public static function sharesOf(int $companyId, int $memberId): int
    {
        return (int) SaccoShareAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('member_id', $memberId)->value('shares_count');
    }

    public static function ref(SaccoShareAccount $a): SubRef
    {
        return new SubRef('shares', (int) $a->id, (int) $a->ledger_account_id, (int) $a->member_id);
    }

    /** The share count beside the money (the balance moves through the books; the count here, in the same transaction). */
    public static function addShares(int $accountId, int $delta): void
    {
        $moved = DB::table('sacco_share_accounts')->where('id', $accountId)->whereRaw('shares_count + (?) >= 0', [$delta])
            ->update(['shares_count' => DB::raw('shares_count + ('.$delta.')'), 'server_seq' => SyncSequence::next(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        if ($moved !== 1) {
            throw BusinessRuleException::make('not_enough_shares', 'That would leave a member with fewer than no shares.');
        }
    }

    /** Wire shares into the other parts (SaccoModule::boot). */
    public static function boot(): void
    {
        $shares = new self();
        SaccoTransactions::recorder('share_purchase', fn (Company $c, array $d, ?User $by, array $o, SaccoSetting $s) => $shares->recordPurchase($c, $d, $by, $o, $s));
        SaccoTransactions::recorder('entrance_fee', fn (Company $c, array $d, ?User $by, array $o, SaccoSetting $s) => $shares->recordEntranceFee($c, $d, $by, $o, $s));
        SaccoTransactions::onReverse('share_purchase', 'shares', fn (SaccoTransaction $orig) => self::addShares((int) $orig->share_account_id, -(int) $orig->shares_count));
        SaccoTransactions::onReverse('share_transfer', 'shares', function (SaccoTransaction $orig) {
            self::addShares((int) $orig->counter_account_id, -(int) $orig->shares_count);
            self::addShares((int) $orig->share_account_id, (int) $orig->shares_count);
        });
        // A held receipt allocated to a share account buys whole shares, within the cap.
        SaccoTransactions::onAllocate('shares', function (int $companyId, SubRef $target, int $amount) {
            $settings = SaccoSetting::for($companyId) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
            $n = self::wholeShares($settings, $amount);
            $account = SaccoShareAccount::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->findOrFail($target->id);
            self::assertCap($companyId, $settings, $account, $n);
            self::addShares((int) $account->id, $n);
        });
        // Offline: a purchase names the member's share account (or none); its allocation target when it must be held.
        SaccoTransactions::offlineCheck('share_purchase', function (int $companyId, array $record, ?SaccoMember $member) {
            $account = $member === null ? null : SaccoShareAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('member_id', $member->id)->first();
            if (! empty($record['share_account_id']) && ($account === null || (int) $account->id !== (int) $record['share_account_id'])) {
                return ['unknown_account', null];
            }

            return [null, $account === null ? null : self::ref($account)];
        });
        \App\Services\Sacco\Members\MemberService::activationCheck('shares', function (Company $company, SaccoMember $member) {
            $settings = SaccoSetting::for((int) $company->id);
            if ($settings === null || $settings->isGroup()) {
                return;
            }
            if ((int) $settings->entrance_fee_minor > 0 && ! self::entranceFeePaid((int) $company->id, (int) $member->id)) {
                throw BusinessRuleException::make('entrance_fee_required', 'The member pays the entrance fee before joining.');
            }
            $held = self::sharesOf((int) $company->id, (int) $member->id);
            if ($held < (int) $settings->min_shares) {
                throw BusinessRuleException::make('min_shares_required', "A member holds at least {$settings->min_shares} shares before joining (now {$held}).",
                    ['min_shares' => (int) $settings->min_shares, 'shares' => $held]);
            }
        });
        \App\Services\Sacco\Members\MemberService::nominationCheck('shares', function (Company $company, SaccoMember $member, array $nominees) {
            // Regs 1992 reg. 10, for a regulated SACCO only (AK-69).
            if (SaccoRules::strict($company) && count($nominees) > 1 && self::sharesOf((int) $company->id, (int) $member->id) <= 1) {
                throw BusinessRuleException::make('nomination_needs_shares', 'More than one nominee needs more than one share.');
            }
        });
    }
}
