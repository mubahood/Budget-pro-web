<?php

namespace Tests\Feature\Rentals;

use App\Models\Plan;
use App\Models\Subscription;
use Database\Seeders\EstateLedgerPlanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * One agency's whole life over HTTP: the setup, a building and its doors, landlords and tenants, every kind of money, a repair above a limit, a promise kept, advances, a payout run, a month
 * closed, its statements, a handover, a move-out with the deposit, a year's summary. After each stage the books check clean and every screen that shows the same number shows the same number.
 * Two figures that claim to be one number and differ are a bug. Nothing here calls a service: what a phone can do is all it does.
 */
class RentalsApiLifecycleTest extends RentalsPhoneApiCase
{
    private const API = '/api/v1/rentals/';

    /** @var array<string, string> */
    private array $h = [];

    private int $cid = 0;

    /** @var array<string, mixed> */
    private array $ids = [];

    private function send(string $path, array $body = [], int $want = 0, ?array $headers = null): mixed
    {
        $res = $this->postJson(self::API.$path, $body, $headers ?? $this->h);
        $code = $res->getStatusCode();
        $this->assertTrue($want !== 0 ? $code === $want : $code < 300, "POST {$path}: {$code} ".substr((string) $res->getContent(), 0, 400));

        return $res->json('data');
    }

    private function amend(string $path, array $body = []): mixed
    {
        $res = $this->putJson(self::API.$path, $body, $this->h);
        $this->assertLessThan(300, $res->getStatusCode(), "PUT {$path}: ".substr((string) $res->getContent(), 0, 400));

        return $res->json('data');
    }

    private function read(string $path, ?array $headers = null): mixed
    {
        $res = $this->getJson(self::API.$path, $headers ?? $this->h);
        $this->assertSame(200, $res->getStatusCode(), "GET {$path}: ".substr((string) $res->getContent(), 0, 400));

        return $res->json('data');
    }

