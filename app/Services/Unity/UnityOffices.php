<?php

namespace App\Services\Unity;

use Illuminate\Support\Facades\DB;

/** The committee offices members hold now (serving: no end date, or one still ahead). */
final class UnityOffices
{
    /**
     * @param  array<int, int>  $memberIds
     * @return array<int, list<array{committee_id: int, committee: string, title: string}>> member id => offices
     */
    public static function of(int $companyId, array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }
        $out = [];
        foreach (self::serving($companyId)->whereIn('cm.member_id', $memberIds)
            ->orderBy('c.chapter_id')->orderBy('c.sort_order')->orderBy('cm.sort_order')
            ->get(['cm.member_id', 'c.id AS committee_id', 'c.name AS committee', 'cm.title']) as $r) {
            $out[(int) $r->member_id][] = ['committee_id' => (int) $r->committee_id, 'committee' => $r->committee, 'title' => $r->title];
        }

        return $out;
    }

    /** Serving officials joined to their committee (aliases cm, c). */
    public static function serving(int $companyId): \Illuminate\Database\Query\Builder
    {
        $today = \App\Support\LocalDate::today($companyId)->toDateString();

        return DB::table('unity_committee_members AS cm')->join('unity_committees AS c', 'c.id', '=', 'cm.committee_id')
            ->where('cm.company_id', $companyId)->where('cm.is_deleted', 0)->where('c.is_deleted', 0)
            ->where(fn ($w) => $w->whereNull('cm.ends_on')->orWhere('cm.ends_on', '>=', $today));
    }
}
