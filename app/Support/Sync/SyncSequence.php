<?php

namespace App\Support\Sync;

use Illuminate\Support\Facades\DB;

/**
 * One global, monotonically increasing sequence (plan §B3). Every server write
 * to a synced row takes the next value; devices pull by `server_seq`, never
 * by timestamp. A single atomic UPDATE keeps it correct under concurrency.
 */
class SyncSequence
{
    public static function next(): int
    {
        DB::table('sync_sequence')->where('id', 1)->update(['last_seq' => DB::raw('LAST_INSERT_ID(last_seq + 1)')]);
        $value = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS v')->v;
        if ($value <= 0) {
            // Fresh schema without the seed row (tests on a bare database).
            DB::table('sync_sequence')->insertOrIgnore(['id' => 1, 'last_seq' => 0]);
            DB::table('sync_sequence')->where('id', 1)->update(['last_seq' => DB::raw('LAST_INSERT_ID(last_seq + 1)')]);
            $value = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS v')->v;
        }

        return $value;
    }

    public static function current(): int
    {
        return (int) (DB::table('sync_sequence')->where('id', 1)->value('last_seq') ?? 0);
    }

    /** Deterministic child uuid (e.g. the n-th payment created from one client payment) that still fits CHAR(36). */
    public static function childUuid(string $parent, string $suffix): string
    {
        $h = md5($parent.'#'.$suffix);

        return substr($h, 0, 8).'-'.substr($h, 8, 4).'-5'.substr($h, 13, 3).'-a'.substr($h, 17, 3).'-'.substr($h, 20, 12);
    }

    public static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
