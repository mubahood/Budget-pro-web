<?php

namespace Tests\Feature\Sacco;

use App\Models\User;
use App\Services\Team\Permissions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * Module-aware permissions (AK-60) must cost a family shop nothing: resolving a person's permissions reads the
 * company once (owner and modules from one row) and the membership once, as before modules had roles.
 */
class PermissionsQueryCountTest extends ApiTestCase
{
    public function test_resolving_permissions_reads_the_company_once(): void
    {
        foreach ([['business_type' => 'retail'], ['modules' => ['sacco']]] as $kind) {
            $t = $this->registerTenant($kind);
            $user = User::withoutGlobalScopes()->find($t['user_id']);
            Permissions::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            Permissions::of($user);
            Permissions::can($user, 'sell');
            Permissions::rolesFor((int) $user->company_id);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertSame(1, collect($queries)->filter(fn ($q) => str_contains($q['query'], 'from `companies`'))->count(), json_encode($kind));
            $this->assertLessThanOrEqual(3, count($queries), 'company, membership and role overrides: '.json_encode(array_column($queries, 'query')));
        }
    }
}
