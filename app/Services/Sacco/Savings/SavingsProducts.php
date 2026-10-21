<?php

namespace App\Services\Sacco\Savings;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\SaccoSavingsProductVersion;
use App\Models\SaccoSetting;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoAudit;
use App\Support\LocalDate;
use App\Support\Rules\SaccoRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A SACCO's savings products (docs/akiba/04-architecture.md §3.3, §7): create, rename or switch off, and change
 * terms. Terms (rates, fees, minimums, notice, withdrawability) never change in place: a change is an approved
 * `product_terms_change` request that writes a new version, effective on its date. A rate cut takes effect at least
 * 30 days after it is approved, so members are told first (SI 51 reg. 33); the version waits until then and
 * applyDue() (daily, `sacco:savings-terms`) puts it in force.
 *
 * Every product posts to its own control account (control_of = savings): 2110 voluntary, 2120 compulsory, 2130 fixed
 * by default; products of a kind may share one.
 */
final class SavingsProducts
{
    /** Days between a rate cut's approval and the day it takes effect (04 §7 `product_terms_change`). */
    public const RATE_CUT_NOTICE_DAYS = 30;

    /** The control account each kind posts to unless the SACCO picks another savings control account. */
    public const DEFAULT_ACCOUNT = ['voluntary' => 'savings.voluntary', 'compulsory' => 'savings.compulsory', 'fixed' => 'savings.fixed', 'group' => 'savings.voluntary'];

    /** The longest notice a product may ask (an approval waits at most 7 days, SaccoApprovals::EXPIRY_DAYS: AK-63). */
    public const MAX_NOTICE_DAYS = 7;

