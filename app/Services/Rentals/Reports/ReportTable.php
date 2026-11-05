<?php

namespace App\Services\Rentals\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a report class needs to build its table from a run's context (RentalReports::context): column definitions, id-to-name
 * lookups, the list search and the words for a period or an as-at date. Shared by RentalReports and the report classes beside it.
 */
trait ReportTable
{
    /** Whether a row passes the list's search (`q`): any of its words, ignoring case; no search passes everything. @param list<string> $haystack */
    private static function matches(object $x, array $haystack): bool
    {
        $q = trim((string) ($x->params['q'] ?? ''));

        return $q === '' || mb_stripos(implode(' ', $haystack), $q) !== false;
    }

    private function period(object $x): string
    {
        return Carbon::parse($x->from)->format('j M Y').' to '.Carbon::parse($x->to)->format('j M Y');
    }

    private function asAt(object $x): string
    {
        return 'As at '.$x->today->format('j M Y').$this->filterWords($x);
    }

    private function filterWords(object $x): string
    {
        return ($x->property !== null ? ' · '.$x->property->name : '').($x->landlord !== null ? ' · landlord '.$x->landlord->name : '');
    }

    /** @return array{key: string, label: string, type: string} */
    private static function c(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    /** id => a name column, for ids of one account's table. @return array<int, string> */
    private function names(string $table, string $column, array $ids, int $cid): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : DB::table($table)->where('company_id', $cid)->whereIn('id', $ids)->pluck($column, 'id')->all();
    }
}
