<?php

namespace Tests\Feature\Rentals;

use App\Http\Middleware\ApiPermissionMap;
use App\Models\Company;
use App\Models\RentalCharge;
use App\Models\RentalEntry;
use App\Models\RentalExpense;
use App\Models\RentalLease;
use App\Models\RentalLeaseItem;
use App\Models\RentalMaintenance;
use App\Models\RentalPayment;
use App\Models\RentalPayout;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Maintenance;
use App\Services\Rentals\Payments\Payments;
use App\Services\Team\Permissions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * The staff API (docs/estate-ledger/04-architecture.md §9, §9.1): every endpoint, each role's allow and deny from
 * ApiPermissionMap (the route table is walked, so a new route with no line here fails), a caretaker's out-of-scope
 * record and another account's record answering 404, the module off, a lapsed plan, idempotent replay, the API's refusals.
 */
class RentalsApiTest extends ApiTestCase
{
    use MakesRentals;

    /** Every staff route and the permission §7 and §9 give it. */
    private const ROUTES = [
        'GET overview' => 'rentals_view', 'POST setup' => 'manage_settings', 'GET settings' => 'rentals_view', 'PUT settings' => 'rentals_settings',
        'GET landlords' => 'rentals_view', 'POST landlords' => 'rentals_manage', 'GET landlords/{id}' => 'rentals_view', 'PUT landlords/{id}' => 'rentals_manage',
        'GET landlords/{id}/statement' => 'rentals_landlords', 'POST landlords/{id}/login' => 'rentals_settings',
        'GET payouts' => 'rentals_landlords', 'POST payouts' => 'rentals_landlords', 'POST payouts/{id}/reverse' => 'rentals_reverse',
        'GET properties' => 'rentals_view', 'POST properties' => 'rentals_manage', 'GET properties/{id}' => 'rentals_view', 'PUT properties/{id}' => 'rentals_manage',
        'PUT properties/{id}/staff' => 'rentals_settings', 'POST properties/{id}/handover' => 'rentals_manage',
        'GET units' => 'rentals_view', 'POST units' => 'rentals_manage', 'GET units/{id}' => 'rentals_view', 'PUT units/{id}' => 'rentals_manage',
        'GET tenants' => 'rentals_view', 'POST tenants' => 'rentals_manage', 'GET tenants/{id}' => 'rentals_view', 'PUT tenants/{id}' => 'rentals_manage',
        'POST tenants/{id}/login' => 'rentals_settings',
        'GET leases' => 'rentals_view', 'POST leases' => 'rentals_manage', 'GET leases/{id}' => 'rentals_view',
        'POST leases/{id}/notice' => 'rentals_manage', 'POST leases/{id}/withdraw-notice' => 'rentals_manage', 'POST leases/{id}/renew' => 'rentals_manage',
        'POST leases/{id}/move-out' => 'rentals_manage', 'POST leases/{id}/cancel' => 'rentals_manage', 'POST leases/{id}/refund-deposit' => 'rentals_manage',
        'POST leases/{id}/refund-credit' => 'rentals_manage', 'POST leases/{id}/rent-change' => 'rentals_bill', 'POST leases/{id}/items' => 'rentals_bill',
        'POST leases/{id}/items/{item}/end' => 'rentals_bill', 'GET leases/{id}/payment-preview' => 'rentals_view', 'GET leases/{id}/reminder' => 'rentals_view',
        'GET charges' => 'rentals_view', 'POST charges' => 'rentals_bill', 'POST charges/{id}/void' => 'rentals_bill', 'POST charges/{id}/waive' => 'rentals_bill', 'POST billing/run' => 'rentals_bill',
        'GET payments' => 'rentals_view', 'POST payments' => 'rentals_collect', 'GET payments/{id}' => 'rentals_view', 'POST payments/{id}/reverse' => 'rentals_reverse', 'GET payments/{id}/receipt' => 'rentals_view',
        'GET arrears' => 'rentals_view', 'GET follow-ups' => 'rentals_view', 'POST follow-ups' => 'rentals_collect',
        'GET expenses' => 'rentals_expenses', 'POST expenses' => 'rentals_expenses', 'POST expenses/{id}/reverse' => 'rentals_reverse',
        'GET cash' => 'rentals_expenses', 'POST cash/transfers' => 'rentals_expenses', 'POST cash/opening' => 'rentals_settings',
        'GET documents' => 'rentals_view', 'POST documents' => 'rentals_manage', 'DELETE documents/{id}' => 'rentals_manage',
        'GET maintenance' => 'rentals_maintenance', 'POST maintenance' => 'rentals_maintenance', 'GET maintenance/{id}' => 'rentals_maintenance', 'PUT maintenance/{id}' => 'rentals_maintenance',
        'GET meter-readings' => 'rentals_maintenance', 'POST meter-readings' => 'rentals_maintenance',
        'GET reports' => 'rentals_reports', 'GET reports/{key}' => 'rentals_reports', 'GET activity' => 'rentals_settings',
    ];

    /** Routes that other builders add for their screens, each with the permission it must have. */
    private const OTHER_BUILDERS = [];

    /** The shapes of §9.1 (the app and the portal read these names). */
    private const POSITION = ['owed_minor', 'overdue_minor', 'overdue_days', 'credit_minor', 'deposit_held_minor', 'paid_up_to', 'next_due', 'state'];

    private const LEASE_BRIEF = ['id', 'number', 'status', 'start_date', 'end_date', 'rent_minor', 'cycle_months', 'currency', 'unit', 'property', 'tenant', 'position'];

    private const LEASE = ['landlord', 'anchor', 'deposit_minor', 'deposit_held_by', 'commission', 'late_fee', 'billed_until', 'notice_on', 'move_out_on', 'ended_on', 'end_reason', 'notes',
        'items', 'rent_changes', 'charges', 'payments', 'follow_ups'];

    private const CHARGE = ['id', 'lease_id', 'kind', 'description', 'period_start', 'period_end', 'due_date', 'amount_minor', 'paid_minor', 'left_minor', 'status', 'goes_to', 'overdue', 'days_overdue'];

    private const PAYMENT_BRIEF = ['id', 'uuid', 'number', 'paid_on', 'amount_minor', 'method', 'reference', 'reversed', 'tenant', 'unit', 'property'];

    private const PAYMENT = ['lease', 'currency', 'received_by', 'channel', 'notes', 'unallocated_minor', 'allocations', 'reversed_at', 'reverse_reason', 'owed_after_minor', 'paid_up_to', 'receipt'];

    private const LANDLORD = ['id', 'code', 'name', 'phone', 'email', 'address', 'payout_method', 'payout_details', 'commission', 'is_self', 'status', 'properties_count', 'units_count', 'balance_minor', 'notes'];

    private const PROPERTY = ['id', 'code', 'name', 'kind', 'address', 'area', 'district', 'lat', 'lng', 'photo_url', 'landlord', 'commission', 'deposit_held_by', 'water_rate_minor', 'power_rate_minor', 'status',
        'units_count', 'occupied_count', 'vacant_count', 'occupancy_pct', 'arrears_minor', 'staff'];

    private const UNIT = ['id', 'property', 'code', 'kind', 'floor', 'block', 'bedrooms', 'bathrooms', 'size_sqm', 'rent_minor', 'deposit_minor', 'cycle_months', 'status', 'state', 'water_meter', 'power_meter', 'notes', 'photo_url', 'lease'];

