<?php

namespace App\Services\Sync;

use App\Exceptions\BusinessRuleException;
use App\Support\Sync\SyncSequence;

/** `GET /sync/pull` and `POST /sync/bootstrap` (A.3/A.4): seq cursor, paging, never timestamps. */
class SyncPuller
{
    public const MAX_LIMIT = 1000;

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

        return ['table' => $table, 'rows' => $wire, 'next_seq' => $next, 'has_more' => $hasMore];
    }

    /** First-install snapshot: every table from seq 0, paged per table. */
    public function bootstrap(int $companyId, array $tables, int $page, int $pageSize = 500): array
    {
        $out = [];
        foreach ($tables as $table) {
            $config = SyncRegistry::get($table);
            if ($config === null) {
                continue;
            }
            $model = $config['model'];
            $query = $model::query()->withoutGlobalScopes()->orderBy('server_seq');
            if ($config['kind'] !== SyncRegistry::KIND_REFERENCE) {
                $query->where('company_id', $companyId);
            }
            $rows = $query->offset(max(0, $page - 1) * $pageSize)->limit($pageSize + 1)->get();
            $hasMore = $rows->count() > $pageSize;
            $out[$table] = [
                'rows' => $rows->take($pageSize)->map(fn ($r) => $this->serializer->row($table, $r))->values()->all(),
                'next_page' => $hasMore ? $page + 1 : null,
            ];
        }

        return ['tables' => $out, 'seq' => SyncSequence::current()];
    }
}