    private function on(string $day): void
    {
        Carbon::setTestNow($day.' 09:00:00');
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    private function lease(string $who): mixed
    {
        return $this->read('leases/'.$this->ids[$who]);
    }

    /**
     * Whatever the stage: rentals:check is clean, and every figure that claims to be the same number is.
     *
     * @param  string  $stage  what just happened, for the message
     */
    private function agrees(string $stage): void
    {
        $this->assertSame(0, Artisan::call('rentals:check', ['--company' => $this->cid]), "{$stage}: rentals:check: ".Artisan::output());
        $o = $this->read('overview');
        $today = $this->read('today');
        $arrears = $this->getJson(self::API.'arrears?per_page=100', $this->h)->assertOk()->json();
        $cash = $this->read('cash');
        $money = $this->read('money-position');
        $book = $this->read('rent-book');
        $landlords = $this->read('landlords?per_page=100');
        $properties = $this->read('properties?per_page=100&status=active');
        $leases = $this->read('leases?per_page=100&status=active,notice,ended');
        $tenants = $this->read('tenants?per_page=100');
        $thisMonth = now()->format('Y-m');
        $monthEnd = $this->read('month-end?month='.$thisMonth);

        // Arrears: overview, Today (old and new), the arrears screen's total and its rows.
        $total = (int) $o['arrears']['total_minor'];
        $this->assertSame($total, (int) $today['figures']['arrears']['total_minor'], "{$stage}: arrears on Today's figures");
        $this->assertSame($total, (int) $today['board']['arrears']['total_minor'], "{$stage}: arrears on Today's board");
        $this->assertSame($total, (int) $arrears['meta']['aging']['total_minor'], "{$stage}: arrears' own total");
        $this->assertSame($total, (int) array_sum(array_column($arrears['data'], 'overdue_minor')), "{$stage}: arrears' rows");
        $this->assertSame($total, (int) array_sum($o['arrears']['aging']), "{$stage}: arrears' buckets");
        $this->assertSame($total, (int) array_sum(array_column($properties, 'arrears_minor')), "{$stage}: arrears by property");
        $this->assertSame((int) $o['arrears']['leases'], count($arrears['data']), "{$stage}: leases in arrears");

        // Cash: overview, the cash book, whose money, month end's last-day figure (this month is still running, so it is today's).
        $this->assertSame((int) $o['cash']['total'], (int) $cash['balances']['total'], "{$stage}: cash on the overview and the cash book");
        $this->assertSame((int) $o['cash']['total'], (int) $money['cash_minor'], "{$stage}: cash on the overview and whose money");
        $this->assertSame((int) $o['cash']['total'], (int) $today['figures']['cash']['total'], "{$stage}: cash on Today");
        $this->assertSame((int) $cash['balances']['total'], (int) $monthEnd['figures']['totals']['cash_total'], "{$stage}: cash at month end");
        foreach (['cash', 'bank', 'mobile_money', 'cheque'] as $m) {
            $this->assertSame((int) $cash['balances'][$m], (int) $monthEnd['figures']['totals']['cash'][$m], "{$stage}: {$m} at month end");
        }
        $this->assertSame((int) $cash['closing_minor'], (int) $cash['balances']['total'], "{$stage}: the cash book's closing");

        // What the money is: it adds up, on every screen that says so.
        $this->assertSame((int) $money['cash_minor'], (int) $money['owed_to_landlords_minor'] - (int) $money['owed_back_by_landlords_minor'] + (int) $money['deposits_minor'] + (int) $money['credit_minor'] + (int) $money['agency_own_minor'], "{$stage}: whose money adds up");
        $this->assertSame((int) $o['deposits_minor'], (int) $money['deposits_minor'], "{$stage}: deposits on the overview and whose money");
        $this->assertSame((int) $o['deposits_minor'], (int) $today['board']['deposits_minor'], "{$stage}: deposits on Today");
        $this->assertSame((int) $o['deposits_minor'], (int) $monthEnd['figures']['totals']['deposits'], "{$stage}: deposits at month end");
        $this->assertSame((int) $o['credit_minor'], (int) $money['credit_minor'], "{$stage}: credit on the overview and whose money");
        $this->assertSame((int) $o['credit_minor'], (int) $today['board']['credit_minor'], "{$stage}: credit on Today");
        $this->assertSame((int) $o['credit_minor'], (int) $monthEnd['figures']['totals']['credit'], "{$stage}: credit at month end");
        $this->assertSame((int) $money['agency_own_minor'], (int) $monthEnd['figures']['totals']['agency_own'], "{$stage}: the agency's own at month end");
        $this->assertSame((int) $money['agency_own_minor'], (int) $monthEnd['look_at']['agency_own_minor'], "{$stage}: the agency's own to look at");

        // Landlords: each one's balance is the journal's, the statement's and month end's; together they are the overview's.
        $this->assertSame((int) $o['landlords_minor'], (int) array_sum(array_column($landlords, 'balance_minor')), "{$stage}: landlords on the overview and their list");
        $this->assertSame((int) $o['landlords_minor'], (int) $money['owed_to_landlords_minor'] - (int) $money['owed_back_by_landlords_minor'], "{$stage}: landlords on the overview and whose money");
        $this->assertSame((int) $money['owed_to_landlords_minor'], (int) $today['board']['landlords_owed_minor'], "{$stage}: owed to landlords on Today");
        $this->assertSame((int) $money['owed_back_by_landlords_minor'], (int) $today['board']['landlords_owe_minor'], "{$stage}: owed back by landlords on Today");
        $this->assertSame((int) $money['owed_to_landlords_minor'], (int) $monthEnd['figures']['totals']['owed_to_landlords'], "{$stage}: owed to landlords at month end");
        $rows = collect($monthEnd['figures']['rows'])->keyBy('landlord_id');
        foreach ($landlords as $l) {
            $id = (int) $l['id'];
            $st = $this->read("landlords/{$id}/statement?from=".$thisMonth.'-01&to='.now()->toDateString());
            $this->assertSame((int) $l['balance_minor'], (int) $st['closing_minor'], "{$stage}: {$l['name']}: their balance and their statement");
            $this->assertSame((int) $l['balance_minor'], (int) ($rows[$id]['closing_minor'] ?? 0), "{$stage}: {$l['name']}: their balance and month end");
            if (isset($rows[$id])) {
                foreach (['collected_minor', 'commission_minor', 'expenses_minor', 'payouts_minor', 'deposits_minor', 'other_minor'] as $k) {
                    $this->assertSame((int) $st['totals'][$k], (int) $rows[$id][$k], "{$stage}: {$l['name']}: {$k} on the statement and at month end");
                }
                $this->assertSame((int) $st['opening_minor'], (int) $rows[$id]['opening_minor'], "{$stage}: {$l['name']}: opening");
            }
            $sheet = $this->read("landlords/{$id}/sheet?month=".$thisMonth);
            $this->assertSame((int) array_sum(array_column($sheet['rows'], 'paid_minor')), (int) $sheet['totals']['paid_minor'], "{$stage}: {$l['name']}: the sheet adds up");
            $this->assertSame((int) array_sum(array_column($sheet['rows'], 'owed_minor')), (int) $sheet['totals']['owed_minor'], "{$stage}: {$l['name']}: the sheet's owed adds up");
            // The sheet's owed is what is still open of the bills that go to this landlord, due by the month's last day.
            $open = 0;
            foreach ($this->pagesOf('charges?status=open') as $c) {
                if ((int) $c['landlord_id_for_test'] === $id && $c['goes_to'] === 'landlord' && $c['due_date'] <= $sheet['as_of']) {
                    $open += (int) $c['left_minor'];
                }
            }
            $this->assertSame($open, (int) $sheet['totals']['owed_minor'], "{$stage}: {$l['name']}: the sheet's owed and the open bills");
        }

        // A lease's position, on its own, in the book, on its tenant, and as the sum of its bills.
        $byId = collect($book['leases'])->keyBy('id');
        foreach ($leases as $l) {
            $id = (int) $l['id'];
            if (isset($byId[$id])) {
                $this->assertSame($l['position'], $byId[$id]['position'], "{$stage}: lease {$l['number']} in the rent book");
            }
            $open = 0;
            foreach ($this->pagesOf("charges?lease_id={$id}&status=open") as $c) {
                $open += $c['kind'] === 'refund' ? 0 : (int) $c['left_minor'];
            }
            $this->assertSame((int) $l['position']['owed_minor'], $open, "{$stage}: lease {$l['number']}: position and open bills");
            $this->assertSame($l['position'], $this->read("leases/{$id}")['position'], "{$stage}: lease {$l['number']}: list and record");
        }
        foreach ($tenants as $t) {
            $sum = 0;
            $credit = 0;
            foreach ($leases as $l) {
                if ((int) $l['tenant']['id'] === (int) $t['id']) {
                    $sum += (int) $l['position']['owed_minor'];
                    $credit += (int) $l['position']['credit_minor'];
                }
            }
            $this->assertSame($sum, (int) $t['owed_minor'], "{$stage}: {$t['name']}: owed on the tenant and their leases");
            $this->assertSame($credit, (int) $t['credit_minor'], "{$stage}: {$t['name']}: credit on the tenant and their leases");
        }
        $this->assertSame((int) $o['credit_minor'], (int) array_sum(array_map(fn ($l) => (int) $l['position']['credit_minor'], $leases)), "{$stage}: credit across the leases");

        // Doors: the overview, Today, the properties and the book.
        $this->assertSame((int) $o['units'], (int) $today['figures']['units'], "{$stage}: units on Today's figures");
        $this->assertSame((int) $o['units'], (int) $today['board']['units'], "{$stage}: units on Today's board");
        $this->assertSame((int) $o['vacant'], (int) $today['board']['vacant'] + (int) $today['board']['held_back'], "{$stage}: not let, on the overview and Today's board (which keeps apart the doors held back)");
        $this->assertSame((int) $o['occupied'], (int) $today['board']['occupied'], "{$stage}: occupied on Today's board");
        $this->assertSame((int) $o['units'], (int) array_sum(array_column($properties, 'units_count')), "{$stage}: units by property");
        $this->assertSame((int) $o['occupied'], (int) array_sum(array_column($properties, 'occupied_count')), "{$stage}: occupied by property");
        $this->assertSame((int) $o['units'], (int) array_sum(array_column($book['properties'], 'units_count')), "{$stage}: units in the book");
        $this->assertSame((int) $o['occupied'], (int) array_sum(array_column($book['properties'], 'occupied_count')), "{$stage}: occupied in the book");
    }

    /** Every row of a list, with the landlord the bill's lease is on for this test's sums. @return list<array<string, mixed>> */
    private function pagesOf(string $path): array
    {
        $rows = [];
        $sep = str_contains($path, '?') ? '&' : '?';
        for ($page = 1; $page < 20; $page++) {
            $res = $this->getJson(self::API.$path.$sep."per_page=100&page={$page}", $this->h)->assertOk();
            foreach ((array) $res->json('data') as $r) {
                if (isset($r['id'], $r['lease_id'])) {
                    $r['landlord_id_for_test'] = \App\Models\RentalCharge::withoutGlobalScopes()->whereKey($r['id'])->value('landlord_id');
                }
                $rows[] = $r;
            }
            if (! $res->json('meta.pagination.has_more')) {
                break;
            }
        }

        return $rows;
    }

    public function test_one_agencys_whole_life(): void
    {
        // ── The account ──
        $this->on('2026-01-05');
        if (class_exists(EstateLedgerPlanSeeder::class) && ! Plan::where('slug', 'estate-ledger-agency')->exists()) {
            $this->seed(EstateLedgerPlanSeeder::class);
        }
        $t = $this->registerTenant(['modules' => ['rentals'], 'currency' => 'UGX']);
        $this->cid = (int) $t['company_id'];
        Subscription::where('company_id', $this->cid)->update(['plan_id' => Plan::where('slug', 'estate-ledger-agency')->value('id'), 'status' => 'active', 'trial_ends_at' => null, 'ends_at' => '2030-01-01 00:00:00']);
        $this->h = $this->auth($t['token']);
        $this->send('setup', ['mode' => 'agency', 'grace_days' => 5, 'bill_days_before' => 5, 'commission_type' => 'percent', 'commission_bp' => 1000, 'deposit_held_by' => 'agency', 'late_fee_type' => 'none', 'payment_instructions' => 'MTN MoMo 0700 000 100 (fictional)']);
        $this->send('cash/opening', ['method' => 'bank', 'amount_minor' => 500000, 'on' => '2026-01-01']);
        $this->agrees('setup');

        // ── Property, units, landlords ──
        $mary = $this->send('landlords', ['name' => 'Mary Fictional', 'phone' => '0700000301', 'email' => 'mary'.uniqid().'@example.com', 'payout_method' => 'bank', 'payout_details' => 'Demo Bank 0101 (fictional)', 'expense_limit_minor' => 100000]);
        $sam = $this->send('landlords', ['name' => 'Sam Sample', 'phone' => '0700000302', 'payout_method' => 'cash']);
        $plaza = $this->send('properties', ['name' => 'Plaza Court', 'landlord_id' => $mary['id'], 'water_rate_minor' => 3000, 'area' => 'Ntinda']);
        $yard = $this->send('properties', ['name' => 'Yard View', 'landlord_id' => $sam['id'], 'area' => 'Kisaasi']);
        $this->ids += ['mary' => $mary['id'], 'sam' => $sam['id'], 'plaza' => $plaza['id'], 'yard' => $yard['id']];
        $a1 = $this->send('units', ['property_id' => $plaza['id'], 'code' => 'A1', 'rent_minor' => 500000, 'kind' => 'apartment']);
        $a2 = $this->send('units', ['property_id' => $plaza['id'], 'code' => 'A2', 'rent_minor' => 400000, 'kind' => 'apartment']);
        $a3 = $this->send('units', ['property_id' => $plaza['id'], 'code' => 'A3', 'rent_minor' => 300000, 'kind' => 'apartment']);
        $b1 = $this->send('units', ['property_id' => $yard['id'], 'code' => 'B1', 'rent_minor' => 200000, 'kind' => 'single_room']);
        $this->assertSame(['vacant', 'vacant'], [$a1['state'], $b1['state']]);
        $this->agrees('property and units');

        // ── Tenants, move-in, a first payment by MoMo ──
        $asha = $this->send('leases', ['unit_id' => $a1['id'], 'tenant' => ['name' => 'Asha Fictional', 'phone' => '0772000301', 'email' => 'asha'.uniqid().'@example.com'], 'start_date' => '2026-01-05', 'rent_minor' => 500000, 'deposit_minor' => 500000, 'deposit_held_by' => 'agency',
            'items' => [['name' => 'Garbage', 'amount_minor' => 10000, 'goes_to' => 'agency']], 'first_payment' => ['amount_minor' => 1010000, 'method' => 'mobile_money', 'reference' => 'MM-1', 'uuid' => $this->uuid()]]);
        $this->assertSame(0, $asha['position']['owed_minor'], 'the deposit, the rent and the fee are settled');
        $this->assertSame(500000, $asha['position']['deposit_held_minor']);
        $this->ids += ['asha' => $asha['id']];
        $ben = $this->send('leases', ['unit_id' => $a2['id'], 'tenant' => ['name' => 'Ben Sample', 'phone' => '0772000302'], 'start_date' => '2026-01-05', 'rent_minor' => 400000, 'deposit_minor' => 0]);
        $this->ids += ['ben' => $ben['id']];
        $cleo = $this->send('leases', ['unit_id' => $b1['id'], 'tenant' => ['name' => 'Cleo Madeup', 'phone' => '0772000303'], 'start_date' => '2026-01-05', 'rent_minor' => 200000, 'deposit_minor' => 200000, 'deposit_held_by' => 'landlord']);
        $this->ids += ['cleo' => $cleo['id']];
        $this->assertSame(400000, $ben['position']['owed_minor']);
        $this->agrees('move-ins');

        // ── Payments: cash, part, more than is owed, and paid straight to the landlord ──
        $this->on('2026-01-10');
        $part = $this->send('payments', ['lease_id' => $ben['id'], 'amount_minor' => 150000, 'method' => 'cash', 'uuid' => $this->uuid()]);
        $this->assertSame(250000, $part['owed_after_minor'], 'part paid');
        $bill = $part['allocations'][0];
        $this->assertSame(135000, $bill['landlord_minor']);
        $this->assertSame(15000, $bill['commission_minor']);
        $this->agrees('a part payment');
        $over = $this->send('payments', ['lease_id' => $cleo['id'], 'amount_minor' => 500000, 'method' => 'cash', 'uuid' => $this->uuid()]);
        $this->assertSame(100000, $over['unallocated_minor'], 'the rest is credit');
        $this->assertSame(100000, $this->lease('cleo')['position']['credit_minor']);
        $this->agrees('more than was owed');
        $direct = $this->send('payments', ['lease_id' => $ben['id'], 'amount_minor' => 50000, 'method' => 'paid_landlord', 'uuid' => $this->uuid()]);
        $this->assertSame(200000, $direct['owed_after_minor']);
        $this->agrees('paid to the landlord');

        // ── A reversal: the money goes back, the bill reopens ──
        $slip = $this->send('payments', ['lease_id' => $ben['id'], 'amount_minor' => 100000, 'method' => 'bank', 'reference' => 'CHQ-9', 'uuid' => $this->uuid()]);
        $this->assertSame(100000, $slip['owed_after_minor']);
        $this->agrees('a bank payment');
        $back = $this->send('payments/'.$slip['id'].'/reverse', ['reason' => 'The cheque bounced']);
        $this->assertTrue($back['reversed']);
        $this->assertSame(200000, $this->lease('ben')['position']['owed_minor']);
        $this->agrees('a reversal');

        // ── Charges: a one-off, a mistake cancelled, part forgiven ──
        $key = $this->send('charges', ['lease_id' => $ben['id'], 'description' => 'A lost key', 'amount_minor' => 20000, 'goes_to' => 'agency', 'due_date' => '2026-01-10']);
        $wrong = $this->send('charges', ['lease_id' => $ben['id'], 'description' => 'Typed twice', 'amount_minor' => 20000]);
        $this->send('charges/'.$wrong['id'].'/void', ['reason' => 'Typed twice']);
        $this->send('charges/'.$key['id'].'/waive', ['amount_minor' => 5000, 'reason' => 'Goodwill']);
        $this->assertSame(215000, $this->lease('ben')['position']['owed_minor']);
        $this->agrees('charges');

        // ── An expense and a repair above the landlord's limit ──
        $this->send('expenses', ['property_id' => $plaza['id'], 'category' => 'Repairs', 'description' => 'Gate paint', 'amount_minor' => 30000, 'method' => 'cash', 'charged_to' => 'landlord']);
        $this->send('expenses', ['category' => 'Office', 'description' => 'Airtime', 'amount_minor' => 5000, 'method' => 'cash', 'charged_to' => 'agency']);
        $this->agrees('expenses');
        $this->send('landlords/'.$mary['id'].'/login', ['email' => 'mary.signs.in.'.uniqid().'@example.com', 'password' => self::PASS]);
        $maryLogin = $this->read('landlords/'.$mary['id'])['email'] ?? null;
        $signIn = \App\Models\RentalLogin::withoutGlobalScopes()->where('company_id', $this->cid)->where('subject_type', 'landlord')->where('subject_id', $mary['id'])->value('email');
        $maryH = $this->auth($this->postJson(self::API.'portal-auth/login', ['login' => $signIn, 'password' => self::PASS, 'device_name' => 'phone'])->assertStatus(201)->json('data.token'));
        unset($maryLogin);
        $repair = $this->send('maintenance', ['uuid' => $this->uuid(), 'unit_id' => $a1['id'], 'title' => 'Geyser burst', 'priority' => 'urgent']);
        $repair = $this->amend('maintenance/'.$repair['id'], ['quote_minor' => 150000]);
        $this->assertTrue($repair['needs_answer'], 'above her limit');
        $this->assertSame(100000, $repair['limit_minor']);
        $asked = $this->send('maintenance/'.$repair['id'].'/ask-landlord');
        $this->assertStringContainsString('Geyser', $asked['message']['text']);
        $waiting = $this->read('me/approvals', $maryH);
        $this->assertSame([$repair['id']], array_column($waiting, 'id'));
        $this->assertSame(150000, $waiting[0]['quote_minor']);
        $this->send('me/approvals/'.$repair['id'], ['yes' => true, 'note' => 'Go ahead'], 200, $maryH);
        $done = $this->amend('maintenance/'.$repair['id'], ['status' => 'done', 'cost_minor' => 150000, 'method' => 'cash', 'vendor' => 'A plumber', 'resolution' => 'Replaced']);
        $this->assertSame('done', $done['status']);
        $this->assertNotNull($done['expense_id'], 'a cost becomes an expense');
        $this->agrees('a repair');
        // Above the limit with no answer: a reason, and it never blocks.
        $this->send('expenses', ['property_id' => $plaza['id'], 'category' => 'Repairs', 'amount_minor' => 120000, 'method' => 'cash'], 422);
        $this->send('expenses', ['property_id' => $plaza['id'], 'category' => 'Repairs', 'amount_minor' => 120000, 'method' => 'cash', 'over_limit_reason' => 'The roof could not wait']);
        $this->agrees('an expense above the limit');

        // ── A meter: the first reading sets the start, the second bills ──
        $this->send('meter-readings', ['uuid' => $this->uuid(), 'unit_id' => $a1['id'], 'utility' => 'water', 'reading' => '100', 'read_on' => '2026-01-10']);
        $reading = $this->send('meter-readings', ['uuid' => $this->uuid(), 'unit_id' => $a1['id'], 'utility' => 'water', 'reading' => '110', 'read_on' => '2026-01-10']);
        $this->assertSame(30000, $reading['amount_minor']);
        $this->assertSame(30000, $this->lease('asha')['position']['owed_minor']);
        $this->agrees('a meter reading');

        // ── A promise and its follow-up ──
        $this->send('follow-ups', ['lease_id' => $ben['id'], 'channel' => 'call', 'note' => 'Will pay on the 20th', 'promise_on' => '2026-01-20', 'uuid' => $this->uuid()]);
        $this->on('2026-01-20');
        $chase = collect($this->read('today')['groups'])->firstWhere('key', 'chase');
        $this->assertSame('promise_today', collect($chase['lines'])->firstWhere('tenant_id', $ben['tenant']['id'])['kind']);
        $this->send('payments', ['lease_id' => $ben['id'], 'amount_minor' => 215000, 'method' => 'mobile_money', 'reference' => 'MM-2', 'uuid' => $this->uuid()]);
        $this->assertSame(0, $this->lease('ben')['position']['owed_minor']);
        $this->agrees('a promise kept');

        // ── Bill ahead: money above what is owed bills the coming periods ──
        $ahead = $this->send('payments', ['lease_id' => $cleo['id'], 'amount_minor' => 300000, 'method' => 'cash', 'bill_ahead_periods' => 2, 'uuid' => $this->uuid()]);
        $this->assertSame(0, $ahead['owed_after_minor']);
        $this->agrees('billed ahead');

        // ── The month ends: the bills of February ──
        $this->on('2026-02-02');
        $this->send('billing/run');
        $this->agrees('February billed');
        $this->send('payments', ['lease_id' => $ben['id'], 'amount_minor' => 400000, 'method' => 'cash', 'uuid' => $this->uuid()]);
        $this->agrees('February paid');

        // ── Payouts: part of a balance, an advance, a run ──
        $maryOwed = (int) $this->read('landlords/'.$mary['id'])['balance_minor'];
        $this->assertGreaterThan(0, $maryOwed);
        $this->send('payouts', ['landlord_id' => $mary['id'], 'amount_minor' => 10000, 'method' => 'cash', 'reference' => 'PAY-1']);
        $this->agrees('a payout');
        $this->send('payouts', ['landlord_id' => $mary['id'], 'amount_minor' => $maryOwed + 50000, 'method' => 'cash'], 422);
        $this->send('payouts', ['landlord_id' => $mary['id'], 'amount_minor' => $maryOwed - 10000 + 5000, 'method' => 'cash', 'pay_ahead' => true, 'advance_reason' => 'School fees', 'advance_others_ok' => true]);
        $this->assertSame(-5000, (int) $this->read('landlords/'.$mary['id'])['balance_minor'], 'an advance puts her below zero');
        $this->agrees('an advance');
        $samOwed = (int) $this->read('landlords/'.$sam['id'])['balance_minor'];
        $this->assertSame([$sam['id']], array_column($this->read('payout-runs/candidates'), 'landlord_id'), 'only who is owed');
        $run = $this->send('payout-runs', ['lines' => [['landlord_id' => $sam['id'], 'amount_minor' => $samOwed - 1000, 'method' => 'cash']]]);
        $this->assertTrue($run['check']['short'], 'the cash is in mobile money, and the draft says so');
        $this->send('payout-runs/'.$run['id'].'/post', [], 422);
        $this->agrees('a draft run moves nothing');
        $this->send('cash/transfers', ['from' => 'mobile_money', 'to' => 'cash', 'amount_minor' => 1000000, 'note' => 'Withdrawn']);
        $this->agrees('money moved between accounts');
        $run = $this->amend('payout-runs/'.$run['id'], ['lines' => [['landlord_id' => $sam['id'], 'amount_minor' => $samOwed, 'method' => 'cash']]]);
        $posted = $this->send('payout-runs/'.$run['id'].'/post', ['reference' => 'RUN-1']);
        $this->assertSame('posted', $posted['status']);
        $this->assertSame(0, (int) $this->read('landlords/'.$sam['id'])['balance_minor']);
        $this->send('payout-runs/'.$run['id'].'/post', [], 422);
        $this->agrees('a run posted');

        // ── January closes, its statements go out ──
        $closing = $this->read('month-end?month=2026-01');
        $this->assertTrue($closing['can_close']);
        $closed = $this->send('month-end/close', ['month' => '2026-01']);
        $this->assertSame('2026-01-31', $closed['closed_through']);
        $this->agrees('January closed');
        $jan = $this->read('month-end?month=2026-01');
        $this->assertSame('closed', $jan['status']);
        $this->assertSame((int) $closing['figures']['totals']['collected'], (int) $jan['figures']['totals']['collected'], 'closing changed nothing');
        foreach ($jan['send'] as $row) {
            $st = $this->read('landlords/'.$row['landlord_id'].'/statement?from=2026-01-01&to=2026-01-31');
            $this->assertSame((int) $row['closing_minor'], (int) $st['closing_minor'], 'the sent paper and the statement');
            $this->assertSame((int) collect($jan['figures']['rows'])->firstWhere('landlord_id', $row['landlord_id'])['closing_minor'], (int) $row['closing_minor'], 'the sent paper and month end');
            $msg = $this->read('statements/'.$row['statement_id'].'/message');
            $this->assertStringContainsString('/rentals/pdf/statement', $msg['link']);
            $this->assertSame((int) $row['closing_minor'], (int) $msg['closing_minor']);
            $this->send('statements/'.$row['statement_id'].'/sent', ['via' => 'whatsapp']);
        }
        // Her own view of the same month is the same paper.
        $hers = $this->read('me/statement?month=2026-01', $maryH);
        $this->assertSame((int) collect($jan['send'])->firstWhere('landlord_id', $mary['id'])['closing_minor'], (int) $hers['closing_minor'], 'the landlord reads what was sent');
        $this->assertSame(['2026-01'], array_column($this->read('me/statements', $maryH), 'month'));
        // Nothing dated in it goes in now.
        $this->send('payments', ['lease_id' => $ben['id'], 'amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-01-20'], 422);
        $this->send('expenses', ['property_id' => $plaza['id'], 'category' => 'Repairs', 'amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-01-20'], 422);
        $this->assertSame((int) $this->read('landlords/'.$mary['id'])['balance_minor'], (int) $this->read('me/home', $maryH)['owed_minor'], 'her balance, office and own');

        // ── March: a handover ──
        $this->on('2026-03-03');
        $this->send('billing/run');
        $this->agrees('March billed');
        $preview = $this->read('properties/'.$plaza['id'].'/handover-preview?landlord_id='.$sam['id'].'&from=2026-03-01');
        $this->assertSame((int) $this->read('landlords/'.$mary['id'])['balance_minor'], (int) $preview['old']['balance_minor']);
        $this->send('properties/'.$plaza['id'].'/handover', ['landlord_id' => $sam['id'], 'from' => '2026-03-01', 'reason' => 'Sold to Sam']);
        $this->assertSame($sam['id'], $this->read('properties/'.$plaza['id'])['landlord']['id']);
        $this->agrees('a handover');
        $this->send('payments', ['lease_id' => $asha['id'], 'amount_minor' => 1020000, 'method' => 'cash', 'uuid' => $this->uuid()]);
        $this->agrees('rent after the handover');

        // ── Notice, then the move-out with the deposit ──
        $this->send('leases/'.$asha['id'].'/notice', ['notice_on' => '2026-03-03', 'move_out_on' => '2026-03-20']);
        $this->send('leases/'.$asha['id'].'/withdraw-notice');
        $this->send('leases/'.$asha['id'].'/notice', ['notice_on' => '2026-03-03', 'move_out_on' => '2026-03-20']);
        $this->on('2026-03-20');
        $held = (int) $this->lease('asha')['position']['deposit_held_minor'];
        $this->assertSame(500000, $held);
        $out = $this->send('leases/'.$asha['id'].'/move-out', ['date' => '2026-03-20', 'damages' => [['description' => 'A broken window', 'amount_minor' => 40000, 'goes_to' => 'landlord']], 'readings' => [['utility' => 'water', 'reading' => '115']], 'unused_days' => true, 'reason' => 'Moved away']);
        $s = $out['settlement'];
        $this->assertSame($held, (int) $s['deposit_used_minor'] + (int) $s['deposit_kept_minor'] + (int) $s['deposit_refunded_minor'], 'every shilling of the deposit is somewhere');
        $this->assertSame('ended', $out['lease']['status']);
        $this->agrees('a move-out');
        $kept = (int) $this->lease('asha')['position']['deposit_held_minor'];
        if ($kept > 0) {
            $this->send('leases/'.$asha['id'].'/refund-deposit', ['method' => 'cash']);
            $this->assertSame(0, (int) $this->lease('asha')['position']['deposit_held_minor']);
            $this->agrees('the deposit refunded');
        }
        $credit = (int) $this->lease('asha')['position']['credit_minor'];
        if ($credit > 0) {
            $this->send('leases/'.$asha['id'].'/refund-credit', ['method' => 'cash']);
            $this->assertSame(0, (int) $this->lease('asha')['position']['credit_minor']);
            $this->agrees('the credit refunded');
        }
        $this->assertSame('vacant', $this->read('units/'.$a1['id'])['state']);

        // ── A new tenant in the same door, a rent change, a lease made by mistake ──
        $dora = $this->send('leases', ['unit_id' => $a1['id'], 'tenant' => ['name' => 'Dora Invented', 'phone' => '0772000304'], 'start_date' => '2026-03-21', 'rent_minor' => 550000, 'deposit_minor' => 0, 'first_payment' => ['amount_minor' => 550000, 'method' => 'cash', 'uuid' => $this->uuid()]]);
        $this->ids += ['dora' => $dora['id']];
        $mistake = $this->send('leases', ['unit_id' => $a3['id'], 'tenant' => ['name' => 'Typed Wrong', 'phone' => '0772000305'], 'start_date' => '2026-03-21', 'rent_minor' => 300000, 'deposit_minor' => 0]);
        $this->send('leases/'.$mistake['id'].'/cancel', ['reason' => 'Wrong door']);
        $this->send('leases/'.$dora['id'].'/rent-change', ['rent_minor' => 600000, 'effective_from' => '2026-05-21', 'reason' => 'Agreed']);
        $this->agrees('a door let again');

        // ── A door under repair is held back, and a building given up leaves every count ──
        $this->amend('units/'.$a3['id'], ['status' => 'maintenance']);
        $this->agrees('a door under repair');
        $old = $this->send('properties', ['name' => 'Old Yard', 'landlord_id' => $sam['id']]);
        $this->send('units', ['property_id' => $old['id'], 'code' => 'O1', 'rent_minor' => 100000]);
        $this->agrees('a new building');
        $this->amend('properties/'.$old['id'], ['status' => 'archived']);
        $this->agrees('a building archived');

        // ── February closes, then March, and the year reads as the sum of its months ──
        $this->on('2026-04-02');
        $this->send('billing/run');
        $this->send('month-end/close', ['month' => '2026-02']);
        $this->agrees('February closed');
        $this->send('month-end/close', ['month' => '2026-03']);
        $this->agrees('March closed');
        $this->send('cash/transfers', ['from' => 'cash', 'to' => 'bank', 'amount_minor' => 1000, 'note' => 'Banked']);
        $own = (int) $this->read('money-position')['agency_own_minor'];
        $this->send('cash/drawings', ['method' => 'cash', 'amount_minor' => 1000, 'note' => 'Office', 'below_zero_agreed' => true]);
        $this->assertSame($own - 1000, (int) $this->read('money-position')['agency_own_minor']);
        $this->agrees('the agency took its own');

        foreach ([$mary['id'], $sam['id']] as $landlordId) {
            $year = $this->read('reports/landlord_year?landlord_id='.$landlordId.'&year=2026');
            $months = ['collected_minor' => 0, 'commission_minor' => 0, 'expenses_minor' => 0, 'payouts_minor' => 0];
            foreach (['01', '02', '03'] as $m) {
                $st = $this->read("landlords/{$landlordId}/statement?from=2026-{$m}-01&to=".Carbon::parse("2026-{$m}-01")->endOfMonth()->toDateString());
                foreach ($months as $k => $v) {
                    $months[$k] = $v + (int) $st['totals'][$k];
                }
            }
            $st = $this->read("landlords/{$landlordId}/statement?from=2026-04-01&to=2026-04-02");
            foreach ($months as $k => $v) {
                $months[$k] = $v + (int) $st['totals'][$k];
            }
            $this->assertSame((int) $st['closing_minor'], (int) $this->read('landlords/'.$landlordId)['balance_minor'], 'the last statement closes on the balance');
            $this->assertSame((int) $months['collected_minor'], (int) $year['totals']['collected_minor'], "the year's collected is the months'");
            $this->assertSame((int) $months['commission_minor'], (int) $year['totals']['commission_minor'], "the year's commission is the months'");
        }

        // The reports that restate the books say the same.
        $money = $this->read('money-position');
        $report = $this->read('reports/money_position');
        $this->assertSame((int) $money['cash_minor'], (int) ($report['totals']['cash_minor'] ?? $report['totals']['cash_total'] ?? $money['cash_minor']));
        $commission = $this->read('reports/commission?from=2026-01-01&to=2026-04-02');
        $jan = $this->read('month-end?month=2026-01')['figures']['totals']['commission'];
        $feb = $this->read('month-end?month=2026-02')['figures']['totals']['commission'];
        $mar = $this->read('month-end?month=2026-03')['figures']['totals']['commission'];
        $apr = $this->read('month-end?month=2026-04')['figures']['totals']['commission'];
        $this->assertSame((int) $jan + (int) $feb + (int) $mar + (int) $apr, (int) $commission['totals']['commission_minor'], 'the commission report and the months');
        $this->assertBooksAgree($this->cid);
    }
}
