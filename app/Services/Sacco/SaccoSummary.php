<?php

namespace App\Services\Sacco;

use App\Models\SaccoApproval;
use App\Models\SaccoMember;
use App\Models\SaccoSetting;
use App\Models\SaccoTransaction;
use App\Services\Sacco\Ledger\OpeningBalances;
use App\Support\LocalDate;
use Illuminate\Support\Facades\DB;

/**
 * The figures on the SACCO's home (04 §13.2 `GET sacco/overview`, the portal's Overview, the app's home): members by
 * status, savings and share capital (from the control accounts), the cash position (cash, bank, mobile money), what
 * waits for approval, held receipts, today's takings, and how far the opening balances are. Later phases add theirs
 * (P2: the loan portfolio and PAR30) with extend().
 */
final class SaccoSummary
{
    /** @var array<string, callable(int): array<string, mixed>> */
    private static array $extra = [];

    /** @param callable(int): array<string, mixed> $figures */
    public static function extend(string $key, callable $figures): void
    {
        self::$extra[$key] = $figures;
    }

    /** @return array<string, mixed> */
    public function for(int $companyId): array
    {
        $settings = SaccoSetting::for($companyId);
        $balances = DB::table('sacco_account_balances as b')->join('sacco_ledger_accounts as a', 'a.id', '=', 'b.ledger_account_id')->where('b.company_id', $companyId)
            ->get(['a.system_key', 'a.control_of', 'b.balance_minor']);
        $sumWhere = fn (callable $f) => (int) $balances->filter($f)->sum('balance_minor');
        $today = LocalDate::today($companyId)->toDateString();
        $out = [
            'currency' => $settings?->currency,
            'mode' => $settings?->mode,
            'name' => $settings?->short_name,
            'members' => SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->whereNull('merged_into_id')
                ->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'savings_minor' => $sumWhere(fn ($r) => $r->control_of === 'savings'),
            'share_capital_minor' => $sumWhere(fn ($r) => $r->control_of === 'shares'),
            'cash_minor' => $sumWhere(fn ($r) => in_array($r->system_key, ['cash.office', 'cash.box', 'cash.petty'], true)),
            'bank_minor' => $sumWhere(fn ($r) => $r->system_key === 'bank.main'),
            'momo_minor' => $sumWhere(fn ($r) => in_array($r->system_key, ['momo.float', 'momo.clearing'], true)),
            'unallocated_minor' => $sumWhere(fn ($r) => $r->system_key === 'suspense.receipts'),
            'pending_approvals' => SaccoApproval::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('status', ['pending', 'approved'])->where('expires_at', '>=', now())->count(),
            'held_receipts' => SaccoTransaction::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'held')->count(),
            'today' => [
                'deposits_minor' => (int) SaccoTransaction::withoutGlobalScopes()->where('company_id', $companyId)->where('kind', 'deposit')->where('status', 'posted')->where('value_date', $today)->sum('amount_minor'),
                'withdrawals_minor' => (int) SaccoTransaction::withoutGlobalScopes()->where('company_id', $companyId)->where('kind', 'withdrawal')->where('status', 'posted')->where('value_date', $today)->sum('amount_minor'),
                'receipts' => SaccoTransaction::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'posted')->where('value_date', $today)->count(),
            ],
            'opening' => $settings === null ? null : OpeningBalances::status($companyId),
        ];
        foreach (self::$extra as $key => $figures) {
            $out[$key] = $figures($companyId);
        }

        return $out;
    }
}