    private const TENANT = ['id', 'code', 'kind', 'name', 'phone', 'alt_phone', 'email', 'id_type', 'id_number_masked', 'occupation', 'employer', 'next_of_kin_name', 'next_of_kin_phone', 'photo_url', 'notes', 'status',
        'owed_minor', 'credit_minor', 'has_login', 'leases'];

    private const PAYOUT = ['id', 'number', 'landlord', 'amount_minor', 'method', 'reference', 'paid_on', 'period_from', 'period_to', 'notes', 'reversed'];

    private const EXPENSE = ['id', 'number', 'property', 'unit', 'landlord', 'category', 'description', 'vendor', 'amount_minor', 'method', 'paid_on', 'charged_to', 'receipt_url', 'maintenance_id', 'reversed'];

    private const MAINTENANCE = ['id', 'uuid', 'number', 'property', 'unit', 'tenant', 'title', 'details', 'priority', 'status', 'reported_by', 'assigned_to', 'assignee_name', 'photos', 'cost_minor', 'expense_id', 'resolved_on', 'resolution', 'created_at'];

    private const READING = ['id', 'uuid', 'unit', 'utility', 'reading', 'previous', 'used', 'rate_minor', 'amount_minor', 'read_on', 'reset', 'charge_id'];

    private const OVERVIEW = ['currency', 'mode', 'units', 'occupied', 'vacant', 'occupancy_pct', 'month', 'arrears', 'credit_minor', 'deposits_minor', 'landlords_minor', 'maintenance_open', 'ending_30', 'cash', 'attention', 'today'];

