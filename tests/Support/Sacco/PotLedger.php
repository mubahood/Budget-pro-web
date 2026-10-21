<?php

namespace Tests\Support\Sacco;

use App\Services\Sacco\Ledger\SubLedger;
use Illuminate\Support\Facades\DB;

/**
 * A stand-in member sub-ledger for the ledger's own tests: "pots" under a control account, in a temporary table,
 * so the posting service, its projector and the verifier's sub-ledger checks are proved before the real savings,
 * shares and loans sub-ledgers exist (they register themselves the same way).
 */
final class PotLedger implements SubLedger
{
    public const TABLE = 'sacco_test_pots';

    public function __construct(private readonly string $type = 'savings')
    {
    }

    public static function createTable(): void
    {
        DB::statement('CREATE TEMPORARY TABLE IF NOT EXISTS '.self::TABLE.' (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
            ledger_account_id BIGINT UNSIGNED NOT NULL, member_id BIGINT UNSIGNED NULL, balance_minor BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    }

    public static function open(int $companyId, int $accountId, ?int $memberId = null): int
    {
        return (int) DB::table(self::TABLE)->insertGetId(['company_id' => $companyId, 'ledger_account_id' => $accountId, 'member_id' => $memberId]);
    }

    public static function balance(int $id): int
    {
        return (int) DB::table(self::TABLE)->where('id', $id)->value('balance_minor');
    }

    public function type(): string
    {
        return $this->type;
    }

    public function lock(int $companyId, array $ids): array
    {
        $out = [];
        foreach (DB::table(self::TABLE)->where('company_id', $companyId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $r) {
            $out[(int) $r->id] = ['accounts' => [(int) $r->ledger_account_id], 'member_id' => $r->member_id === null ? null : (int) $r->member_id];
        }

        return $out;
    }

    public function project(int $companyId, int $id, int $accountId, int $signedMinor): void
    {
        DB::table(self::TABLE)->where('company_id', $companyId)->where('id', $id)->update(['balance_minor' => DB::raw('balance_minor + ('.$signedMinor.')')]);
    }

    public function balances(int $companyId): array
    {
        return DB::table(self::TABLE)->where('company_id', $companyId)->get()
            ->map(fn ($r) => ['sub_id' => (int) $r->id, 'ledger_account_id' => (int) $r->ledger_account_id, 'balance_minor' => (int) $r->balance_minor])->all();
    }
}
