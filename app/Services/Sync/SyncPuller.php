<?php

namespace App\Services\Sync;

use App\Exceptions\BusinessRuleException;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `GET /sync/pull`, `POST /sync/pull-many` and `POST /sync/bootstrap` (A.3/A.4): seq cursor, paging, never timestamps.
 *
 * Snapshot tables (SyncRegistry::KIND_SNAPSHOT: tax classes, prices, promotions, batches…) have no
 * server_seq: their rows are written by many services with plain queries. For them the cursor is a
 * fingerprint of the company's whole set (count + CRC of every column): when the device's cursor
 * differs, the whole set comes back with `snapshot: true` and the device replaces what it holds.
 */
class SyncPuller
{
    public const MAX_LIMIT = 1000;

    /** A snapshot table never sends more rows than this in one response (`truncated: true` when it had to cut). */
    public const MAX_SNAPSHOT_ROWS = 20000;

    /** Event history a bootstrap sends when the device does not say (0 = everything). */
    public const DEFAULT_HISTORY_DAYS = 90;

    public function __construct(private readonly SyncSerializer $serializer = new SyncSerializer())
    {
    }

    /** @return array{table: string, rows: array, next_seq: int, has_more: bool} */
    public function pull(int $companyId, string $table, int $sinceSeq, int $limit = 500): array
    {
        $config = SyncRegistry::get($table);
        if ($config === null) {
            throw BusinessRuleException::make('unknown_table', 'Unknown sync table "'.$table.'".', ['table' => $table]);
        }
        if ($config['kind'] === SyncRegistry::KIND_SNAPSHOT) {
            return ['table' => $table] + $this->snapshot($companyId, $table, $config, $sinceSeq);
        }
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $model = $config['model'];

        $query = $model::query()->withoutGlobalScopes()->where('server_seq', '>', $sinceSeq)->orderBy('server_seq')->limit($limit + 1);
        if ($config['kind'] !== SyncRegistry::KIND_REFERENCE) {
            $query->where('company_id', $companyId);
        }
        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $wire = [];
        $next = $sinceSeq;
        foreach ($rows as $row) {
            $wire[] = $this->serializer->row($table, $row);
            $next = max($next, (int) $row->server_seq);
        }

        return ['table' => $table, 'rows' => $this->enrich($companyId, $table, $wire), 'next_seq' => $next, 'has_more' => $hasMore];
    }

    /**
     * Many tables in one round trip: {table: since_seq}. An unknown table answers with an error entry
     * instead of failing the others.
     *
     * @param  array<string, int|string|null>  $cursors
     * @return array<string, array<string, mixed>>
     */
    public function pullMany(int $companyId, array $cursors, int $limit = 500): array
    {
        $out = [];
        foreach ($cursors as $table => $since) {
            try {
                $out[(string) $table] = $this->pull($companyId, (string) $table, (int) $since, $limit);
            } catch (BusinessRuleException $e) {
                $out[(string) $table] = ['table' => (string) $table, 'rows' => [], 'next_seq' => (int) $since, 'has_more' => false, 'error' => $e->errorCode()];
            }
        }

        return $out;
    }

