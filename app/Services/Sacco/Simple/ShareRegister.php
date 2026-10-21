<?php

namespace App\Services\Sacco\Simple;

use App\Models\SaccoMember;
use App\Models\SaccoShareAccount;
use App\Services\Sacco\Loans\LoanBooks;

/** Flow 1: every member's shares and their value, and the SACCO's total, at the share price set in Settings. */
final class ShareRegister
{
    /** @return array{share_price_minor: int, currency: string, total_shares: int, total_value_minor: int, members_holding: int, rows: list<array<string, mixed>>} */
    public static function for(int $companyId, ?int $memberId = null): array
    {
        $settings = LoanBooks::settings($companyId);
        $accounts = SaccoShareAccount::withoutGlobalScopes()->where('company_id', $companyId)->when($memberId !== null, fn ($q) => $q->where('member_id', $memberId))
            ->get()->keyBy('member_id');
        $members = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)
            ->when($memberId !== null, fn ($q) => $q->whereKey($memberId), fn ($q) => $q->whereIn('status', ['applicant', 'active', 'dormant', 'suspended']))
            ->orderBy('member_no')->get();
        $rows = [];
        foreach ($members as $m) {
            $a = $accounts[$m->id] ?? null;
            $rows[] = ['member_id' => (int) $m->id, 'member_no' => $m->member_no, 'name' => $m->full_name, 'share_account_id' => $a?->id === null ? null : (int) $a->id,
                'shares' => (int) ($a->shares_count ?? 0), 'value_minor' => (int) ($a->balance_minor ?? 0)];
        }
        $all = $memberId === null ? $accounts : SaccoShareAccount::withoutGlobalScopes()->where('company_id', $companyId)->get();

        return ['share_price_minor' => (int) $settings->share_par_minor, 'currency' => (string) $settings->currency,
            'total_shares' => (int) $all->sum('shares_count'), 'total_value_minor' => (int) $all->sum('balance_minor'),
            'members_holding' => $all->where('shares_count', '>', 0)->count(), 'rows' => $rows];
    }
}
