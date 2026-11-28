<?php

namespace App\Services\Sacco\Members;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * A family's purse holds only some of the family (doc 13.1 T3): in a company with the kin module, every savings list,
 * picker and member-facing report shows the people with in_purse = 1, not the whole tree. Every other SACCO is unchanged
 * (applies() is false, so nothing is added to its queries).
 */
final class PurseOnly
{
    public static function applies(Company|int $company): bool
    {
        $company = $company instanceof Company ? $company : Company::withoutGlobalScopes()->find($company);

        return $company !== null && in_array('kin', $company->modules(), true);
    }

    /**
     * Keep a members query (sacco_members, or a join naming it by $column) to the purse's people.
     *
     * @template T of EloquentBuilder|QueryBuilder
     * @param T $q
     * @return T
     */
    public static function members(EloquentBuilder|QueryBuilder $q, Company|int $company, string $column = 'in_purse'): EloquentBuilder|QueryBuilder
    {
        return self::applies($company) ? $q->where($column, 1) : $q;
    }

    /**
     * Keep a query of rows that name a member (accounts, loans) to the purse's people.
     *
     * @template T of EloquentBuilder|QueryBuilder
     * @param T $q
     * @return T
     */
    public static function byMember(EloquentBuilder|QueryBuilder $q, Company|int $company, string $column = 'member_id'): EloquentBuilder|QueryBuilder
    {
        if (! self::applies($company)) {
            return $q;
        }
        $cid = $company instanceof Company ? (int) $company->id : $company;

        return $q->whereIn($column, DB::table('sacco_members')->select('id')->where('company_id', $cid)->where('in_purse', 1));
    }
}
