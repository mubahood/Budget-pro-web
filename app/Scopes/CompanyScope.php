<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant isolation for every model that carries a company_id.
 *
 * Resolves the current user from the session guards (`web`, then `admin`) so
 * the scope is live inside the laravel-admin panel too — previously it only
 * looked at the default `web` guard, which the admin panel never uses, so every
 * admin `findOrFail($id)` was an IDOR (P0-2). The API is deliberately NOT
 * covered here: `BaseCrudController` scopes explicitly with
 * `withoutGlobalScopes()->where(company_id)`, and Ping Pin's multi-organisation
 * membership must not be filtered by a single company_id.
 */
class CompanyScope implements Scope
{
    /** Guards consulted, in order. Never `sanctum` (see class docblock). */
    public const GUARDS = ['web', 'admin'];

    /** @var array<string,bool> table => has company_id column */
    private static array $columnCache = [];

    public function apply(Builder $builder, Model $model)
    {
        $companyId = self::currentCompanyId();
        if ($companyId === null) {
            return;
        }

        if (! $this->hasCompanyIdColumn($model)) {
            return;
        }

        $builder->where($model->getTable().'.company_id', '=', $companyId);
    }

    /** The authenticated session user's company_id, or null when unauthenticated / no company. */
    public static function currentCompanyId(): ?int
    {
        foreach (self::GUARDS as $guard) {
            $user = Auth::guard($guard)->user();
            if ($user !== null) {
                return $user->company_id ? (int) $user->company_id : null;
            }
        }

        return null;
    }

    protected function hasCompanyIdColumn(Model $model): bool
    {
        $table = $model->getTable();

        if (! array_key_exists($table, self::$columnCache)) {
            self::$columnCache = self::cachedMap($table);
        }

        return self::$columnCache[$table];
    }

    /**
     * The table => has-company_id map, kept in the cache for good (plan A2) so a request no longer
     * reads information_schema. The key carries the database and the migrations folder's mtime, so a
     * deploy that adds migrations starts a new map in both apps; `migrate` also forgets it
     * (AppServiceProvider, MigrationsEnded). A table missing from the map is looked up once and added.
     *
     * @return array<string, bool>
     */
    private static function cachedMap(string $table): array
    {
        $key = self::cacheKey();
        $map = [];
        try {
            $map = (array) Cache::rememberForever($key, fn () => []);
        } catch (\Throwable) {
            // No usable cache store (early boot, misconfigured): fall back to this process only.
        }
        $map = self::$columnCache + $map;
        if (! array_key_exists($table, $map)) {
            $map[$table] = in_array('company_id', Schema::getColumnListing($table), true);
            try {
                Cache::forever($key, $map);
            } catch (\Throwable) {
            }
        }

        return $map;
    }

    private static function cacheKey(): string
    {
        $db = (string) config('database.connections.'.config('database.default').'.database');
        $migrations = @filemtime(dirname(__DIR__, 2).'/database/migrations') ?: 0;

        return 'company_scope.columns.'.md5($db).'.'.$migrations;
    }

    /** For tests / schema changes at runtime (and after `migrate`): this process and the cache. */
    public static function flushColumnCache(): void
    {
        self::$columnCache = [];
        try {
            Cache::forget(self::cacheKey());
        } catch (\Throwable) {
        }
    }

    public function extend(Builder $builder)
    {
        $builder->macro('withoutCompanyScope', function (Builder $builder) {
            return $builder->withoutGlobalScope(static::class);
        });

        $builder->macro('forCompany', function (Builder $builder, $companyId) {
            $model = $builder->getModel();

            return $builder->withoutGlobalScope(static::class)
                ->where($model->getTable().'.company_id', '=', $companyId);
        });

        $builder->macro('allCompanies', function (Builder $builder) {
            return $builder->withoutGlobalScope(static::class);
        });
    }
}