    /** @return array<string, array<int, mixed>> */
    public static function termRules(): array
    {
        $s = 'sometimes';

        return [
            'withdrawable' => [$s, 'boolean'],
            'min_opening_minor' => SaccoRules::minor(false),
            'min_balance_minor' => SaccoRules::minor(false),
            'interest_rate_bp' => SaccoRules::bp(10000),
            'interest_basis' => [$s, Rule::in(SaccoSavingsProduct::INTEREST_BASES)],
            'interest_posting' => [$s, Rule::in(SaccoSavingsProduct::INTEREST_POSTINGS)],
            'term_months' => [$s, 'nullable', 'integer', 'between:1,120'],
            'early_withdrawal_penalty_bp' => SaccoRules::bp(10000),
            'withdrawal_fee_minor' => SaccoRules::minor(false),
            'max_withdrawals_per_month' => [$s, 'nullable', 'integer', 'between:1,100'],
            'notice_days' => [$s, 'integer', 'between:0,'.self::MAX_NOTICE_DAYS],
            'disclosure_text' => [$s, 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId): array
    {
        return [
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Z0-9_-]+$/i', Rule::unique('sacco_savings_products', 'code')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(SaccoSavingsProduct::KINDS)],
            'ledger_account_id' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ] + self::termRules();
    }

    /** @param  array<string, mixed>  $data */
    public function create(Company $company, array $data, ?User $by): SaccoSavingsProduct
    {
        $cid = (int) $company->id;
        if ($by !== null) {
            SaccoAccess::authorize($by, 'sacco_settings');
        }
        $settings = SaccoSetting::for($cid) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
        $v = validator($data, self::rules($cid));
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_product', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $d = $v->validated();
        $d['code'] = strtoupper((string) $d['code']);
        $account = $this->controlAccount($cid, $d['kind'], $d['ledger_account_id'] ?? null);
        $d = $this->normalise($d, $settings);

        return DB::transaction(function () use ($cid, $d, $account, $by) {
            $product = SaccoSavingsProduct::create(array_merge(self::defaults(), $d, ['company_id' => $cid, 'ledger_account_id' => $account->id,
                'interest_expense_account_id' => SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('system_key', 'exp.savings_interest')->value('id'),
                'terms_version' => 1, 'created_by_id' => $by?->id, 'updated_by_id' => $by?->id]));
            SaccoSavingsProductVersion::create(['company_id' => $cid, 'product_id' => $product->id, 'terms_version' => 1,
                'effective_from' => LocalDate::today($cid)->toDateString(), 'terms' => $product->fresh()->terms(), 'created_by_id' => $by?->id]);

            return $product->fresh();
        });
    }

    /** Rename, switch on or off. Terms go through requestTermsChange(). @param array<string, mixed> $data */
    public function update(Company $company, int $productId, array $data, User $by): SaccoSavingsProduct
    {
        SaccoAccess::authorize($by, 'sacco_settings');
        if (array_intersect_key($data, array_flip(SaccoSavingsProduct::TERMS)) !== []) {
            throw BusinessRuleException::make('terms_need_approval', 'A product\'s terms change through an approved request: ask for a terms change instead.');
        }
        $v = validator($data, ['name' => ['sometimes', 'required', 'string', 'max:80'], 'is_active' => ['sometimes', 'boolean']]);
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_product', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }

        return DB::transaction(function () use ($company, $productId, $v, $by) {
            $product = $this->find((int) $company->id, $productId, true);
            $product->fill($v->validated() + ['updated_by_id' => $by->id])->save();

            return $product;
        });
    }

    /**
     * Ask for new terms (maker: sacco_settings; checker: sacco_approve). The payload shows the old terms beside the
     * new ones, so the checker sees what changes.
     *
     * @param  array<string, mixed>  $terms
     */
    public function requestTermsChange(Company $company, int $productId, array $terms, User $by, ?string $effectiveFrom = null, ?string $uuid = null): \App\Models\SaccoApproval
    {
        return app(SaccoApprovals::class)->request($company, 'product_terms_change',
            ['product_id' => $productId, 'terms' => $terms, 'effective_from' => $effectiveFrom], $by, $uuid);
    }

    /**
     * Check a terms change and freeze it (ProductTermsChangeExecutor::prepare): the full new terms, the old ones,
     * and the day they take effect (a rate cut: 30 days out at the earliest).
     *
     * @param  array<string, mixed>  $payload
     * @return array{product: SaccoSavingsProduct, old: array<string, mixed>, new: array<string, mixed>, effective_from: string}
     */
    public function prepareTermsChange(int $companyId, array $payload): array
    {
        $product = $this->find($companyId, (int) ($payload['product_id'] ?? 0));
        $terms = is_array($payload['terms'] ?? null) ? $payload['terms'] : [];
        $unknown = array_diff(array_keys($terms), SaccoSavingsProduct::TERMS);
        if ($terms === [] || $unknown !== []) {
            throw BusinessRuleException::make('invalid_terms', $terms === [] ? 'Name the terms that change.' : 'These are not product terms: '.implode(', ', $unknown).'.');
        }
        $v = validator($terms, self::termRules());
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_terms', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $settings = SaccoSetting::for($companyId) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
        $old = $product->terms();
        $new = $this->normalise(array_merge($old, $v->validated()), $settings, $product->kind);
        $new = array_intersect_key($new, array_flip(SaccoSavingsProduct::TERMS));
        $today = LocalDate::today($companyId);
        $earliest = (int) $new['interest_rate_bp'] < (int) $old['interest_rate_bp'] ? $today->copy()->addDays(self::RATE_CUT_NOTICE_DAYS) : $today;
        $from = isset($payload['effective_from']) && $payload['effective_from'] !== null ? Carbon::parse((string) $payload['effective_from'])->startOfDay() : $earliest;
        if ($from->lt($earliest)) {
            throw BusinessRuleException::make('notice_too_short', 'A lower savings rate takes effect at least '.self::RATE_CUT_NOTICE_DAYS.' days after members are told: choose '.$earliest->toDateString().' or later.',
                ['earliest' => $earliest->toDateString()]);
        }
        if ($new == $old) {
            throw BusinessRuleException::make('invalid_terms', 'These are the terms the product already has.');
        }

        return ['product' => $product, 'old' => $old, 'new' => $new, 'effective_from' => $from->toDateString()];
    }

    /** Write the approved version; in force now if its day has come, otherwise on that day (applyDue). */
    public function applyTerms(int $companyId, int $productId, array $terms, string $effectiveFrom, ?int $approvalId, ?int $userId): SaccoSavingsProductVersion
    {
        return DB::transaction(function () use ($companyId, $productId, $terms, $effectiveFrom, $approvalId, $userId) {
            $product = $this->find($companyId, $productId, true);
            $next = (int) SaccoSavingsProductVersion::withoutGlobalScopes()->where('product_id', $product->id)->max('terms_version') + 1;
            $version = SaccoSavingsProductVersion::create(['company_id' => $companyId, 'product_id' => $product->id, 'terms_version' => $next, 'effective_from' => $effectiveFrom,
                'terms' => $terms, 'approval_id' => $approvalId, 'created_by_id' => $userId]);
            if (Carbon::parse($effectiveFrom)->lte(LocalDate::today($companyId))) {
                $this->putInForce($product, $version);
            }

            return $version;
        });
    }

    /** Put in force every approved version whose day has come (daily). @return int how many */
    public function applyDue(?int $companyId = null): int
    {
        $n = 0;
        SaccoSavingsProduct::withoutGlobalScopes()->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->orderBy('id')
            ->each(function (SaccoSavingsProduct $p) use (&$n) {
                $due = SaccoSavingsProductVersion::withoutGlobalScopes()->where('product_id', $p->id)->where('terms_version', '>', $p->terms_version)
                    ->where('effective_from', '<=', LocalDate::today((int) $p->company_id)->toDateString())->orderByDesc('terms_version')->first();
                if ($due !== null) {
                    DB::transaction(fn () => $this->putInForce($this->find((int) $p->company_id, (int) $p->id, true), $due));
                    $n++;
                }
            });

        return $n;
    }

    /**
     * The key facts a member is given before opening an account (SI 51 reg. 33), in minor units and basis points.
     *
     * @return array<string, mixed>
     */
    public static function disclosure(SaccoSavingsProduct $p): array
    {
        return ['code' => $p->code, 'name' => $p->name, 'kind' => $p->kind, 'withdrawable' => (bool) $p->withdrawable, 'interest_rate_bp' => (int) $p->interest_rate_bp,
            'interest_basis' => $p->interest_basis, 'interest_posting' => $p->interest_posting, 'min_opening_minor' => (int) $p->min_opening_minor,
            'min_balance_minor' => (int) $p->min_balance_minor, 'withdrawal_fee_minor' => (int) $p->withdrawal_fee_minor,
            'max_withdrawals_per_month' => $p->max_withdrawals_per_month, 'notice_days' => (int) $p->notice_days, 'term_months' => $p->term_months,
            'early_withdrawal_penalty_bp' => (int) $p->early_withdrawal_penalty_bp, 'terms_version' => (int) $p->terms_version, 'text' => (string) $p->disclosure_text];
    }

    /** A new SACCO's products (SaccoSetupService::extend): ordinary and compulsory savings in office mode, group savings in a group. */
    public function ensureDefaults(Company $company): void
    {
        $settings = SaccoSetting::for((int) $company->id);
        if ($settings === null) {
            return;
        }
        $rows = $settings->isGroup()
            ? [['code' => 'GRP', 'name' => 'Group savings', 'kind' => 'group']]
            : [['code' => 'ORD', 'name' => 'Ordinary savings', 'kind' => 'voluntary'],
                ['code' => 'CMP', 'name' => 'Compulsory savings', 'kind' => 'compulsory', 'withdrawable' => false]];
        foreach ($rows as $row) {
            if (! SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $company->id)->where('code', $row['code'])->exists()) {
                $this->create($company, $row, null);
            }
        }
    }

