<?php

namespace App\Services\Sacco\Shares;

use App\Exceptions\BusinessRuleException;
use App\Services\Sacco\Ledger\SubLedger;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;

/** The shares sub-ledger (ADR 0009 §7): each member's share account under 3100; never below zero. */
final class SharesLedger implements SubLedger
{
    public const TABLE = 'sacco_share_accounts';

    public function type(): string
    {
        return 'shares';
    }

    public function lock(int $companyId, array $ids): array
    {
        $out = [];
        foreach (DB::table(self::TABLE)->where('company_id', $companyId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'ledger_account_id', 'member_id']) as $r) {
            $out[(int) $r->id] = ['accounts' => [(int) $r->ledger_account_id], 'member_id' => (int) $r->member_id];
        }

        return $out;
    }

    public function project(int $companyId, int $id, int $accountId, int $signedMinor): void
    {
        $moved = DB::table(self::TABLE)->where('company_id', $companyId)->where('id', $id)->where('ledger_account_id', $accountId)
            ->whereRaw('balance_minor + (?) >= 0', [$signedMinor])
            ->update(['balance_minor' => DB::raw('balance_minor + ('.$signedMinor.')'), 'server_seq' => SyncSequence::next(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        if ($moved !== 1) {
            throw BusinessRuleException::make('insufficient_balance', 'That would take a share account below zero.', ['share_account_id' => $id]);
        }
    }

    public function balances(int $companyId): array
    {
        return DB::table(self::TABLE)->where('company_id', $companyId)->get(['id', 'ledger_account_id', 'balance_minor'])
            ->map(fn ($r) => ['sub_id' => (int) $r->id, 'ledger_account_id' => (int) $r->ledger_account_id, 'balance_minor' => (int) $r->balance_minor])->all();
    }
}