    private const STATEMENT = ['landlord', 'from', 'to', 'currency', 'opening_minor', 'closing_minor', 'lines', 'totals', 'occupancy', 'arrears'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function h(User $u): array
    {
        return $this->auth($u->createToken('t')->plainTextToken);
    }

    private function assertShape(array $data, array $keys, string $what = ''): void
    {
        $this->assertSame([], array_values(array_diff($keys, array_keys($data))), "missing from {$what}");
    }

    /** One account with a record of every kind, on property $p (and a second property, $q, with its own lease). */
    private function world(string $mode = 'agency'): array
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount($mode);
        [$p, $units] = $this->building($owner, 3, 300000, ['water_rate_minor' => 2000]);
        [$q, $others] = $this->building($owner, 1, 200000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 100000, 'items' => [['name' => 'Garbage', 'amount_minor' => 10000]]]);
        $out = $this->tenantIn($owner, $others[0], '2026-03-01', ['deposit_minor' => 0]);
        $payment = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 700000, 'method' => 'cash']);
        $outPayment = app(Payments::class)->receive($owner, $out, ['amount_minor' => 500000, 'method' => 'cash']);
        $payout = app(Payouts::class)->record($owner, \App\Models\RentalLandlord::findOrFail($p->landlord_id), ['amount_minor' => 1000, 'method' => 'cash']);
        $expense = app(Expenses::class)->record($owner, ['property_id' => $p->id, 'category' => 'Repairs', 'amount_minor' => 1000, 'method' => 'cash']);
        $ticket = app(Maintenance::class)->open($owner, (int) $company->id, ['unit_id' => $units[0]->id, 'title' => 'Tap']);
        $outTicket = app(Maintenance::class)->open($owner, (int) $company->id, ['unit_id' => $others[0]->id, 'title' => 'Door']);

        return ['company' => $company, 'owner' => $owner, 'p' => $p, 'q' => $q, 'units' => $units, 'other_unit' => $others[0], 'lease' => $lease, 'out' => $out, 'payment' => $payment,
            'out_payment' => $outPayment, 'payout' => $payout, 'expense' => $expense, 'ticket' => $ticket, 'out_ticket' => $outTicket,
            'item' => RentalLeaseItem::where('lease_id', $lease->id)->firstOrFail(),
            'charge' => RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->firstOrFail(),
            'out_charge' => RentalCharge::where('lease_id', $out->id)->firstOrFail()];
    }

    /** The path of a route template with this world's ids. */
    private function path(string $uri, array $w, bool $outOfScope = false): string
    {
        $base = str_replace('api/v1/rentals/', '', $uri);
        $ids = $outOfScope
            ? ['landlords' => $w['q']->landlord_id, 'properties' => $w['q']->id, 'units' => $w['other_unit']->id, 'tenants' => $w['out']->tenant_id, 'leases' => $w['out']->id, 'charges' => $w['out_charge']->id,
                'payments' => $w['out_payment']->id, 'payouts' => $w['payout']->id, 'expenses' => $w['expense']->id, 'maintenance' => $w['out_ticket']->id, 'documents' => 999999]
            : ['landlords' => $w['p']->landlord_id, 'properties' => $w['p']->id, 'units' => $w['units'][0]->id, 'tenants' => $w['lease']->tenant_id, 'leases' => $w['lease']->id, 'charges' => $w['charge']->id,
                'payments' => $w['payment']->id, 'payouts' => $w['payout']->id, 'expenses' => $w['expense']->id, 'maintenance' => $w['ticket']->id, 'documents' => 999999];
        $collection = explode('/', $base)[0];
        $path = str_replace('{id}', (string) ($ids[$collection] ?? 0), $base);
        $path = str_replace('{item}', (string) $w['item']->id, $path);

        return '/api/v1/rentals/'.str_replace('{key}', 'rent_roll', $path);
    }

    /** @return list<array{0: string, 1: string}> [method, route template] of every staff route; PUT and PATCH count once. */
    private function staffRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            if (! str_starts_with($r->uri(), 'api/v1/rentals/') || ! in_array('auth:sanctum', $r->gatherMiddleware(), true)) {
                continue;
            }
            foreach ($r->methods() as $m) {
                if (in_array($m, ['HEAD', 'PATCH'], true)) {
                    continue;
                }
                $out[] = [$m, $r->uri()];
            }
        }

        return $out;
    }

    public function test_every_staff_route_has_its_permission_line_and_the_right_middleware(): void
    {
        $table = self::ROUTES + self::OTHER_BUILDERS;
        $walked = [];
        foreach ($this->staffRoutes() as [$m, $uri]) {
            $walked[] = $m.' '.str_replace('api/v1/rentals/', '', $uri);
        }
        $this->assertSame([], array_values(array_diff($walked, array_keys($table))), 'a new route needs its permission here');
        $this->assertSame([], array_values(array_diff(array_keys($table), $walked)), 'a route in the table no longer exists');

        foreach (Route::getRoutes() as $r) {
            if (str_starts_with($r->uri(), 'api/v1/rentals/') && in_array('auth:sanctum', $r->gatherMiddleware(), true)) {
                $this->assertSame([], array_values(array_diff(['api.tenant', 'api.module:rentals', 'api.subscription', 'api.perm', 'api.idempotent'], $r->gatherMiddleware())), $r->uri());
            }
        }

        // ApiPermissionMap agrees with the table: writes have a line of their own, reads fall to rentals_view or their own.
        foreach ($table as $route => $permission) {
            [$m, $uri] = explode(' ', $route);
            $concrete = 'rentals/'.preg_replace(['/\{id\}|\{item\}/', '/\{key\}/'], ['7', 'rent_roll'], $uri);
            $found = 'no line';
            foreach (ApiPermissionMap::RULES as [$methods, $pattern, $perm]) {
                if (in_array($m, $methods, true) && preg_match($pattern, $concrete)) {
                    $found = $perm;
                    break;
                }
            }
            $this->assertSame($permission, $found, $route);
        }
    }

    public function test_every_role_is_allowed_and_denied_exactly_as_the_permission_map_says(): void
    {
        $w = $this->world();
        $company = $w['company'];
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        app(Properties::class)->assignStaff($w['owner'], $w['p'], [$caretaker->id]);
        $roles = ['owner' => $w['owner'], 'manager' => $this->rentalsStaff($company, 'rentals_manager'), 'accountant' => $this->rentalsStaff($company, 'rentals_accountant'),
            'caretaker' => $caretaker, 'viewer' => $this->rentalsStaff($company, 'viewer')];
        $tokens = array_map(fn (User $u) => $this->h($u), $roles);
        $expect = [
            'manager' => ['rentals_view', 'rentals_manage', 'rentals_collect', 'rentals_bill', 'rentals_reverse', 'rentals_landlords', 'rentals_expenses', 'rentals_maintenance', 'rentals_reports', 'rentals_settings'],
            'accountant' => ['rentals_view', 'rentals_collect', 'rentals_bill', 'rentals_reverse', 'rentals_landlords', 'rentals_expenses', 'rentals_reports'],
            'caretaker' => ['rentals_view', 'rentals_collect', 'rentals_maintenance'],
            'viewer' => ['rentals_view', 'rentals_reports'],
        ];
        $checked = 0;
        foreach ($this->staffRoutes() as [$m, $uri]) {
            $permission = self::ROUTES[$m.' '.str_replace('api/v1/rentals/', '', $uri)] ?? null;
            if ($permission === null) {
                continue;
            }
            foreach ($roles as $name => $user) {
                $allowed = $name === 'owner' ? $permission !== '' : in_array($permission, $expect[$name], true);
                DB::beginTransaction();
                $res = $this->json($m, $this->path($uri, $w), [], $tokens[$name]);
                DB::rollBack();
                $by = "{$name} on {$m} {$uri}: ".$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 160);
                if ($allowed) {
                    $this->assertFalse($res->getStatusCode() === 403 && $res->json('errors.permission') === $permission, $by);
                    $this->assertLessThan(500, $res->getStatusCode(), $by);
                } else {
                    $this->assertSame(403, $res->getStatusCode(), $by);
                    $this->assertSame('forbidden', $res->json('errors.code'), $by);
                    $this->assertSame($permission, $res->json('errors.permission'), $by);
                    $this->assertStringContainsString('property manager', (string) $res->json('message'), 'an Estate Ledger team never hears of a shop');
                }
                $checked++;
            }
        }
        $this->assertGreaterThan(300, $checked);
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_record_of_another_account_is_404_at_every_id_route(): void
    {
        $w = $this->world();
        [, $stranger] = $this->rentalsAccount();
        $h = $this->h($stranger);
        $n = 0;
        foreach ($this->staffRoutes() as [$m, $uri]) {
            if (! str_contains($uri, '{id}')) {
                continue;
            }
            $res = $this->json($m, $this->path($uri, $w), ['amount_minor' => 1000, 'reason' => 'x', 'rent_minor' => 1000, 'effective_from' => '2026-06-01', 'move_out_on' => '2026-06-01', 'method' => 'cash',
                'user_ids' => [], 'name' => 'X', 'code' => 'X', 'status' => 'open'], $h);
            $this->assertSame(404, $res->getStatusCode(), "{$m} {$uri}: ".$res->getContent());
            $this->assertSame('not_found', $res->json('errors.code'), "{$m} {$uri}");
            $n++;
        }
        $this->assertGreaterThan(25, $n);

        // Ids in a body or a filter too.
        foreach ([['payments', ['lease_id' => $w['lease']->id, 'amount_minor' => 1000, 'method' => 'cash']], ['charges', ['lease_id' => $w['lease']->id, 'amount_minor' => 1000, 'description' => 'x']],
            ['follow-ups', ['lease_id' => $w['lease']->id, 'channel' => 'note']], ['meter-readings', ['unit_id' => $w['units'][0]->id, 'utility' => 'water', 'reading' => '5']],
            ['maintenance', ['unit_id' => $w['units'][0]->id, 'title' => 'x']], ['payouts', ['landlord_id' => $w['p']->landlord_id, 'amount_minor' => 1000]],
            ['expenses', ['property_id' => $w['p']->id, 'category' => 'Repairs', 'amount_minor' => 1000]], ['leases', ['unit_id' => $w['units'][1]->id, 'tenant' => ['name' => 'X'], 'start_date' => '2026-03-01']],
            ['units', ['property_id' => $w['p']->id, 'code' => 'Z1', 'rent_minor' => 1000]], ['billing/run', ['lease_id' => $w['lease']->id]],
            ['documents', ['owner_type' => 'lease', 'owner_id' => $w['lease']->id, 'file_uuid' => (string) Str::uuid(), 'name' => 'x']]] as [$path, $body]) {
            $this->postJson("/api/v1/rentals/{$path}", $body, $h)->assertStatus(404);
        }
        foreach (['payments?lease_id', 'charges?lease_id', 'leases?tenant_id', 'follow-ups?lease_id'] as $list) {
            $this->assertSame([], $this->getJson("/api/v1/rentals/{$list}={$w['lease']->id}", $h)->assertOk()->json('data'), $list);
        }
        $this->postJson('/api/v1/rentals/properties', ['name' => 'X', 'landlord_id' => $w['p']->landlord_id], $h)->assertStatus(422)->assertJsonPath('errors.code', 'landlord_required');
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_a_caretaker_sees_only_their_properties_and_a_record_outside_them_is_404(): void
    {
        $w = $this->world();
        $caretaker = $this->rentalsStaff($w['company'], 'rentals_caretaker');
        app(Properties::class)->assignStaff($w['owner'], $w['p'], [$caretaker->id]);
        $h = $this->h($caretaker);

        foreach (['GET landlords/{id}', 'GET properties/{id}', 'GET units/{id}', 'GET tenants/{id}', 'GET leases/{id}', 'GET leases/{id}/reminder', 'GET leases/{id}/payment-preview?amount_minor=5',
            'GET payments/{id}', 'GET payments/{id}/receipt', 'GET maintenance/{id}', 'PUT maintenance/{id}'] as $route) {
            [$m, $uri] = explode(' ', $route);
            $this->json($m, $this->path($uri, $w, true), ['title' => 'x'], $h)->assertStatus(404)->assertJsonPath('errors.code', 'not_found');
            $this->assertNotSame(404, $this->json($m, $this->path($uri, $w), ['title' => 'x'], $h)->getStatusCode(), "{$route} inside the scope");
        }
        foreach ([['payments', ['lease_id' => $w['out']->id, 'amount_minor' => 1000, 'method' => 'cash']], ['follow-ups', ['lease_id' => $w['out']->id, 'channel' => 'note']],
            ['meter-readings', ['unit_id' => $w['other_unit']->id, 'utility' => 'water', 'reading' => '5']], ['maintenance', ['unit_id' => $w['other_unit']->id, 'title' => 'x']]] as [$path, $body]) {
            $this->postJson("/api/v1/rentals/{$path}", $body, $h)->assertStatus(404);
        }
        // Lists hold only their property.
        foreach (['properties' => [(int) $w['p']->id], 'units' => array_map(fn ($u) => (int) $u->id, $w['units']), 'leases' => [(int) $w['lease']->id], 'tenants' => [(int) $w['lease']->tenant_id],
            'landlords' => [(int) $w['p']->landlord_id], 'payments' => [(int) $w['payment']->id], 'maintenance' => [(int) $w['ticket']->id]] as $list => $ids) {
            $got = array_column($this->getJson("/api/v1/rentals/{$list}?per_page=100", $h)->assertOk()->json('data'), 'id');
            sort($got);
            sort($ids);
            $this->assertSame($ids, $got, $list);
        }
        $charges = $this->getJson('/api/v1/rentals/charges', $h)->assertOk()->json('data');
        $this->assertSame([(int) $w['lease']->id], array_values(array_unique(array_column($charges, 'lease_id'))));

        // The overview counts what is theirs: their 3 units, no cash book, nothing of the other property.
        $o = $this->getJson('/api/v1/rentals/overview', $h)->assertOk()->json('data');
        $this->assertSame(3, $o['units']);
        $this->assertNull($o['cash']);
        // A caretaker with nothing assigned sees nothing at all.
        $none = $this->h($this->rentalsStaff($w['company'], 'rentals_caretaker'));
        foreach (['properties', 'units', 'leases', 'tenants', 'landlords', 'payments', 'charges', 'maintenance', 'follow-ups'] as $list) {
            $this->assertSame([], $this->getJson("/api/v1/rentals/{$list}", $none)->assertOk()->json('data'), $list);
        }
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_the_module_off_and_a_lapsed_plan(): void
    {
        $w = $this->world();
        $shop = $this->registerTenant(['business_type' => 'retail']);
        $sh = $this->auth($shop['token']);
        foreach ($this->staffRoutes() as [$m, $uri]) {
            $this->json($m, $this->path($uri, $w), [], $sh)->assertStatus(403)->assertJsonPath('errors.code', 'module_off');
        }
        // No token at all.
        $this->getJson('/api/v1/rentals/overview')->assertStatus(401);

        $h = $this->h($w['owner']);
        $sub = Subscription::where('company_id', $w['company']->id)->firstOrFail();
        // In grace: still at work, and told so.
        $sub->forceFill(['status' => 'trialing', 'trial_ends_at' => now()->subDays(2)])->save();
        Company::withoutGlobalScopes()->whereKey($w['company']->id)->update(['license_expire' => null]);
        $res = $this->postJson('/api/v1/rentals/payments', ['lease_id' => $w['lease']->id, 'amount_minor' => 1000, 'method' => 'cash'], $this->h($w['owner']));
        $res->assertStatus(201)->assertHeader('X-Subscription-State', 'grace');

        // Past grace: refused with its own status, nothing posted, the books untouched.
        $sub->forceFill(['status' => 'trialing', 'trial_ends_at' => now()->subDays(60)])->save();
        $count = RentalPayment::where('company_id', $w['company']->id)->count();
        $this->postJson('/api/v1/rentals/payments', ['lease_id' => $w['lease']->id, 'amount_minor' => 1000, 'method' => 'cash'], $this->h($w['owner']))
            ->assertStatus(402)->assertJsonPath('data.reason', 'subscription_expired');
        $this->getJson('/api/v1/rentals/leases', $h)->assertStatus(402);
        $this->assertSame($count, RentalPayment::where('company_id', $w['company']->id)->count());
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_an_idempotent_replay_never_posts_money_twice(): void
    {
        $w = $this->world();
        $cid = (int) $w['company']->id;
        $h = $this->h($w['owner']) + ['Idempotency-Key' => 'replay-1'];
        $body = ['lease_id' => $w['lease']->id, 'amount_minor' => 50000, 'method' => 'cash'];
        $first = $this->postJson('/api/v1/rentals/payments', $body, $h)->assertStatus(201);
        $cash = RentalLedger::cash($cid)['cash'];
        $entries = RentalEntry::where('company_id', $cid)->count();
        $again = $this->postJson('/api/v1/rentals/payments', $body, $h)->assertStatus(201)->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('data.number'), $again->json('data.number'));
        $this->assertSame([$cash, $entries], [RentalLedger::cash($cid)['cash'], RentalEntry::where('company_id', $cid)->count()]);
        $this->postJson('/api/v1/rentals/payments', ['amount_minor' => 60000] + $body, $h)->assertStatus(422)->assertJsonPath('errors.code', 'idempotency_key_reused');

        // Money out as well: payouts, expenses, transfers, reversals.
        foreach ([['payouts', ['landlord_id' => $w['p']->landlord_id, 'amount_minor' => 1000, 'method' => 'cash']], ['expenses', ['property_id' => $w['p']->id, 'category' => 'Repairs', 'amount_minor' => 1000, 'method' => 'cash']],
            ['cash/transfers', ['from' => 'cash', 'to' => 'bank', 'amount_minor' => 1000]], ['payments/'.$first->json('data.id').'/reverse', ['reason' => 'Bounced']]] as $i => [$path, $b]) {
            $key = $this->h($w['owner']) + ['Idempotency-Key' => 'money-'.$i];
            $one = $this->postJson("/api/v1/rentals/{$path}", $b, $key);
            $this->assertContains($one->getStatusCode(), [200, 201], $path.' '.$one->getContent());
            $n = RentalEntry::where('company_id', $cid)->count();
            $two = $this->postJson("/api/v1/rentals/{$path}", $b, $key)->assertHeader('Idempotent-Replay', 'true');
            $this->assertSame($one->getStatusCode(), $two->getStatusCode());
            $this->assertSame($n, RentalEntry::where('company_id', $cid)->count(), $path);
        }

        // Without a key, a payment's own uuid is the same promise: 200 the first payment; another payload 409.
        $uuid = (string) Str::uuid();
        $h2 = $this->h($w['owner']);
        $this->postJson('/api/v1/rentals/payments', $body + ['uuid' => $uuid], $h2)->assertStatus(201);
        $this->postJson('/api/v1/rentals/payments', $body + ['uuid' => $uuid], $h2)->assertOk()->assertJsonPath('message', 'Already received.');
        $this->postJson('/api/v1/rentals/payments', ['amount_minor' => 1] + $body + ['uuid' => $uuid], $h2)->assertStatus(409)->assertJsonPath('errors.code', 'idempotency_conflict');
        // The phone must send one.
        $this->postJson('/api/v1/rentals/payments', $body, $h2 + ['X-App-Version' => '9.9.9'])->assertStatus(422)->assertJsonPath('errors.code', 'uuid_required');
        $this->assertBooksAgree($cid);
    }

    public function test_refusals_are_in_the_apis_normal_form_and_money_is_never_rounded(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $lease = $w['lease'];
        // Framework validation: the envelope with the field named.
        $res = $this->postJson('/api/v1/rentals/leases', [], $h)->assertStatus(422);
        $this->assertSame(0, $res->json('code'));
        $this->assertArrayHasKey('unit_id', $res->json('errors'));
        // A rule: the envelope with its code.
        $res = $this->postJson('/api/v1/rentals/units', ['property_id' => $w['p']->id], $h)->assertStatus(422);
        $this->assertSame([0, 'invalid', null], [$res->json('code'), $res->json('errors.code'), $res->json('data')]);
        $this->assertArrayHasKey('code', $res->json('errors.errors'));

        // Decimals and words are refused for money, never rounded or read as zero.
        foreach ([1000.5, '1000.5', 'abc', -5, 0, ['x'], '1e5'] as $bad) {
            foreach ([['payments', ['lease_id' => $lease->id, 'method' => 'cash']], ['charges', ['lease_id' => $lease->id, 'description' => 'x']], ['payouts', ['landlord_id' => $w['p']->landlord_id, 'method' => 'cash']],
                ['expenses', ['property_id' => $w['p']->id, 'category' => 'Repairs', 'method' => 'cash']], ['cash/transfers', ['from' => 'cash', 'to' => 'bank']],
                ["leases/{$lease->id}/items", ['name' => 'Fee']], ["charges/{$w['charge']->id}/waive", ['reason' => 'x']]] as [$path, $b]) {
                $this->postJson("/api/v1/rentals/{$path}", $b + ['amount_minor' => $bad], $h)->assertStatus(422)->assertJsonPath('errors.code', 'amount_invalid');
            }
            $this->postJson("/api/v1/rentals/leases/{$lease->id}/rent-change", ['rent_minor' => $bad, 'effective_from' => '2026-06-01'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'amount_invalid');
        }
        foreach ([1000.5, 'abc', -1] as $bad) {
            $this->postJson('/api/v1/rentals/leases', ['unit_id' => $w['units'][1]->id, 'tenant' => ['name' => 'X'], 'start_date' => '2026-03-01', 'deposit_minor' => $bad], $h)
                ->assertStatus(422)->assertJsonPath('errors.code', 'amount_invalid');
            $this->postJson('/api/v1/rentals/leases', ['unit_id' => $w['units'][1]->id, 'tenant' => ['name' => 'X'], 'start_date' => '2026-03-01', 'commission' => ['type' => 'percent', 'bp' => $bad]], $h)
                ->assertStatus(422)->assertJsonPath('errors.code', 'amount_invalid');
            $this->putJson("/api/v1/rentals/maintenance/{$w['ticket']->id}", ['status' => 'done', 'cost_minor' => $bad], $h)->assertStatus(422)->assertJsonPath('errors.code', 'amount_invalid');
        }
        $this->assertSame(0, RentalLease::where('unit_id', $w['units'][1]->id)->count(), 'a bad deposit moved nobody in');

        // Dates: nonsense and days that do not exist are refused, never moved to another day.
        foreach (['banana', '2026-02-31', '31/12/2026'] as $bad) {
            $this->postJson('/api/v1/rentals/payments', ['lease_id' => $lease->id, 'amount_minor' => 1000, 'method' => 'cash', 'paid_on' => $bad], $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_invalid');
            $this->postJson('/api/v1/rentals/payouts', ['landlord_id' => $w['p']->landlord_id, 'amount_minor' => 1000, 'method' => 'cash', 'paid_on' => $bad], $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_invalid');
            $this->postJson('/api/v1/rentals/meter-readings', ['unit_id' => $w['units'][0]->id, 'utility' => 'water', 'reading' => '5', 'read_on' => $bad], $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_invalid');
            $this->postJson('/api/v1/rentals/leases', ['unit_id' => $w['units'][1]->id, 'tenant' => ['name' => 'X'], 'start_date' => $bad], $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_invalid');
            $this->postJson("/api/v1/rentals/leases/{$lease->id}/move-out", ['date' => $bad], $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_invalid');
            $this->getJson("/api/v1/rentals/payments?from={$bad}", $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_invalid');
        }
        $this->postJson('/api/v1/rentals/payments', ['lease_id' => $lease->id, 'amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-03-02'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_in_future');
        $this->postJson('/api/v1/rentals/payments', ['lease_id' => $lease->id, 'amount_minor' => 1000, 'method' => 'gold'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'method_invalid');
        $this->postJson('/api/v1/rentals/payments', ['lease_id' => $lease->id, 'amount_minor' => 1000, 'method' => 'deposit'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'method_invalid');
        $this->postJson('/api/v1/rentals/payments', ['lease_id' => $lease->id, 'amount_minor' => 1000, 'method' => 'cash', 'uuid' => 'nope'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'uuid_invalid');

        // The blueprint's codes.
        $this->postJson('/api/v1/rentals/leases', ['unit_id' => $w['units'][0]->id, 'tenant' => ['name' => 'X'], 'start_date' => '2026-03-01'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'unit_taken');
        $this->postJson("/api/v1/rentals/payments/{$w['payment']->id}/reverse", [], $h)->assertStatus(422)->assertJsonPath('errors.code', 'reason_required');
        $this->postJson("/api/v1/rentals/charges/{$w['charge']->id}/void", ['reason' => 'x'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'charge_paid');
        $this->postJson("/api/v1/rentals/charges/{$w['charge']->id}/waive", ['amount_minor' => 1000, 'reason' => 'x'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'nothing_to_waive');
        $this->postJson('/api/v1/rentals/payouts', ['landlord_id' => $w['p']->landlord_id, 'amount_minor' => 99000000, 'method' => 'cash'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'payout_above_balance');
        $this->postJson('/api/v1/rentals/cash/transfers', ['from' => 'bank', 'to' => 'cash', 'amount_minor' => 1000], $h)->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_cash');
        $this->putJson('/api/v1/rentals/settings', ['currency' => 'USD'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'currency_locked');
        // A list asked for too much is capped, and a person typing is never an error.
        $this->assertSame(100, $this->getJson('/api/v1/rentals/payments?per_page=5000', $h)->assertOk()->json('meta.pagination.per_page'));
        $this->getJson('/api/v1/rentals/tenants?q='.urlencode("%' OR 1=1 -- _"), $h)->assertOk()->assertJsonPath('data', []);
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_nonsense_in_a_field_never_crashes_the_api(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $junk = ['reason' => ['x'], 'method' => ['x'], 'paid_on' => ['x'], 'notes' => ['x'], 'reference' => ['x'], 'description' => ['x'], 'title' => ['x'], 'name' => ['x'], 'channel' => ['x'], 'note' => ['x'],
            'from' => ['x'], 'to' => ['x'], 'on' => ['x'], 'date' => ['x'], 'status' => ['x'], 'priority' => ['x'], 'category' => ['x'], 'utility' => ['x'], 'reading' => ['x'], 'lease_id' => ['x'],
            'uuid' => ['x'], 'kind' => ['x'], 'goes_to' => ['x'], 'email' => ['x'], 'phone' => ['x'], 'password' => ['x'], 'user_ids' => 'x', 'file_uuid' => ['x'], 'owner_type' => ['x']];
        foreach ($this->staffRoutes() as [$m, $uri]) {
            if ($m === 'GET') {
                continue;
            }
            DB::beginTransaction();
            $res = $this->json($m, $this->path($uri, $w), $junk, $h);
            DB::rollBack();
            $this->assertLessThan(500, $res->getStatusCode(), "{$m} {$uri}: ".substr((string) $res->getContent(), 0, 200));
        }
    }

    public function test_the_directory_endpoints_and_their_shapes(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $h = $this->h($owner);
        $api = '/api/v1/rentals';

        $this->getJson("{$api}/settings", $h)->assertOk()->assertJsonPath('data.mode', 'agency')->assertJsonStructure(['data' => ['lists' => ['unit_kinds', 'methods', 'expense_categories']]]);
        $this->putJson("{$api}/settings", ['payment_instructions' => 'MoMo 0772 000000', 'grace_days' => 3], $h)->assertOk()->assertJsonPath('data.grace_days', 3);
        $this->putJson("{$api}/settings", ['grace_days' => 500], $h)->assertStatus(422);

        $landlord = $this->postJson("{$api}/landlords", ['name' => 'Okello', 'phone' => '0772123456', 'email' => 'okello@example.com', 'id_number' => 'CM9012345678AB', 'payout_method' => 'bank'], $h)->assertStatus(201)->json('data');
        $this->assertShape($landlord, self::LANDLORD, 'Landlord');
        $this->assertArrayNotHasKey('id_number', $landlord);
        $this->putJson("{$api}/landlords/{$landlord['id']}", ['address' => 'Ntinda'], $h)->assertOk()->assertJsonPath('data.address', 'Ntinda');
        $this->assertSame(1, $this->getJson("{$api}/landlords?q=Okello", $h)->assertOk()->json('meta.pagination.total'));

        $property = $this->postJson("{$api}/properties", ['name' => 'Ntinda Court', 'landlord_id' => $landlord['id'], 'water_rate_minor' => 2000], $h)->assertStatus(201)->json('data');
        $this->assertShape($property, self::PROPERTY, 'Property');
        $this->assertSame(['id' => $landlord['id'], 'name' => 'Okello'], $property['landlord']);
        $this->getJson("{$api}/properties/{$property['id']}", $h)->assertOk();
        $this->putJson("{$api}/properties/{$property['id']}", ['area' => 'Kampala'], $h)->assertOk()->assertJsonPath('data.area', 'Kampala');

        $unit = $this->postJson("{$api}/units", ['property_id' => $property['id'], 'code' => 'A1', 'rent_minor' => 300000, 'kind' => 'apartment'], $h)->assertStatus(201)->json('data');
        $this->assertShape($unit, self::UNIT, 'Unit');
        $this->assertSame('vacant', $unit['state']);
        $second = $this->postJson("{$api}/units", ['property_id' => $property['id'], 'code' => 'A2', 'rent_minor' => 200000], $h)->assertStatus(201)->json('data');
        $this->postJson("{$api}/units", ['property_id' => $property['id'], 'code' => 'A1', 'rent_minor' => 1], $h)->assertStatus(422)->assertJsonPath('errors.code', 'code_taken');
        $this->putJson("{$api}/units/{$second['id']}", ['status' => 'maintenance'], $h)->assertOk()->assertJsonPath('data.state', 'maintenance');

        $tenant = $this->postJson("{$api}/tenants", ['name' => 'Asha Nakato', 'phone' => '0701123456', 'id_type' => 'national_id', 'id_number' => 'CF90123456789Z', 'email' => 'asha@example.com'], $h)->assertStatus(201)->json('data');
        $this->assertShape($tenant, self::TENANT, 'Tenant');
        $this->assertSame('CF••••••••789Z', $tenant['id_number_masked']);
        $this->assertArrayNotHasKey('id_number', $tenant);
        $this->assertStringNotContainsString('CF90123456789Z', (string) json_encode($this->getJson("{$api}/tenants/{$tenant['id']}", $h)->json()));
        $this->postJson("{$api}/tenants", ['name' => 'Someone', 'phone' => '0701123456'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'tenant_exists');
        $this->putJson("{$api}/tenants/{$tenant['id']}", ['occupation' => 'Teacher'], $h)->assertOk()->assertJsonPath('data.occupation', 'Teacher');
        $this->postJson("{$api}/tenants/{$tenant['id']}/login", ['email' => 'asha@example.com'], $h)->assertOk()->assertJsonPath('data.has_login', true);
        $this->postJson("{$api}/landlords/{$landlord['id']}/login", ['email' => 'okello@example.com'], $h)->assertOk()->assertJsonPath('data.login.enabled', true);
        $this->postJson("{$api}/landlords/{$landlord['id']}/login", ['enabled' => false], $h)->assertOk()->assertJsonPath('data.has_login', false);

        $staff = $this->rentalsStaff($company, 'rentals_caretaker');
        $this->putJson("{$api}/properties/{$property['id']}/staff", ['user_ids' => [$staff->id]], $h)->assertOk()->assertJsonPath('data.staff.0.user_id', $staff->id);
        $this->putJson("{$api}/properties/{$property['id']}/staff", ['user_ids' => [999999]], $h)->assertStatus(422);

        // Moving in by id, and the lease's shape.
        $lease = $this->postJson("{$api}/leases", ['unit_id' => $unit['id'], 'tenant_id' => $tenant['id'], 'start_date' => '2026-03-01', 'rent_minor' => 300000, 'deposit_minor' => 300000,
            'items' => [['name' => 'Garbage', 'amount_minor' => 10000, 'goes_to' => 'agency']], 'first_payment' => ['amount_minor' => 310000, 'method' => 'mobile_money', 'reference' => 'MM1']], $h)->assertStatus(201)->json('data');
        $this->assertShape($lease, [...self::LEASE_BRIEF, ...self::LEASE], 'Lease');
        $this->assertShape($lease['position'], self::POSITION, 'Position');
        $this->assertSame(['id' => $tenant['id'], 'name' => 'Asha Nakato', 'phone' => '0701123456'], $lease['tenant']);
        $this->assertShape($lease['charges'][0], self::CHARGE, 'Charge');
        $this->assertShape($lease['items'][0], ['id', 'name', 'amount_minor', 'goes_to', 'commissionable', 'starts_on', 'ends_on', 'active'], 'Item');
        $this->assertShape($lease['rent_changes'][0], ['rent_minor', 'effective_from', 'reason'], 'rent change');
        $this->assertShape($lease['payments'][0], self::PAYMENT_BRIEF, 'PaymentBrief');
        $this->assertSame(['type' => 'percent', 'bp' => 1000, 'minor' => 0], $lease['commission']);
        $this->assertIsInt($lease['position']['owed_minor']);

        // The state filters.
        foreach ([['occupied', []], ['overdue', []], ['vacant', []], ['maintenance', [$second['id']]], ['notice', []]] as [$state, $ids]) {
            $got = array_column($this->getJson("{$api}/units?state={$state}", $h)->assertOk()->json('data'), 'id');
            if ($state === 'occupied') {
                $this->assertSame([$unit['id']], $got);
            } else {
                $this->assertSame($ids, $got, $state);
            }
        }
        $this->assertSame($lease['id'], $this->getJson("{$api}/units/{$unit['id']}", $h)->assertOk()->json('data.lease.id'));
        $this->assertSame(1, $this->getJson("{$api}/leases?q=Asha&status=live", $h)->assertOk()->json('meta.pagination.total'));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_the_money_endpoints_and_their_shapes(): void
    {
        $w = $this->world();
        $cid = (int) $w['company']->id;
        $h = $this->h($w['owner']);
        $api = '/api/v1/rentals';
        $lease = $w['lease'];

        $o = $this->getJson("{$api}/overview", $h)->assertOk()->json('data');
        $this->assertShape($o, self::OVERVIEW, 'Overview');
        $this->assertShape($o['arrears'], ['total_minor', 'leases', 'aging'], 'arrears');
        $this->assertSame(['d0_30', 'd31_60', 'd61_90', 'd90_plus'], array_keys($o['arrears']['aging']));
        $this->assertSame(['cash', 'bank', 'mobile_money', 'cheque', 'total'], array_keys($o['cash']));
        $this->assertSame(['billed_minor', 'collected_minor', 'rate_pct'], array_keys($o['month']));
        $this->assertSame(['id', 'number', 'status'], array_slice(array_keys($this->getJson("{$api}/leases", $h)->json('data.0')), 0, 3));
        $this->assertShape($this->getJson("{$api}/leases", $h)->json('data.0'), self::LEASE_BRIEF, 'LeaseBrief');
        $this->assertShape($this->getJson("{$api}/charges", $h)->json('data.0'), self::CHARGE, 'Charge');

        // A payment: its split, receipt and signed link.
        $preview = $this->getJson("{$api}/leases/{$lease->id}/payment-preview?amount_minor=1000", $h)->assertOk()->json('data');
        $this->assertArrayHasKey('credit_minor', $preview);
        $p = $this->postJson("{$api}/payments", ['lease_id' => $w['out']->id, 'amount_minor' => 100000, 'method' => 'bank', 'reference' => 'BK9', 'notes' => 'Rent'], $h)->assertStatus(201)->json('data');
        $this->assertShape($p, [...self::PAYMENT_BRIEF, ...self::PAYMENT], 'Payment');
        $this->assertSame(['text', 'pdf_url'], array_keys($p['receipt']));
        $this->assertSame('web', $p['channel']);
        $this->assertSame('app', $this->postJson("{$api}/payments", ['lease_id' => $w['out']->id, 'amount_minor' => 1000, 'method' => 'cash', 'uuid' => (string) Str::uuid()], $h + ['X-App-Version' => '9.9.9'])->assertStatus(201)->json('data.channel'));
        $this->getJson("{$api}/payments/{$p['id']}", $h)->assertOk()->assertJsonPath('data.number', $p['number']);
        $this->getJson("{$api}/payments/{$p['id']}/receipt", $h)->assertOk()->assertJsonStructure(['data' => ['text', 'pdf_url']]);
        $this->assertSame(1, $this->getJson("{$api}/payments?method=bank", $h)->assertOk()->json('meta.pagination.total'));
        $this->assertSame(1, $this->getJson("{$api}/payments?q={$p['number']}", $h)->assertOk()->json('meta.pagination.total'));
        $this->postJson("{$api}/payments/{$p['id']}/reverse", ['reason' => 'Bounced'], $h)->assertOk()->assertJsonPath('data.reversed', true);
        $this->postJson("{$api}/payments/{$p['id']}/reverse", ['reason' => 'Again'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'already_reversed');

        // Bills.
        $c = $this->postJson("{$api}/charges", ['lease_id' => $lease->id, 'description' => 'Key', 'amount_minor' => 5000, 'goes_to' => 'agency', 'due_date' => '2026-03-01'], $h)->assertStatus(201)->json('data');
        $this->assertShape($c, self::CHARGE, 'Charge');
        $this->assertSame('paid', $c['status'], 'the credit settled it at once');
        $this->postJson("{$api}/charges/{$c['id']}/void", ['reason' => 'Mistake'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'charge_paid');
        $third = $this->tenantIn($w['owner'], $w['units'][2], '2026-03-01', ['deposit_minor' => 0]);
        $open = $this->postJson("{$api}/charges", ['lease_id' => $third->id, 'description' => 'Key', 'amount_minor' => 5000], $h)->assertStatus(201)->json('data');
        $this->postJson("{$api}/charges/{$open['id']}/void", ['reason' => 'Mistake'], $h)->assertOk()->assertJsonPath('data.status', 'void');
        $this->postJson("{$api}/charges/{$open['id']}/void", ['reason' => 'Again'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'charge_void');
        $this->assertSame(1, $this->postJson("{$api}/billing/run", [], $h)->assertOk()->json('code'));
        $this->assertNotEmpty($this->getJson("{$api}/charges?lease_id={$lease->id}&kind=rent", $h)->assertOk()->json('data'));
        $this->getJson("{$api}/charges?kind=rent&status=open&from=2026-01-01&to=2026-12-31&overdue=1", $h)->assertOk();

        // Terms of a lease.
        $item = $this->postJson("{$api}/leases/{$lease->id}/items", ['name' => 'Security', 'amount_minor' => 7000], $h)->assertStatus(201)->json('data');
        $this->postJson("{$api}/leases/{$lease->id}/items/{$item['id']}/end", ['ends_on' => '2026-03-31'], $h)->assertOk()->assertJsonPath('data.ends_on', '2026-03-31');
        $this->postJson("{$api}/leases/{$lease->id}/rent-change", ['rent_minor' => 350000, 'effective_from' => '2026-05-01', 'reason' => 'Review'], $h)->assertOk()->assertJsonPath('data.rent_changes.1.rent_minor', 350000);
        $this->postJson("{$api}/leases/{$lease->id}/rent-change", ['rent_minor' => 350000, 'effective_from' => '2026-03-15'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'period_billed');
        $this->postJson("{$api}/leases/{$lease->id}/notice", ['move_out_on' => '2026-05-31'], $h)->assertOk()->assertJsonPath('data.status', 'notice');
        $this->postJson("{$api}/leases/{$lease->id}/withdraw-notice", [], $h)->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("{$api}/leases/{$lease->id}/renew", ['end_date' => '2027-02-28'], $h)->assertOk()->assertJsonPath('data.end_date', '2027-02-28');

        // Arrears, follow-ups, reminders with no message sent.
        $ar = $this->getJson("{$api}/arrears", $h)->assertOk();
        $this->assertArrayHasKey('aging', $ar->json('meta'));
        $f = $this->postJson("{$api}/follow-ups", ['lease_id' => $lease->id, 'channel' => 'call', 'note' => 'Called', 'promise_on' => '2026-03-05'], $h)->assertStatus(201)->json('data');
        $this->assertShape($f, ['id', 'channel', 'note', 'owed_minor', 'promise_on', 'by', 'created_at'], 'FollowUp');
        $this->getJson("{$api}/follow-ups?lease_id={$lease->id}", $h)->assertOk()->assertJsonPath('data.0.id', $f['id']);
        $this->postJson("{$api}/follow-ups", ['lease_id' => $lease->id, 'channel' => 'pigeon'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'channel_invalid');
        $r = $this->getJson("{$api}/leases/{$lease->id}/reminder", $h)->assertOk()->json('data');
        $this->assertStringStartsWith('https://wa.me/', (string) $r['whatsapp']);
        $this->assertStringStartsWith('sms:', (string) $r['sms']);

        // Money out.
        $payout = $this->postJson("{$api}/payouts", ['landlord_id' => $w['p']->landlord_id, 'amount_minor' => 2000, 'method' => 'cash', 'reference' => 'V1'], $h)->assertStatus(201)->json('data');
        $this->assertShape($payout, self::PAYOUT, 'Payout');
        $this->postJson("{$api}/payouts/{$payout['id']}/reverse", ['reason' => 'x'], $h)->assertOk()->assertJsonPath('data.reversed', true);
        $this->assertNotEmpty($this->getJson("{$api}/payouts?landlord_id={$w['p']->landlord_id}", $h)->assertOk()->json('data'));
        $e = $this->postJson("{$api}/expenses", ['property_id' => $w['p']->id, 'category' => 'Cleaning', 'amount_minor' => 2000, 'method' => 'cash', 'vendor' => 'Bosco'], $h)->assertStatus(201)->json('data');
        $this->assertShape($e, self::EXPENSE, 'Expense');
        $this->postJson("{$api}/expenses/{$e['id']}/reverse", ['reason' => 'x'], $h)->assertOk()->assertJsonPath('data.reversed', true);
        $this->assertArrayHasKey('categories', $this->getJson("{$api}/expenses", $h)->assertOk()->json('meta'));
        $cash = $this->getJson("{$api}/cash?from=2026-03-01&to=2026-03-31", $h)->assertOk()->json('data');
        $this->assertShape($cash, ['currency', 'balances', 'from', 'to', 'opening_minor', 'closing_minor', 'in_minor', 'out_minor', 'rows'], 'cash');
        $this->assertSame($cash['balances']['total'], $cash['closing_minor']);
        $this->postJson("{$api}/cash/transfers", ['from' => 'cash', 'to' => 'bank', 'amount_minor' => 3000, 'note' => 'Banking'], $h)->assertStatus(201)->assertJsonPath('data.balances.bank', 3000);

        // Repairs, meters.
        $uuid = (string) Str::uuid();
        $m = $this->postJson("{$api}/maintenance", ['uuid' => $uuid, 'unit_id' => $w['units'][0]->id, 'title' => 'Roof', 'priority' => 'urgent'], $h)->assertStatus(201)->json('data');
        $this->assertShape($m, self::MAINTENANCE, 'Maintenance');
        $this->postJson("{$api}/maintenance", ['uuid' => $uuid, 'unit_id' => $w['units'][0]->id, 'title' => 'Roof'], $h)->assertOk()->assertJsonPath('data.id', $m['id']);
        $this->postJson("{$api}/maintenance", ['uuid' => $uuid, 'unit_id' => $w['units'][0]->id, 'title' => 'Other'], $h)->assertStatus(409);
        $this->postJson("{$api}/maintenance", ['unit_id' => $w['units'][0]->id, 'title' => 'Other', 'assigned_to_id' => 999999], $h)->assertStatus(404);
        $this->putJson("{$api}/maintenance/{$m['id']}", ['status' => 'in_progress', 'assignee_name' => 'Fundi Joe'], $h)->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->getJson("{$api}/maintenance?status=in_progress&priority=urgent", $h)->assertOk()->assertJsonPath('data.0.id', $m['id']);
        $this->putJson("{$api}/maintenance/{$m['id']}", ['status' => 'done', 'cost_minor' => 1500, 'resolution' => 'Fixed', 'charged_to' => 'landlord'], $h)->assertOk()->assertJsonPath('data.cost_minor', 1500);
        $this->putJson("{$api}/maintenance/{$m['id']}", ['status' => 'in_progress'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'ticket_closed');
        $this->putJson("{$api}/maintenance/{$w['ticket']->id}", ['status' => 'cancelled', 'reason' => 'No longer'], $h)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $uuid2 = (string) Str::uuid();
        $read = $this->postJson("{$api}/meter-readings", ['uuid' => $uuid2, 'unit_id' => $w['units'][0]->id, 'utility' => 'water', 'reading' => '100'], $h)->assertStatus(201)->json('data');
        $this->assertShape($read, self::READING, 'MeterReading');
        $this->postJson("{$api}/meter-readings", ['uuid' => $uuid2, 'unit_id' => $w['units'][0]->id, 'utility' => 'water', 'reading' => '100'], $h)->assertOk()->assertJsonPath('data.id', $read['id']);
        $this->postJson("{$api}/meter-readings", ['uuid' => $uuid2, 'unit_id' => $w['units'][0]->id, 'utility' => 'water', 'reading' => '101'], $h)->assertStatus(409);
        $this->postJson("{$api}/meter-readings", ['unit_id' => $w['units'][0]->id, 'utility' => 'water', 'reading' => '90'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'reading_lower');
        $this->assertNotEmpty($this->getJson("{$api}/meter-readings?unit_id={$w['units'][0]->id}&utility=water", $h)->assertOk()->json('data'));

        // Reports registry, a statement, the audit trail.
        $this->assertSame([], array_diff(['rent_roll', 'collections', 'arrears_aging', 'occupancy', 'landlord_statement', 'commission', 'expenses', 'deposits_held', 'cash_book', 'tenant_statement', 'lease_expiries', 'maintenance', 'income_statement'], array_column($this->getJson("{$api}/reports", $h)->assertOk()->json('data'), 'key')));
        $st = $this->getJson("{$api}/landlords/{$w['p']->landlord_id}/statement?from=2026-03-01&to=2026-03-31", $h)->assertOk()->json('data');
        $this->assertShape($st, self::STATEMENT, 'Statement');
        $this->assertSame(['collected_minor', 'commission_minor', 'expenses_minor', 'payouts_minor', 'deposits_minor', 'other_minor'], array_keys($st['totals']));
        $this->assertShape($st['lines'][0], ['date', 'type', 'description', 'property', 'unit', 'tenant', 'gross_minor', 'commission_minor', 'amount_minor'], 'line');
        $this->assertStringContainsString('signature=', (string) $this->getJson("{$api}/landlords/{$w['p']->landlord_id}/statement?from=2026-03-01&to=2026-03-31&format=pdf", $h)->assertOk()->json('data.url'));
        $this->getJson("{$api}/landlords/{$w['p']->landlord_id}/statement?from=2026-03-31&to=2026-03-01", $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_invalid');
        $this->assertNotEmpty($this->getJson("{$api}/activity?action=payment.", $h)->assertOk()->json('data'));

        // Moving out, cancel.
        Carbon::setTestNow('2026-03-02 09:00:00');
        $h = $this->h($w['owner']);
        $out = $this->postJson("{$api}/leases/{$lease->id}/move-out", ['date' => '2026-03-02', 'unused_days' => true, 'damages' => [['description' => 'Door', 'amount_minor' => 1000]], 'refund_deposit' => 'cash', 'refund_credit' => 'cash', 'reason' => 'Left'], $h)->assertOk()->json('data');
        $this->assertSame(['lease', 'settlement'], array_keys($out));
        $this->assertSame('ended', $out['lease']['status']);
        $this->postJson("{$api}/leases/{$lease->id}/move-out", ['date' => '2026-03-02'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'lease_not_live');
        $this->postJson("{$api}/leases/{$lease->id}/cancel", [], $h)->assertStatus(422)->assertJsonPath('errors.code', 'lease_not_live');
        $fresh = $this->tenantIn($w['owner'], $w['units'][1], '2026-03-02', ['deposit_minor' => 0]);
        $this->postJson("{$api}/leases/{$fresh->id}/cancel", ['reason' => 'Typed wrong'], $h)->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Setup, by the owner only.
        $this->postJson("{$api}/setup", ['mode' => 'agency'], $h)->assertOk()->assertJsonPath('data.set_up', true);
        $this->assertBooksAgree($cid);
    }
}