    public function find(int $companyId, int $productId, bool $lock = false): SaccoSavingsProduct
    {
        $q = SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $companyId);

        return ($lock ? $q->lockForUpdate() : $q)->find($productId)
            ?? throw BusinessRuleException::make('product_not_found', 'That savings product is not in this SACCO.');
    }

    private function putInForce(SaccoSavingsProduct $product, SaccoSavingsProductVersion $version): void
    {
        $product->fill(array_intersect_key($version->terms, array_flip(SaccoSavingsProduct::TERMS)) + ['terms_version' => $version->terms_version])->save();
        SaccoAudit::record('sacco.product.terms_in_force', $product, (int) $product->company_id, ['terms_version' => $version->terms_version, 'approval_id' => $version->approval_id]);
    }

    private function controlAccount(int $companyId, string $kind, ?int $accountId): SaccoLedgerAccount
    {
        $account = $accountId !== null
            ? SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->find($accountId)
            : SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('system_key', self::DEFAULT_ACCOUNT[$kind])->first();
        $account ??= ChartOfAccounts::account($companyId, 'savings.voluntary');
        if ($account->control_of !== 'savings' || ! $account->is_active || ! $account->is_postable) {
            throw BusinessRuleException::make('invalid_product', "{$account->code} {$account->name} is not a savings control account.");
        }

        return $account;
    }

    /**
     * The mode's and the kind's rules: compulsory savings are never withdrawable, a fixed deposit has a term, a group
     * charges no fees (its chart has no 4420: AK-58).
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function normalise(array $d, SaccoSetting $settings, ?string $kind = null): array
    {
        $kind ??= $d['kind'] ?? 'voluntary';
        if ($kind === 'compulsory') {
            $d['withdrawable'] = false;
        }
        if ($kind === 'fixed' && empty($d['term_months'])) {
            throw BusinessRuleException::make('invalid_product', 'A fixed deposit needs its term in months.');
        }
        if ($kind !== 'fixed') {
            $d['term_months'] = null;
            $d['early_withdrawal_penalty_bp'] = 0;
        }
        if ($settings->isGroup() && (int) ($d['withdrawal_fee_minor'] ?? 0) > 0) {
            throw BusinessRuleException::make('not_in_group_mode', 'A savings group charges no withdrawal fee; use a fine instead.');
        }
        if (($d['interest_basis'] ?? 'none') === 'none' && (int) ($d['interest_rate_bp'] ?? 0) > 0) {
            $d['interest_basis'] = 'min_monthly_balance';
        }

        return $d;
    }

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        return ['withdrawable' => true, 'min_opening_minor' => 0, 'min_balance_minor' => 0, 'interest_rate_bp' => 0, 'interest_basis' => 'none', 'interest_posting' => 'annually',
            'term_months' => null, 'early_withdrawal_penalty_bp' => 0, 'withdrawal_fee_minor' => 0, 'max_withdrawals_per_month' => null, 'notice_days' => 0, 'is_active' => true];
    }

    /** The accounts open on a product (for the notice of a terms change). @return list<int> member ids */
    public static function holders(int $companyId, int $productId): array
    {
        return SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('product_id', $productId)->where('status', '!=', 'closed')
            ->distinct()->pluck('member_id')->map(fn ($v) => (int) $v)->all();
    }
}
