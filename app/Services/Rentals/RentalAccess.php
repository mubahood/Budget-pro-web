<?php

namespace App\Services\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalPropertyStaff;
use App\Models\User;
use App\Services\Team\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Who may do what in Estate Ledger (docs/estate-ledger/04-architecture.md §7): the rentals_* permissions of the person's role,
 * and the property scope of a caretaker, who sees only the properties they are assigned to (KR-17). A record
 * outside the scope answers as if it did not exist (`not_found`), so ids cannot be probed.
 */
final class RentalAccess
{
    public const CARETAKER = 'rentals_caretaker';

    /** The rentals account of a staff login. */
    public static function company(?User $user): ?Company
    {
        if ($user === null || $user->company_id === null) {
            return null;
        }
        $company = Company::withoutGlobalScopes()->find($user->company_id);

        return $company !== null && in_array('rentals', $company->modules(), true) ? $company : null;
    }

    public static function can(?User $user, string $permission): bool
    {
        if ($user === null || self::company($user) === null || Permissions::roleOf($user) === 'none') {
            return false;
        }

        return Permissions::can($user, $permission);
    }

    public static function authorize(?User $user, string $permission): void
    {
        if (! self::can($user, $permission)) {
            throw BusinessRuleException::make('forbidden', 'Your role does not allow this. Ask your property manager.', ['permission' => $permission]);
        }
    }

    /** The properties this login may see: null = all of them (anyone but a caretaker). @return list<int>|null */
    public static function propertyIds(?User $user): ?array
    {
        if ($user === null || Permissions::roleOf($user) !== self::CARETAKER) {
            return null;
        }

        return array_map('intval', RentalPropertyStaff::withoutGlobalScopes()->where('company_id', $user->company_id)
            ->where('user_id', $user->id)->pluck('property_id')->all());
    }

    /**
     * Narrow a query to the login's properties (a no-op for everyone but caretakers).
     *
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function scope(Builder|QueryBuilder $query, ?User $user, string $column = 'property_id'): Builder|QueryBuilder
    {
        $ids = self::propertyIds($user);

        return $ids === null ? $query : $query->whereIn($column, $ids === [] ? [0] : $ids);
    }

    public static function inScope(?User $user, ?int $propertyId): bool
    {
        $ids = self::propertyIds($user);

        return $ids === null || ($propertyId !== null && in_array($propertyId, $ids, true));
    }

    public static function assertInScope(?User $user, ?int $propertyId, string $what = 'record'): void
    {
        if (! self::inScope($user, $propertyId)) {
            throw BusinessRuleException::make('not_found', "That {$what} was not found.");
        }
    }
}