    /**
     * First-install snapshot, paged per table by server_seq (keyset, never OFFSET on a fresh bootstrap):
     *  - `after` {table: last server_seq received} + `upto` (the `seq` of the first page) page precisely;
     *  - older apps send only `page`: the position of their next page is remembered per device ($scope);
     *  - event tables send the last `history_days` (default 90; 0 = all), plus whatever is still open
     *    (unpaid sales and their lines and payments, open shifts, draft stock counts); master data is always complete.
     *
     * @param  array{after?: array<string, int>, upto?: ?int, history_days?: ?int, scope?: ?string}  $opts
     */
    public function bootstrap(int $companyId, array $tables, int $page, int $pageSize = 500, array $opts = []): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min($pageSize, self::MAX_LIMIT));
        $after = is_array($opts['after'] ?? null) ? $opts['after'] : [];
        $scope = $opts['scope'] ?? null;
        $days = isset($opts['history_days']) && $opts['history_days'] !== null ? max(0, (int) $opts['history_days']) : self::DEFAULT_HISTORY_DAYS;
        $cacheKey = fn (string $table, int $p) => 'sync-boot:'.$companyId.':'.$scope.':'.$table.':'.$pageSize.':'.$days.':'.$p;

        // The seq this bootstrap stands at: rows written after it come with the first pull from it.
        $upto = isset($opts['upto']) && $opts['upto'] !== null ? (int) $opts['upto'] : null;
        if ($upto === null && $page > 1 && $scope !== null) {
            foreach ($tables as $t) {
                if (($c = Cache::get($cacheKey((string) $t, $page))) !== null) {
                    $upto = (int) $c['upto'];
                    break;
                }
            }
        }
        if ($upto === null && ($page === 1 || $after !== [])) {
            $upto = SyncSequence::current();
        }

        $out = [];
        foreach ($tables as $table) {
            $table = (string) $table;
            $config = SyncRegistry::get($table);
            if ($config === null) {
                continue;
            }
            if ($config['kind'] === SyncRegistry::KIND_SNAPSHOT) {
                $snap = $this->snapshot($companyId, $table, $config, null);
                $out[$table] = ['rows' => $snap['rows'], 'next_page' => null, 'has_more' => false, 'next_after' => null, 'cursor' => $snap['next_seq'], 'snapshot' => true];

                continue;
            }
            $model = $config['model'];
            $query = $model::query()->withoutGlobalScopes()->orderBy('server_seq');
            if ($config['kind'] !== SyncRegistry::KIND_REFERENCE) {
                $query->where('company_id', $companyId);
            }
            if ($days > 0 && in_array($table, SyncRegistry::HISTORY_TABLES, true)) {
                $this->historyWindow($query, $companyId, $table, now()->subDays($days));
            }

            $offset = null;
            if (array_key_exists($table, $after)) {
                $from = (int) $after[$table];
            } elseif ($page === 1) {
                $from = 0;
            } elseif ($scope !== null && ($c = Cache::get($cacheKey($table, $page))) !== null) {
                $from = (int) $c['after'];
            } else {
                $from = null;
                $offset = ($page - 1) * $pageSize; // an older app's page whose position was not remembered: as before
            }
            if ($from !== null) {
                $query->where('server_seq', '>', $from);
                if ($upto !== null) {
                    $query->where('server_seq', '<=', $upto);
                }
            } else {
                $query->offset($offset);
            }
            $rows = $query->limit($pageSize + 1)->get();
            $hasMore = $rows->count() > $pageSize;
            $rows = $rows->take($pageSize);
            $last = (int) ($rows->last()?->server_seq ?? 0);
            if ($hasMore && $scope !== null && $from !== null) {
                Cache::put($cacheKey($table, $page + 1), ['after' => $last, 'upto' => $upto], now()->addHours(2));
            }
            $wire = $rows->map(fn ($r) => $this->serializer->row($table, $r))->values()->all();
            $out[$table] = [
                'rows' => $this->enrich($companyId, $table, $wire),
                'next_page' => $hasMore ? $page + 1 : null,
                'has_more' => $hasMore,
                'next_after' => $hasMore ? $last : null,
                'cursor' => $upto ?? SyncSequence::current(),
            ];
        }

        return ['tables' => $out, 'seq' => $upto ?? SyncSequence::current(), 'history_days' => $days];
    }

    /** Only the recent history of an event table, plus what is still open. */
    private function historyWindow($query, int $companyId, string $table, \DateTimeInterface $cutoff): void
    {
        $openSales = fn ($q) => $q->select('id')->from('sale_records')->where('company_id', $companyId)->where('balance', '>', 0.004)->whereNull('voided_at');
        $recent = fn ($q) => $q->where('created_at', '>=', $cutoff);
        match ($table) {
            'sales' => $query->where(fn ($q) => $recent($q)->orWhere(fn ($w) => $w->where('balance', '>', 0.004)->whereNull('voided_at'))),
            'sale_items' => $query->whereIn('sale_record_id', fn ($s) => $s->select('id')->from('sale_records')->where('company_id', $companyId)
                ->where(fn ($q) => $recent($q)->orWhere(fn ($w) => $w->where('balance', '>', 0.004)->whereNull('voided_at')))),
            'payments' => $query->where(fn ($q) => $recent($q)->orWhereIn('sale_record_id', $openSales)),
            'shifts' => $query->where(fn ($q) => $recent($q)->orWhere('status', 'open')),
            'stock_takes' => $query->where(fn ($q) => $recent($q)->orWhere('status', 'draft')),
            default => $recent($query),
        };
    }

    /**
     * A snapshot table's whole set for the company, or nothing when the device's cursor is the set's fingerprint.
     *
     * @return array{rows: array, next_seq: int, has_more: bool, snapshot: bool, unchanged?: bool, truncated?: bool}
     */
    public function snapshot(int $companyId, string $table, array $config, ?int $since): array
    {
        $spec = $config['snapshot'];
        $db = $spec['table'];
        $cols = SyncRegistry::columns($db);
        $base = fn () => DB::table($db)->where('company_id', $companyId)->when(isset($spec['where']), fn ($q) => $q->where(...$spec['where']));
        $crc = fn (array $c, string $alias = '') => 'COUNT(*) AS n, COALESCE(BIT_XOR(CRC32(CONCAT_WS(\'|\', '
            .implode(', ', array_map(fn ($col) => "COALESCE(CAST({$alias}`{$col}` AS CHAR), '~')", $c)).'))), 0) AS x';
        $fp = $base()->selectRaw($crc($cols))->first();
        $mark = $table.'|'.$fp->n.'|'.$fp->x;
        if (! empty($spec['targets'])) {
            $t = DB::table('promotion_targets as t')->join('promotions as p', 'p.id', '=', 't.promotion_id')->where('p.company_id', $companyId)
                ->selectRaw($crc(SyncRegistry::columns('promotion_targets'), 't.'))->first();
            $mark .= '|'.$t->n.'|'.$t->x;
        }
        $token = (int) hexdec(substr(md5($mark), 0, 13)) ?: 1;
        if ($since !== null && $since === $token) {
            return ['rows' => [], 'next_seq' => $token, 'has_more' => false, 'snapshot' => false, 'unchanged' => true];
        }

        $rows = $base()->orderBy('id')->limit(self::MAX_SNAPSHOT_ROWS + 1)->get();
        $truncated = $rows->count() > self::MAX_SNAPSHOT_ROWS;
        $rows = $rows->take(self::MAX_SNAPSHOT_ROWS);
        $uuids = [];
        foreach ($spec['uuids'] ?? [] as $field => [$refTable, $column]) {
            $ids = $rows->pluck($column)->filter()->unique()->values()->all();
            $uuids[$field] = $ids ? DB::table($refTable)->whereIn('id', $ids)->pluck('uuid', 'id')->all() : [];
        }
        $targets = [];
        if (! empty($spec['targets']) && $rows->isNotEmpty()) {
            $all = DB::table('promotion_targets')->whereIn('promotion_id', $rows->pluck('id')->all())->orderBy('id')->get();
            $refTables = ['product' => 'stock_items', 'category' => 'stock_categories', 'sub_category' => 'stock_sub_categories'];
            $refUuids = [];
            foreach ($refTables as $type => $refTable) {
                $ids = $all->where('target_type', $type)->pluck('target_id')->unique()->values()->all();
                $refUuids[$type] = $ids ? DB::table($refTable)->whereIn('id', $ids)->pluck('uuid', 'id')->all() : [];
            }
            foreach ($all as $t) {
                $targets[(int) $t->promotion_id][] = ['target_type' => $t->target_type, 'target_id' => (int) $t->target_id, 'target_uuid' => $refUuids[$t->target_type][$t->target_id] ?? null];
            }
        }

        $wire = [];
        foreach ($rows as $r) {
            $row = (array) $r;
            foreach ($spec['json'] ?? [] as $j) {
                if (isset($row[$j]) && is_string($row[$j])) {
                    $row[$j] = json_decode($row[$j], true);
                }
            }
            foreach ($spec['uuids'] ?? [] as $field => [, $column]) {
                $row[$field] = $row[$column] ? ($uuids[$field][$row[$column]] ?? null) : null;
            }
            if (! empty($spec['targets'])) {
                $row['targets'] = $targets[(int) $r->id] ?? [];
            }
            $row['uuid'] = SyncSequence::childUuid('snapshot:'.$table, (string) $r->id);
            $row['server_seq'] = $token;
            $row['version'] = 1;
            $row['is_deleted'] = 0;
            $wire[] = $row;
        }

        return ['rows' => $wire, 'next_seq' => $token, 'has_more' => false, 'snapshot' => true] + ($truncated ? ['truncated' => true] : []);
    }

    /** Derived, read-only fields added to a page of rows (customers: `loyalty_points`, the loyalty ledger's sum). */
    private function enrich(int $companyId, string $table, array $wire): array
    {
        if ($table !== 'customers' || $wire === [] || SyncRegistry::columns('loyalty_ledger') === []) {
            return $wire;
        }
        $ids = array_values(array_filter(array_map(fn ($r) => (int) ($r['id'] ?? 0), $wire)));
        $points = DB::table('loyalty_ledger')->where('company_id', $companyId)->whereIn('customer_id', $ids)
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(points) AS p')->pluck('p', 'customer_id');
        foreach ($wire as &$row) {
            $row['loyalty_points'] = (int) ($points[(int) ($row['id'] ?? 0)] ?? 0);
        }

        return $wire;
    }
}
