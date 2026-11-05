<?php

namespace Tests\Feature\Rentals;

use App\Http\Middleware\RentalPortalAuth;
use App\Models\Company;
use App\Models\RentalCharge;
use App\Models\RentalPayment;
use App\Models\RentalStatement;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Collections\FollowUps;
use App\Services\Rentals\Documents;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\PayoutRuns;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Maintenance;
use App\Services\Rentals\Meters;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Portal\LandlordApprovals;
use App\Services\Rentals\Portal\PaidReports;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Every route of the Estate Ledger API, walked from the router so a new route cannot be skipped: who may call it, what bad input does to it, what it says and to whom. A route with
 * no line in the tables below fails its test. Each write runs inside a savepoint and is rolled back, so every request starts from the same books.
 */
class RentalsApiSweepTest extends RentalsPhoneApiCase
{
    /** Routes with no token, and why. Signed links carry their own key; sign-in is how a token is got. */
    private const PUBLIC = [
        'GET rentals/pdf/receipt' => 'signed', 'GET rentals/pdf/statement' => 'signed', 'GET rentals/pdf/payout-advice' => 'signed', 'GET rentals/pdf/payout-run' => 'signed',
        'GET rentals/pdf/tenant-statement' => 'signed', 'GET rentals/pdf/year-summary' => 'signed', 'GET rentals/pdf/lease-statement' => 'signed', 'GET rentals/pdf/lease-statement' => 'signed', 'GET rentals/report-download' => 'signed', 'GET rentals/files/{uuid}' => 'signed',
        'POST rentals/portal-auth/login' => 'sign-in', 'POST rentals/portal-auth/token' => 'sign-in', 'POST rentals/portal-auth/code' => 'sign-in',
        'POST rentals/portal-auth/code/verify' => 'sign-in', 'POST rentals/portal-auth/demo' => 'sign-in',
    ];

    /** Person routes for a tenant; those for a landlord; the rest are for both. A route for the other kind answers 404. */
    private const TENANT_ONLY = ['me/paid-reports', 'me/lease/{id}/statement-link', 'me/charges', 'me/payments', 'me/payments/{id}', 'me/payments/{id}/receipt', 'me/lease/{id}', 'me/maintenance', 'me/notice', 'me/files'];

    private const LANDLORD_ONLY = ['me/approvals', 'me/approvals/{id}', 'me/repairs', 'me/expenses', 'me/statements', 'me/properties', 'me/statement', 'me/payouts', 'me/payouts/{id}/advice', 'me/year-summary', 'me/sheet', 'me/arrears'];

    /** A leaf of a body turned into each of these. */
    private const BAD_MONEY = ['array', 'map', 'float', 'negative', 'huge_int', 'huge_float', 'digits', 'text', 'digits_text', 'big_string', 'emoji', 'sql', 'html', 'zero_date', 'bad_date', 'true'];

    private const BAD_DATE = ['array', 'map', 'zero_date', 'bad_date', 'feb31', 'far_date', 'old_date', 'text', 'digits_text', 'big_string', 'emoji', 'sql', 'html', 'true', 'float', 'negative', 'huge_int'];

    private const DATE_FIELDS = ['paid_on', 'on', 'date', 'start_date', 'end_date', 'due_date', 'read_on', 'notice_on', 'move_out_on', 'promise_on', 'effective_from', 'starts_on', 'ends_on', 'period_from', 'period_to', 'resolved_on', 'month'];

    private string $privateRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateRoot = sys_get_temp_dir().'/el-sweep-'.uniqid();
        config(['rentals.private_root' => $this->privateRoot, 'app.debug' => false]);
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->privateRoot);
        parent::tearDown();
    }

    // ── The world ──────────────────────────────────────────────────────────

    /**
     * One agency with a record of every kind, and a second agency with its own. February is closed and today is 2 April 2026, so March is ready to close.
     *
     * @return array<string, mixed>
     */
    private function sweepWorld(): array
    {
        $w = $this->world();
        $owner = $w['owner'];
        $cid = (int) $w['company']->id;

        app(MonthClose::class)->close($owner, '2026-02');
        Carbon::setTestNow('2026-04-02 09:00:00');
        app(\App\Services\Rentals\Billing\Billing::class)->raiseAll($cid);

        $w['margaret']->forceFill(['expense_limit_minor' => 1000, 'withholding_bp' => 600, 'notes' => 'Staff note about Margaret', 'payout_details' => 'Demo Bank, 0000 0101 (fictional)'])->save();
        DB::table('rental_settings')->where('company_id', $cid)->update(['withholding_on' => true]);
        $w['payment'] = app(Payments::class)->receive($owner, $w['brian'], ['amount_minor' => 100000, 'method' => 'cash', 'reference' => 'REF-SWEEP-1', 'notes' => 'Staff note on a payment']);
        $w['aminaPayment'] = RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $w['amina']->id)->orderBy('id')->firstOrFail();
        app(Payments::class)->receive($owner, $w['amina'], ['amount_minor' => 900000, 'method' => 'cash']); // credit ahead, and cash to spend
        app(Payments::class)->receive($owner, $w['carol'], ['amount_minor' => 900000, 'method' => 'cash']);
        $w['openCharge'] = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $w['brian']->id)->where('status', 'open')->where('kind', 'rent')->orderByDesc('id')->firstOrFail();
        $w['voidable'] = app(\App\Services\Rentals\Billing\Billing::class)->charge($owner, $w['brian'], ['description' => 'A key', 'amount_minor' => 5000, 'goes_to' => 'agency']);
        $w['item'] = app(Leases::class)->addItem($owner, $w['amina'], ['name' => 'Garbage', 'amount_minor' => 10000, 'goes_to' => 'agency']);
        $w['payout'] = app(Payouts::class)->record($owner, $w['margaret'], ['amount_minor' => 1000, 'method' => 'cash']);
        $w['expense'] = app(Expenses::class)->record($owner, ['property_id' => $w['p']->id, 'category' => 'Repairs', 'amount_minor' => 900, 'method' => 'cash', 'description' => 'Paint']);
        $w['otherExpense'] = app(Expenses::class)->record($owner, ['property_id' => $w['q']->id, 'category' => 'Repairs', 'amount_minor' => 900, 'method' => 'cash', 'description' => 'Lake View roof']);
        $w['drawing'] = app(CashBook::class)->agencyDrawing($owner, 'cash', 100, null, 'Rent for the office', true);
        $w['run'] = app(PayoutRuns::class)->save($owner, [['landlord_id' => $w['peter']->id, 'amount_minor' => 1000, 'method' => 'cash']]);
        $w['followUp'] = app(FollowUps::class)->log($owner, $w['brian'], 'call', 'Rang him', '2026-04-10');
        app(Meters::class)->record($owner, ['unit_id' => $w['units'][0]->id, 'utility' => 'water', 'reading' => '10', 'read_on' => '2026-03-20']);
        $w['p']->forceFill(['water_rate_minor' => 2000])->save();
        $w['paperUuid'] = $this->privateFile($cid, 'rental_document', 'application/pdf');
        $w['document'] = app(Documents::class)->add($owner, 'tenant', (int) $w['amina']->tenant_id, ['file_uuid' => $w['paperUuid'], 'name' => 'ID copy', 'kind' => 'id']);
        $w['photoUuid'] = $this->privateFile($cid, 'rental_document', 'application/pdf');

        [$w['tenantH'], $w['tenantLogin']] = $this->person($owner, 'tenant', (int) $w['amina']->tenant_id);
        [$w['tenant2H']] = $this->person($owner, 'tenant', (int) $w['carol']->tenant_id);
        [$w['landlordH'], $w['landlordLogin']] = $this->person($owner, 'landlord', (int) $w['margaret']->id);
        [$w['landlord2H']] = $this->person($owner, 'landlord', (int) $w['peter']->id);
        $w['paidReport'] = app(PaidReports::class)->report($w['tenantLogin'], ['amount_minor' => 5000, 'method' => 'mobile_money', 'reference' => 'MM-REPORT', 'uuid' => (string) Str::uuid()])['report'];
        $w['quoted'] = app(Maintenance::class)->quote($owner, $w['urgentHere'], 5000);
        $asked = app(Maintenance::class)->open($owner, $cid, ['unit_id' => $w['units'][0]->id, 'title' => 'Burst pipe']);
        $asked = app(Maintenance::class)->quote($owner, $asked, 5000);
        $w['asked'] = app(LandlordApprovals::class)->ask($owner, $asked);
        $w['statement'] = RentalStatement::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $w['margaret']->id)->where('status', 'issued')->firstOrFail();

        // A lease under notice, with nothing paid on it, and one ended that keeps a deposit and credit: for the routes that need those.
        $w['danNotice'] = app(Leases::class)->notice($owner, $w['dan'], '2026-04-01', '2026-05-30');
        [$r, $rooms] = $this->building($owner, 3, 100000, ['name' => 'Spare Rooms']);
        $w['r'] = $r;
        $w['vacant'] = $rooms[0];
        $w['ended'] = $this->tenantIn($owner, $rooms[2], '2026-03-01', ['deposit_minor' => 100000]);
        app(Payments::class)->receive($owner, $w['ended'], ['amount_minor' => 900000, 'method' => 'cash']);
        app(Leases::class)->moveOut($owner, $w['ended']->fresh(), ['date' => '2026-03-31']);
        $w['ended'] = $w['ended']->fresh();
        $w['cancellable'] = $this->tenantIn($owner, $rooms[1], '2026-04-01', ['deposit_minor' => 0]);
        app(\App\Services\Rentals\Landlords\Handover::class)->handOver($owner, $r->fresh(), ['landlord_id' => $w['peter']->id, 'from' => '2026-06-01', 'reason' => 'Sold, from June']);

        // The other agency: everything a stranger could point at, and whatever it might try on ours.
        [$xCompany, $xOwner] = $this->rentalsAccount();
        Subscription::where('company_id', $xCompany->id)->update(['status' => 'active', 'trial_ends_at' => null, 'ends_at' => '2030-01-01 00:00:00']);
        [$xp, $xUnits] = $this->building($xOwner, 2, 100000, ['name' => 'Foreign Court']);
        $xLease = $this->tenantIn($xOwner, $xUnits[0], '2026-04-01', ['deposit_minor' => 0, 'tenant' => ['name' => 'Foreign Tenant', 'phone' => '0701999888']]);
        $xPay = app(Payments::class)->receive($xOwner, $xLease, ['amount_minor' => 50000, 'method' => 'cash']);
        $w['x'] = ['company' => $xCompany, 'owner' => $xOwner, 'p' => $xp, 'units' => $xUnits, 'lease' => $xLease, 'payment' => $xPay, 'landlord_id' => (int) $xp->landlord_id, 'tenant_id' => (int) $xLease->tenant_id,
            'charge' => RentalCharge::withoutGlobalScopes()->where('company_id', $xCompany->id)->where('lease_id', $xLease->id)->firstOrFail(),
            'ticket' => app(Maintenance::class)->open($xOwner, (int) $xCompany->id, ['unit_id' => $xUnits[0]->id, 'title' => 'Foreign tap']), 'file' => $this->privateFile((int) $xCompany->id, 'rental_document', 'application/pdf')];
        $w['tokens'] = ['owner' => $this->h($owner), 'caretaker' => $this->h($w['caretaker']), 'accountant' => $this->h($w['accountant']), 'viewer' => $this->h($w['viewer']), 'manager' => $this->h($this->rentalsStaff($w['company'], 'rentals_manager')), 'stranger' => $this->h($xOwner)];
        $this->assertBooksAgree($cid);

        return $w;
    }

    private function privateFile(int $cid, string $purpose, string $mime): string
    {
        $uuid = (string) Str::uuid();
        $path = "files/{$cid}/2026/03/{$uuid}.pdf";
        File::ensureDirectoryExists(dirname($this->privateRoot.'/'.$path));
        File::put($this->privateRoot.'/'.$path, '%PDF-1.4 sweep');
        StoredFile::withoutGlobalScopes()->create(['company_id' => $cid, 'uuid' => $uuid, 'purpose' => $purpose, 'path' => $path, 'mime' => $mime, 'size_bytes' => 14]);

        return $uuid;
    }

    // ── The router ─────────────────────────────────────────────────────────

    /** @return list<array{method: string, uri: string, short: string, kind: string}> every rentals route and method, read from the router */
    private function routes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            if (! str_starts_with($r->uri(), 'api/v1/rentals')) {
                continue;
            }
            $mw = $r->gatherMiddleware();
            $kind = match (true) {
                in_array('auth:sanctum', $mw, true) => 'staff',
                in_array(RentalPortalAuth::class, $mw, true) => 'person',
                default => 'public',
            };
            foreach ($r->methods() as $m) {
                if ($m === 'HEAD') {
                    continue;
                }
                $short = str_replace('api/v1/rentals/', '', $r->uri());
                $short = $short === 'api/v1/rentals' ? '' : $short;
                $out[] = ['method' => $m, 'uri' => $r->uri(), 'short' => $short, 'kind' => $kind];
                if ($kind === 'public') {
                    $this->assertArrayHasKey($m.' rentals/'.$short, self::PUBLIC, "{$m} {$short} answers with no token: put it on the allow-list, with why, or give it a guard");
                }
            }
        }
        $this->assertGreaterThanOrEqual(138, count($out), 'the walk found the routes');

        return $out;
    }

    /** A person route is for this kind of person, or for both. */
    private function forKind(string $short, string $kind): bool
    {
        return ! in_array($short, $kind === 'tenant' ? self::LANDLORD_ONLY : self::TENANT_ONLY, true);
    }

    /** The path of a route with real ids. A parameter with no line here fails, so a new route has to say what it points at. @return string the path after /api/v1/rentals/ */
    private function path(string $method, string $short, array $w): string
    {
        $id = match (true) {
            ! str_contains($short, '{') => null,
            $short === 'paid-reports/{id}/confirm', $short === 'paid-reports/{id}/decline' => $w['paidReport']->id,
            str_starts_with($short, 'statements/') => $w['statement']->id,
            str_starts_with($short, 'landlords/') => $w['margaret']->id,
            str_starts_with($short, 'payout-runs/') => $w['run']->id,
            str_starts_with($short, 'cash/drawings/') => $w['drawing']->id,
            $short === 'properties/{id}/handover/cancel' => $w['r']->id,
            str_starts_with($short, 'properties/') => $w['p']->id,
            str_starts_with($short, 'year-summaries/') => $w['margaret']->id,
            str_starts_with($short, 'me/payouts/') => $w['payout']->id,
            $short === 'maintenance/{id}/ask-landlord' => $w['quoted']->id,
            $short === 'maintenance/{id}/landlord-answer' => $w['asked']->id,
            str_starts_with($short, 'maintenance/') => $w['urgentThere']->id,
            str_starts_with($short, 'units/') => $w['vacant']->id,
            $short === 'tenants/{id}/login' => $w['brian']->tenant_id,
            str_starts_with($short, 'tenants/') => $w['amina']->tenant_id,
            $short === 'leases/{id}/withdraw-notice' => $w['danNotice']->id,
            $short === 'leases/{id}/cancel' => $w['cancellable']->id,
            $short === 'leases/{id}/refund-deposit', $short === 'leases/{id}/refund-credit' => $w['ended']->id,
            $short === 'leases/{id}/move-out', $short === 'leases/{id}/move-out-preview' => $w['brian']->id,
            str_starts_with($short, 'leases/') => $w['amina']->id,
            $short === 'charges/{id}/void' => $w['voidable']->id,
            str_starts_with($short, 'charges/') => $w['openCharge']->id,
            str_starts_with($short, 'payments/') => $w['payment']->id,
            str_starts_with($short, 'payouts/') => $w['payout']->id,
            str_starts_with($short, 'expenses/') => $w['expense']->id,
            str_starts_with($short, 'documents/') => $w['document']->id,
            str_starts_with($short, 'me/lease/') => $w['amina']->id,
            str_starts_with($short, 'me/payments/') => $w['aminaPayment']->id,
            str_starts_with($short, 'me/approvals/') => $w['asked']->id,
            str_starts_with($short, 'reports/'), str_starts_with($short, 'files/') => null,
            default => $this->fail("A new route with a parameter: say what {$short} points at in path()."),
        };

        return strtr($short, ['{id}' => (string) $id, '{item}' => (string) ($w['item']->id ?? ''), '{key}' => 'rent_roll', '{uuid}' => (string) $w['photoUuid']]);
    }

    /** Who calls a route: the owner, the tenant or the landlord. @return array<string, string> */
    private function caller(array $route, array $w): array
    {
        if ($route['kind'] === 'person') {
            return in_array($route['short'], self::LANDLORD_ONLY, true) ? $w['landlordH'] : $w['tenantH'];
        }

        return $route['kind'] === 'staff' ? $w['tokens']['owner'] : [];
    }

    /**
     * A body each write route takes, with every field it reads: the fuzz turns each leaf into nonsense one at a time. A write route with no line here fails.
     *
     * @return array<string, array<string, mixed>> "METHOD short" => body
     */
    private function bodies(array $w): array
    {
        $tenant = ['name' => 'Fresh Tenant', 'phone' => '0772100099', 'alt_phone' => '0772100097', 'email' => 'fresh@example.com', 'id_type' => 'national_id', 'id_number' => 'CM99FRESH', 'occupation' => 'Teacher', 'employer' => 'School',
            'next_of_kin_name' => 'Kin', 'next_of_kin_phone' => '0772100098', 'photo_uuid' => $w['photoUuid'], 'notes' => 'A note', 'kind' => 'person'];
        $landlord = ['name' => 'Fresh Landlord', 'phone' => '0772100090', 'phone_2' => '0772100091', 'email' => 'fresh.landlord@example.com', 'address' => 'Ntinda', 'id_number' => 'CM88FRESH', 'payout_method' => 'bank', 'payout_details' => 'Demo Bank 1',
            'commission_type' => 'percent', 'commission_bp' => 1000, 'commission_minor' => 0, 'notes' => 'A note', 'expense_limit_minor' => 5000, 'withholding_bp' => 600];
        $property = ['landlord_id' => $w['margaret']->id, 'code' => 'PX', 'name' => 'Fresh Court', 'kind' => 'residential', 'address' => 'Plot 1', 'area' => 'Ntinda', 'district' => 'Kampala', 'lat' => 0.35, 'lng' => 32.6, 'photo_uuid' => $w['photoUuid'],
            'commission_type' => 'percent', 'commission_bp' => 1000, 'commission_minor' => 0, 'deposit_held_by' => 'agency', 'water_rate_minor' => 2000, 'power_rate_minor' => 800, 'notes' => 'A note'];
        $unit = ['property_id' => $w['r']->id, 'code' => 'Z9', 'kind' => 'apartment', 'floor' => '1', 'block' => 'B', 'bedrooms' => 2, 'bathrooms' => 1, 'size_sqm' => 40, 'rent_minor' => 200000, 'deposit_minor' => 200000, 'cycle_months' => 1,
            'status' => 'available', 'water_meter' => 'W1', 'power_meter' => 'P1', 'notes' => 'A note', 'photo_uuid' => $w['photoUuid'], 'commission_type' => 'percent', 'commission_bp' => 1000, 'commission_minor' => 0];
        $line = ['landlord_id' => $w['peter']->id, 'amount_minor' => 1000, 'method' => 'cash'];
        $settings = ['bill_days_before' => 5, 'grace_days' => 3, 'anchor' => 'start', 'prorate_first' => true, 'commission_type' => 'percent', 'commission_bp' => 1000, 'commission_minor' => 0, 'commission_on_items' => false, 'deposit_months' => 1, 'deposit_held_by' => 'agency',
            'late_fee_type' => 'none', 'late_fee_minor' => 0, 'late_fee_bp' => 0, 'late_fee_to' => 'agency', 'payment_instructions' => 'Pay us', 'receipt_footer' => 'Thanks', 'messages' => ['overdue' => 'Please pay'], 'ahead_default' => 'hold', 'export_accounts' => ['cash' => '1000'], 'commission_gap_bp' => 500, 'withholding_on' => false];

        return [
            'POST paid-reports/{id}/confirm' => [],
            'POST paid-reports/{id}/decline' => ['reason' => 'Not on the statement'],
            'POST month-end/close' => ['month' => '2026-03'],
            'POST month-end/reopen' => ['month' => '2026-02', 'reason' => 'A receipt was missed'],
            'POST statements/{id}/sent' => ['via' => 'whatsapp'],
            'POST payout-runs' => ['lines' => [$line]],
            'PUT payout-runs/{id}' => ['lines' => [$line]],
            'POST payout-runs/{id}/post' => ['paid_on' => '2026-04-02', 'reference' => 'BANK-1', 'references' => []],
            'POST payout-runs/{id}/cancel' => [],
            'POST cash/drawings' => ['method' => 'cash', 'amount_minor' => 1000, 'on' => '2026-04-02', 'note' => 'Office rent', 'below_zero_agreed' => true],
            'POST cash/drawings/{id}/reverse' => ['reason' => 'Typed twice'],
            'POST month-end/withholding' => ['landlord_id' => $w['margaret']->id, 'month' => '2026-02', 'amount_minor' => 100, 'method' => 'cash', 'paid_on' => '2026-04-02', 'vendor' => 'The tax office'],
            'POST year-summaries/{id}/prepared' => ['year' => '2025', 'channel' => 'whatsapp'],
            'POST payouts/{id}/statement-prepared' => ['channel' => 'whatsapp'],
            'POST properties/{id}/handover/cancel' => [],
            'POST maintenance/{id}/ask-landlord' => [],
            'POST maintenance/{id}/landlord-answer' => ['yes' => true, 'via' => 'phone', 'who' => 'Margaret herself', 'note' => 'Go ahead'],
            'POST setup' => ['mode' => 'agency', 'currency' => 'UGX'] + $settings,
            'PUT settings' => $settings,
            'PATCH settings' => $settings,
            'POST landlords' => $landlord,
            'PUT landlords/{id}' => $landlord + ['status' => 'active'],
            'PATCH landlords/{id}' => $landlord + ['status' => 'active'],
            'POST landlords/{id}/login' => ['enabled' => true, 'email' => 'margaret.signs.in@example.com', 'phone' => '0772100061', 'password' => 'Fresh-pass-2026'],
            'POST payouts' => ['landlord_id' => $w['margaret']->id, 'amount_minor' => 2000, 'method' => 'cash', 'paid_on' => '2026-04-02', 'reference' => 'PX', 'period_from' => '2026-03-01', 'period_to' => '2026-03-31', 'notes' => 'A note', 'pay_ahead' => false, 'advance_reason' => 'A reason', 'advance_others_ok' => false],
            'POST payouts/{id}/reverse' => ['reason' => 'Typed twice'],
            'POST properties' => $property,
            'PUT properties/{id}' => array_diff_key($property, ['landlord_id' => 1, 'status' => 1]) + ['landlord_id' => $w['margaret']->id, 'status' => 'active'],
            'PATCH properties/{id}' => array_diff_key($property, ['landlord_id' => 1, 'status' => 1]) + ['landlord_id' => $w['margaret']->id, 'status' => 'active'],
            'PUT properties/{id}/staff' => ['user_ids' => [$w['caretaker']->id]],
            'POST properties/{id}/handover' => ['landlord_id' => $w['peter']->id, 'from' => '2026-04-02', 'reason' => 'Sold to Peter'],
            'POST units' => $unit,
            'PUT units/{id}' => $unit,
            'PATCH units/{id}' => $unit,
            'POST tenants' => $tenant + ['allow_duplicate' => false],
            'PUT tenants/{id}' => $tenant,
            'PATCH tenants/{id}' => $tenant,
            'POST tenants/{id}/login' => ['enabled' => true, 'email' => 'brian.signs.in@example.com', 'phone' => '0772100062', 'password' => 'Fresh-pass-2026'],
            'POST leases' => ['unit_id' => $w['units'][2]->id, 'tenant' => $tenant, 'start_date' => '2026-04-01', 'rent_minor' => 300000, 'cycle_months' => 1, 'anchor' => 'start', 'end_date' => '2027-03-31', 'deposit_minor' => 300000, 'deposit_held_by' => 'agency',
                'commission' => ['type' => 'percent', 'bp' => 1000, 'minor' => 0], 'late_fee' => 'default', 'items' => [['name' => 'Garbage', 'amount_minor' => 10000, 'goes_to' => 'agency', 'commissionable' => false]], 'notes' => 'A note',
                'first_payment' => ['amount_minor' => 100000, 'method' => 'cash', 'reference' => 'FP', 'paid_on' => '2026-04-02', 'uuid' => (string) Str::uuid()]],
            'POST leases/{id}/notice' => ['notice_on' => '2026-04-02', 'move_out_on' => '2026-05-31'],
            'POST leases/{id}/withdraw-notice' => [],
            'POST leases/{id}/renew' => ['end_date' => '2027-12-31'],
            'POST leases/{id}/move-out' => ['date' => '2026-04-02', 'readings' => [['utility' => 'water', 'reading' => '20']], 'damages' => [['description' => 'A window', 'amount_minor' => 5000, 'goes_to' => 'landlord']], 'unused_days' => true, 'refund_deposit' => 'cash', 'refund_credit' => 'cash', 'reason' => 'Moved away'],
            'POST leases/{id}/move-out-preview' => ['date' => '2026-04-02', 'readings' => [['utility' => 'water', 'reading' => '20']], 'damages' => [['description' => 'A window', 'amount_minor' => 5000, 'goes_to' => 'landlord']], 'unused_days' => true, 'refund_deposit' => 'cash', 'refund_credit' => 'cash', 'reason' => 'Moved away'],
            'POST leases/move-in-preview' => ['start_date' => '2026-04-15', 'rent_minor' => 300000, 'cycle_months' => 1, 'anchor' => 'first', 'end_date' => '2027-03-31', 'deposit_minor' => 300000, 'items' => [['name' => 'Garbage', 'amount_minor' => 10000]]],
            'POST leases/{id}/commission' => ['type' => 'percent', 'bp' => 1200, 'minor' => 0, 'effective_from' => '2026-06-01', 'reason' => 'Agreed'],
            'POST leases/{id}/cancel' => ['reason' => 'Typed by mistake'],
            'POST leases/{id}/refund-deposit' => ['method' => 'cash', 'amount_minor' => 1000, 'on' => '2026-04-02'],
            'POST leases/{id}/refund-credit' => ['method' => 'cash', 'amount_minor' => 1000, 'on' => '2026-04-02'],
            'POST leases/{id}/rent-change' => ['rent_minor' => 350000, 'effective_from' => '2026-06-01', 'reason' => 'Agreed'],
            'POST leases/{id}/items' => ['name' => 'Security', 'amount_minor' => 5000, 'goes_to' => 'agency', 'commissionable' => false, 'starts_on' => '2026-06-01', 'ends_on' => '2026-12-31'],
            'POST leases/{id}/items/{item}/end' => ['ends_on' => '2026-04-30'],
            'POST charges' => ['lease_id' => $w['brian']->id, 'kind' => 'one_off', 'description' => 'A lost key', 'amount_minor' => 5000, 'due_date' => '2026-04-02', 'goes_to' => 'landlord', 'commissionable' => true],
            'POST charges/{id}/void' => ['reason' => 'Typed twice'],
            'POST charges/{id}/waive' => ['amount_minor' => 1000, 'reason' => 'Goodwill'],
            'POST billing/run' => ['lease_id' => $w['brian']->id],
            'POST payments' => ['uuid' => (string) Str::uuid(), 'lease_id' => $w['brian']->id, 'amount_minor' => 5000, 'method' => 'cash', 'paid_on' => '2026-04-02', 'reference' => 'RX', 'notes' => 'A note', 'device_id' => 'phone-1', 'split' => [['charge_id' => $w['openCharge']->id, 'amount_minor' => 5000]]],
            'POST payments/{id}/reverse' => ['reason' => 'Bounced'],
            'POST follow-ups' => ['lease_id' => $w['brian']->id, 'channel' => 'call', 'note' => 'Rang', 'promise_on' => '2026-04-20', 'uuid' => (string) Str::uuid()],
            'POST search/opened' => ['kind' => 'property', 'id' => $w['p']->id],
            'POST search/pin' => ['kind' => 'property', 'id' => $w['p']->id],
            'POST expenses' => ['property_id' => $w['p']->id, 'unit_id' => $w['units'][0]->id, 'category' => 'Repairs', 'description' => 'Tap', 'vendor' => 'Plumber', 'amount_minor' => 900, 'method' => 'cash', 'paid_on' => '2026-04-02', 'charged_to' => 'landlord', 'receipt_uuid' => $w['photoUuid'], 'maintenance_id' => $w['urgentHere']->id, 'over_limit_reason' => 'Urgent'],
            'POST expenses/{id}/reverse' => ['reason' => 'Typed twice'],
            'POST cash/transfers' => ['from' => 'cash', 'to' => 'bank', 'amount_minor' => 1000, 'on' => '2026-04-02', 'note' => 'Banked'],
            'POST cash/opening' => ['method' => 'bank', 'amount_minor' => 1000, 'on' => '2026-04-01', 'replace' => false],
            'POST documents' => ['owner_type' => 'lease', 'owner_id' => $w['amina']->id, 'file_uuid' => $w['photoUuid'], 'name' => 'Agreement', 'kind' => 'agreement'],
            'DELETE documents/{id}' => [],
            'POST maintenance' => ['uuid' => (string) Str::uuid(), 'property_id' => $w['p']->id, 'unit_id' => $w['units'][0]->id, 'title' => 'A dripping tap', 'details' => 'In the kitchen', 'priority' => 'normal', 'photos' => [$w['photoUuid']], 'assigned_to_id' => $w['caretaker']->id, 'assignee_name' => 'A plumber'],
            'PUT maintenance/{id}' => ['status' => 'in_progress', 'priority' => 'high', 'title' => 'Roof', 'details' => 'Leaks', 'assigned_to_id' => $w['caretaker']->id, 'assignee_name' => 'A roofer', 'photos' => [$w['photoUuid']], 'quote_minor' => 900],
            'PATCH maintenance/{id}' => ['status' => 'done', 'cost_minor' => 500, 'category' => 'Repairs', 'vendor' => 'A roofer', 'method' => 'cash', 'paid_on' => '2026-04-02', 'charged_to' => 'landlord', 'receipt_uuid' => $w['photoUuid'], 'resolved_on' => '2026-04-02', 'resolution' => 'Fixed', 'over_limit_reason' => 'Urgent'],
            'POST meter-readings' => ['uuid' => (string) Str::uuid(), 'unit_id' => $w['units'][0]->id, 'utility' => 'water', 'reading' => '25', 'read_on' => '2026-04-02', 'reset' => false, 'photo_uuid' => $w['photoUuid'], 'notes' => 'A note'],
            'POST me/logout' => [],
            'POST me/password' => ['password' => 'Fresh-pass-2026'],
            'POST me/paid-reports' => ['uuid' => (string) Str::uuid(), 'amount_minor' => 5000, 'method' => 'cash', 'reference' => 'RX', 'paid_on' => '2026-04-02', 'note' => 'A note', 'lease_id' => $w['amina']->id],
            'POST me/maintenance' => ['uuid' => (string) Str::uuid(), 'lease_id' => $w['amina']->id, 'title' => 'A leak', 'details' => 'In the kitchen', 'priority' => 'normal', 'photos' => []],
            'POST me/notice' => ['lease_id' => $w['amina']->id, 'move_out_on' => '2026-06-01', 'notes' => 'Moving', 'uuid' => (string) Str::uuid()],
            'POST me/approvals/{id}' => ['yes' => false, 'note' => 'Too dear'],
            'POST me/files' => [], // multipart: test_uploading_a_file_refuses_anything_but_a_file
            'POST portal-auth/login' => ['login' => 'nobody@example.com', 'password' => 'wrong-pass-1', 'device_name' => 'phone'],
            'POST portal-auth/token' => ['ticket' => 'no-such-ticket', 'company_id' => $w['company']->id, 'subject_type' => 'tenant', 'subject_id' => $w['amina']->tenant_id, 'device_name' => 'phone'],
            'POST portal-auth/code' => ['login' => 'nobody@example.com'],
            'POST portal-auth/code/verify' => ['login' => 'nobody@example.com', 'code' => '000000', 'device_name' => 'phone'],
            'POST portal-auth/demo' => ['role' => 'tenant', 'account' => 'agency', 'device_name' => 'phone'],
        ];
    }

    /** The write routes, each with the path and body it runs with. @return list<array<string, mixed>> */
    private function writes(array $w): array
    {
        $bodies = $this->bodies($w);
        $out = [];
        foreach ($this->routes() as $r) {
            if ($r['method'] === 'GET') {
                continue;
            }
            $key = $r['method'].' '.$r['short'];
            $this->assertArrayHasKey($key, $bodies, "A new write route: give {$key} its body in bodies(), so bad input reaches it.");
            $out[] = $r + ['key' => $key, 'path' => '/api/v1/rentals/'.$this->path($r['method'], $r['short'], $w), 'body' => $bodies[$key]];
        }

        return $out;
    }

    /** Run a request and roll back whatever it wrote. */
    private function tried(string $method, string $path, array $body, array $headers, ?callable $after = null): array
    {
        DB::beginTransaction();
        try {
            $res = $this->json($method, $path, $body, $headers);
            $seen = $after ? $after($res) : null;
        } finally {
            DB::rollBack();
        }

        return [$res, $seen];
    }

    /** What the account's records hold now, as one figure per table: a refused request must leave it as it was. */
    private function books(int $cid): string
    {
        static $sql = null;
        if ($sql === null) {
            $parts = [];
            foreach (['rental_entries', 'rental_payments', 'rental_allocations', 'rental_charges', 'rental_leases', 'rental_tenants', 'rental_units', 'rental_properties', 'rental_landlords', 'rental_payouts', 'rental_payout_runs', 'rental_payout_run_lines',
                'rental_expenses', 'rental_maintenance', 'rental_meter_readings', 'rental_follow_ups', 'rental_lease_items', 'rental_rent_changes', 'rental_documents', 'rental_paid_reports', 'rental_statements', 'rental_closes', 'rental_logins',
                'rental_property_staff', 'rental_property_owners', 'rental_settings', 'rental_activities'] as $table) {
                $cols = implode(',', array_map(fn ($c) => "`{$c}`", Schema::getColumnListing($table)));
                $parts[] = "SELECT '{$table}' AS t, COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|',{$cols}))),0) AS h FROM `{$table}` WHERE company_id = :c{$table}";
            }
            $sql = implode(' UNION ALL ', $parts);
        }
        $bind = [];
        foreach (explode(' UNION ALL ', $sql) as $part) {
            preg_match('/:c(\w+)$/', $part, $m);
            $bind['c'.$m[1]] = $cid;
        }

        return (string) json_encode(DB::select($sql, $bind));
    }

    /** @return array<string, mixed> name => what a leaf becomes */
    private function variants(): array
    {
        return ['array' => ['x'], 'map' => ['a' => ['b' => 'c']], 'empty_array' => [], 'null' => null, 'true' => true, 'int' => 12345, 'float' => 1.5, 'negative' => -5, 'huge_int' => PHP_INT_MAX, 'huge_float' => 1e30, 'digits' => '99999999999999999999',
            'text' => 'abc', 'digits_text' => '12abc', 'big_string' => str_repeat('x', 102400), 'emoji' => '🏠🔥 Ñandú', 'sql' => "'; DROP TABLE rental_payments; -- \" OR 1=1", 'html' => '<script>alert(1)</script>',
            'zero_date' => '0000-00-00', 'bad_date' => '2026-13-45', 'feb31' => '2026-02-31', 'far_date' => '9999-12-31', 'old_date' => '0001-01-01', 'empty' => '', 'space' => '   ', 'nul' => "a\0b"];
    }

    /** The dot paths of every leaf and list in a body. @return list<string> */
    private function leaves(array $body, string $prefix = ''): array
    {
        $out = [];
        foreach ($body as $k => $v) {
            $path = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            $out[] = $path;
            if (is_array($v)) {
                $out = [...$out, ...$this->leaves($v, $path)];
            }
        }

        return $out;
    }

    // ── 1, 3. No token, and the wrong kind of token ────────────────────────

    public function test_no_token_is_401_and_the_public_routes_are_only_those_on_the_list(): void
    {
        $w = $this->sweepWorld();
        $bad = [];
        $publicSeen = [];
        foreach ($this->routes() as $r) {
            $path = '/api/v1/rentals/'.$this->path($r['method'], $r['short'], $w);
            $res = $this->json($r['method'], $path, []);
            if ($r['kind'] === 'public') {
                $publicSeen[] = $r['method'].' rentals/'.$r['short'];
                // A signed route with no signature is 403 and hands over nothing; sign-in with an empty body is 422 (a demo with none on this server, 404).
                $want = str_contains($r['short'], 'portal-auth') ? [422, 404] : [403];
                if (! in_array($res->getStatusCode(), $want, true)) {
                    $bad[] = "{$r['method']} {$r['short']}: ".$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 160);
                }

                continue;
            }
            if ($res->getStatusCode() !== 401 || $res->json('code') !== 0) {
                $bad[] = "{$r['method']} {$r['short']}: ".$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 160);
            }
        }
        $this->assertSame([], $bad);
        $this->assertEqualsCanonicalizing(array_keys(self::PUBLIC), $publicSeen, 'the allow-list holds no route that is gone');
    }

    public function test_a_person_token_on_a_staff_route_and_a_staff_token_on_a_person_route_are_refused(): void
    {
        $w = $this->sweepWorld();
        $bad = [];
        foreach ($this->routes() as $r) {
            if ($r['kind'] === 'public') {
                continue;
            }
            $path = '/api/v1/rentals/'.$this->path($r['method'], $r['short'], $w);
            $callers = $r['kind'] === 'staff' ? ['tenant' => $w['tenantH'], 'landlord' => $w['landlordH']] : ['staff' => $w['tokens']['owner'], 'caretaker' => $w['tokens']['caretaker'], 'stranger' => $w['tokens']['stranger']];
            foreach ($callers as $who => $h) {
                $before = $this->books((int) $w['company']->id);
                [$res] = $this->tried($r['method'], $path, ['amount_minor' => 1000, 'lease_id' => $w['amina']->id, 'method' => 'cash'], $h);
                if ($res->getStatusCode() !== 401) {
                    $bad[] = "{$who} on {$r['method']} {$r['short']}: ".$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 160);
                }
                $this->assertSame($before, $this->books((int) $w['company']->id));
            }
        }
        $this->assertSame([], $bad);

        // A person's route for the other kind of person is simply not there.
        foreach ($this->routes() as $r) {
            if ($r['kind'] !== 'person') {
                continue;
            }
            $path = '/api/v1/rentals/'.$this->path($r['method'], $r['short'], $w);
            $body = $this->bodies($w)[$r['method'].' '.$r['short']] ?? [];
            if (! $this->forKind($r['short'], 'tenant')) {
                [$res] = $this->tried($r['method'], $path, $body, $w['tenantH']);
                $this->assertSame(404, $res->getStatusCode(), "a tenant on {$r['short']}: ".$res->getContent());
            }
            if (! $this->forKind($r['short'], 'landlord') && $r['short'] !== 'me/files') {
                [$res] = $this->tried($r['method'], $path, $body, $w['landlordH']);
                $this->assertSame(404, $res->getStatusCode(), "a landlord on {$r['short']}: ".$res->getContent());
            }
        }
    }

    // ── 2. Another account ─────────────────────────────────────────────────

    public function test_another_accounts_staff_with_every_permission_gets_nothing_of_this_account(): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        $stranger = $w['tokens']['stranger'];
        $bodies = $this->bodies($w);
        $markers = ['Kato Plaza', 'Lake View', 'Amina Fictional', 'Brian Sample', 'Carol Madeup', 'Margaret Fictional', 'Peter Sample', 'REF-SWEEP-1', 'Kato Sample Agents'];
        $filters = http_build_query(['lease_id' => $w['amina']->id, 'tenant_id' => $w['amina']->tenant_id, 'property_id' => $w['p']->id, 'unit_id' => $w['units'][0]->id, 'landlord_id' => $w['margaret']->id, 'owner_type' => 'lease', 'owner_id' => $w['amina']->id, 'per_page' => 100]);
        $before = $this->books($cid);
        $bad = [];
        foreach ($this->routes() as $r) {
            if ($r['kind'] !== 'staff') {
                continue;
            }
            $path = '/api/v1/rentals/'.$this->path($r['method'], $r['short'], $w);
            $body = $bodies[$r['method'].' '.$r['short']] ?? [];
            $hasId = str_contains($r['short'], '{');
            [$res] = $this->tried($r['method'], $r['method'] === 'GET' && ! $hasId ? $path.'?'.$filters : $path, $body, $stranger);
            $code = $res->getStatusCode();
            $text = (string) $res->getContent();
            foreach ($markers as $m) {
                if (str_contains($text, $m)) {
                    $bad[] = "{$r['method']} {$r['short']} shows {$m}";
                }
            }
            if ($code >= 500) {
                $bad[] = "{$r['method']} {$r['short']}: {$code}";
            }
            if ($hasId && ! in_array($code, [403, 404], true) && ! str_starts_with($r['short'], 'reports/')) {
                $bad[] = "{$r['method']} {$r['short']} on this account's id: {$code} ".substr($text, 0, 160);
            }
        }
        $this->assertSame([], $bad);
        $this->assertSame($before, $this->books($cid), 'nothing here moved');

        // A stranger's own tokens signed for this account's ids get nothing either: a signed link names its company, and checks the person.
        $link = \App\Services\Rentals\RentalLinks::receiptPdf($w['payment'], 'staff', (int) $w['x']['owner']->id)['url'];
        $this->get(str_replace(config('app.url'), '', $link))->assertStatus(404);
    }

    // ── 4. No rentals permission at all ────────────────────────────────────

    public function test_a_login_with_no_rentals_permission_is_refused_everywhere(): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        $bare = $this->rentalsStaff($w['company'], 'viewer');
        foreach (['rentals_view', 'rentals_reports'] as $permission) {
            DB::table('company_role_permissions')->insert(['company_id' => $cid, 'role' => 'viewer', 'permission' => $permission, 'allowed' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
        \App\Services\Team\Permissions::flush();
        $h = $this->h($bare);
        $bodies = $this->bodies($w);
        $before = $this->books($cid);
        $bad = [];
        foreach ($this->routes() as $r) {
            if ($r['kind'] !== 'staff') {
                continue;
            }
            [$res] = $this->tried($r['method'], '/api/v1/rentals/'.$this->path($r['method'], $r['short'], $w), $bodies[$r['method'].' '.$r['short']] ?? [], $h);
            if ($res->getStatusCode() !== 403 || $res->json('errors.code') !== 'forbidden') {
                $bad[] = "{$r['method']} {$r['short']}: ".$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 200);
            }
        }
        $this->assertSame([], $bad);
        $this->assertSame($before, $this->books($cid));
    }

    // ── 5. Ids that are not ids ────────────────────────────────────────────

    public function test_an_id_that_is_not_there_or_not_an_id_is_404_or_422(): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        $bodies = $this->bodies($w);
        $bad = [];
        $tried = 0;
        foreach ($this->routes() as $r) {
            if (! preg_match('/\{(id|item)\}/', $r['short'])) {
                continue;
            }
            foreach (['99999998', '0', '-1', '99999999999999999999', 'abc', '1e5', '0x10', '1.5', '%00', '12abc'] as $id) {
                foreach (array_unique(['{id}', str_contains($r['short'], '{item}') ? '{item}' : '{id}']) as $param) {
                    $good = $this->path($r['method'], $r['short'], $w);
                    $template = explode('/', $r['short']);
                    $parts = explode('/', $good);
                    foreach ($template as $i => $seg) {
                        if ($seg === $param) {
                            $parts[$i] = $id;
                        }
                    }
                    $h = $this->caller($r, $w);
                    [$res] = $this->tried($r['method'], '/api/v1/rentals/'.implode('/', $parts), $bodies[$r['method'].' '.$r['short']] ?? [], $h);
                    $tried++;
                    if (! in_array($res->getStatusCode(), [404, 422], true)) {
                        $bad[] = "{$r['method']} {$r['short']} with {$param}={$id}: ".$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 200);
                    }
                    if ($res->json('code') !== 0) {
                        $bad[] = "{$r['method']} {$r['short']} with {$param}={$id}: not the envelope";
                    }
                }
            }
        }
        $this->assertGreaterThan(300, $tried);
        $this->assertSame([], $bad);
        $this->assertBooksAgree($cid);
    }

    // ── 6. Bad bodies ──────────────────────────────────────────────────────

    public function test_every_write_route_takes_its_good_body(): void
    {
        $w = $this->sweepWorld();
        $bad = [];
        foreach ($this->writes($w) as $r) {
            if ($r['kind'] === 'public' || $r['short'] === 'me/files') {
                continue;
            }
            $h = $this->caller($r, $w);
            [$res, $problems] = $this->tried($r['method'], $r['path'], $r['body'], $h, fn () => app(\App\Services\Rentals\Jobs\RentalCheck::class)->run((int) $w['company']->id));
            if ($res->getStatusCode() >= 300) {
                $bad[] = "{$r['key']}: ".$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 300);
            }
            if ($problems !== []) {
                $bad[] = "{$r['key']} left the books wrong: ".json_encode($problems);
            }
        }
        $this->assertSame([], $bad, "\n".implode("\n", $bad));
    }

    /** @return array<string, array{0: int}> */
    public static function chunks(): array
    {
        return ['part 1' => [0], 'part 2' => [1], 'part 3' => [2], 'part 4' => [3], 'part 5' => [4], 'part 6' => [5], 'part 7' => [6], 'part 8' => [7]];
    }

    /**
     * @dataProvider chunks
     */
    public function test_each_field_of_each_write_route_survives_nonsense(int $chunk): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        $bad = [];
        $before = $this->books($cid);
        $variants = $this->variants();
        foreach ($this->writes($w) as $i => $r) {
            if ($i % 8 !== $chunk || $r['short'] === 'me/files') {
                continue;
            }
            $h = $this->caller($r, $w);
            $fuzz = str_contains($r['short'], 'portal-auth');
            // An empty body, then each leaf as each nonsense.
            [$res] = $this->tried($r['method'], $r['path'], [], $h);
            if ($res->getStatusCode() >= 500) {
                $bad[] = "{$r['key']} with an empty body: ".substr((string) $res->getContent(), 0, 240);
            }
            foreach ($this->leaves($r['body']) as $leaf) {
                $name = (string) last(explode('.', $leaf));
                foreach ($variants as $variantName => $value) {
                    $body = $r['body'];
                    data_set($body, $leaf, $value);
                    [$res, $after] = $this->tried($r['method'], $r['path'], $body, $h, fn ($res) => $res->getStatusCode() >= 400 && ! $fuzz ? $this->books($cid) : null);
                    $status = $res->getStatusCode();
                    $where = "{$r['key']} {$leaf}={$variantName}";
                    if ($status >= 500) {
                        $bad[] = "{$where}: {$status}";

                        continue;
                    }
                    if (! is_array($res->json()) || ! array_key_exists('code', $res->json())) {
                        $bad[] = "{$where}: not the envelope: ".substr((string) $res->getContent(), 0, 120);
                    }
                    if ($after !== null && $after !== $before) {
                        $bad[] = "{$where}: refused ({$status}) but wrote something";
                    }
                    $isMoney = str_ends_with($name, '_minor');
                    if ($isMoney && in_array($variantName, self::BAD_MONEY, true) && $status < 400) {
                        $bad[] = "{$where}: taken ({$status})";
                    }
                    if (in_array($name, self::DATE_FIELDS, true) && in_array($variantName, self::BAD_DATE, true) && $status < 400) {
                        $bad[] = "{$where}: taken ({$status})";
                    }
                }
            }
        }
        $this->assertSame([], array_slice($bad, 0, 200), count($bad).' problems');
        $this->assertSame($before, $this->books($cid));
        $this->assertBooksAgree($cid);
    }

    /** Foreign records in a body: every field that names a record, pointed at the other account's. Mass assignment: fields no route may take. */
    public function test_a_record_of_another_account_in_a_body_is_refused_and_extra_fields_change_nothing(): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        $x = $w['x'];
        $xid = (int) $x['company']->id;
        $foreign = ['lease_id' => $x['lease']->id, 'unit_id' => $x['units'][1]->id, 'tenant_id' => $x['tenant_id'], 'property_id' => $x['p']->id, 'landlord_id' => $x['landlord_id'], 'charge_id' => $x['charge']->id, 'maintenance_id' => $x['ticket']->id,
            'assigned_to_id' => $x['owner']->id, 'owner_id' => $x['lease']->id, 'user_ids' => [$x['owner']->id], 'file_uuid' => $x['file']];
        $otherBooks = $this->books($xid);
        $bad = [];
        $tested = 0;
        foreach ($this->writes($w) as $r) {
            if ($r['kind'] === 'public' || $r['short'] === 'me/files') {
                continue;
            }
            $h = $this->caller($r, $w);
            foreach ($this->leaves($r['body']) as $leaf) {
                $name = (string) last(explode('.', $leaf));
                if (! array_key_exists($name, $foreign) && $name !== 'photo_uuid' && $name !== 'receipt_uuid' && $name !== 'photos') {
                    continue;
                }
                $value = $foreign[$name] ?? ($name === 'photos' ? [$x['file']] : $x['file']);
                $body = $r['body'];
                data_set($body, $leaf, $value);
                $before = $this->books($cid);
                [$res, $after] = $this->tried($r['method'], $r['path'], $body, $h, fn ($res) => [$this->books($cid), json_encode($res->json('data'))]);
                $tested++;
                $status = $res->getStatusCode();
                $where = "{$r['key']} {$leaf}";
                if ($status >= 500) {
                    $bad[] = "{$where}: {$status}";
                } elseif (in_array($name, ['photo_uuid', 'receipt_uuid', 'photos'], true)) {
                    // A file of another account is never linked, whatever the row says.
                    if ($status < 400 && str_contains((string) $after[1], $x['file'])) {
                        $bad[] = "{$where}: the answer names the other account's file";
                    }
                } elseif ($status < 400) {
                    $bad[] = "{$where}: taken ({$status}) ".substr((string) $res->getContent(), 0, 160);
                } elseif ($after[0] !== $before) {
                    $bad[] = "{$where}: refused but wrote something";
                }
                $this->assertSame($otherBooks, $this->books($xid), "{$where} touched the other account");
            }

            // Fields no body may set: the account, the row's own id, its running figures.
            $extra = ['company_id' => $xid, 'id' => 999999, 'created_by_id' => $x['owner']->id, 'closed_through' => '2030-01-01', 'is_self' => true, 'paid_minor' => 999, 'unallocated_minor' => 999, 'seq_receipt' => 999, 'setup_at' => '2001-01-01', 'import' => ['as_of' => '2026-04-01', 'opening_owed_minor' => 999], 'origin' => 'sitting', 'source' => 'import', 'reversed_at' => '2001-01-01', 'run_id' => 999];
            [$res, $seen] = $this->tried($r['method'], $r['path'], $extra + $r['body'], $h, fn ($res) => $res->getStatusCode() < 300 ? [
                DB::table('rental_settings')->where('company_id', $cid)->value('closed_through'),
                DB::table('rental_settings')->where('company_id', $cid)->value('seq_receipt'),
                $this->books($xid),
                DB::table('rental_landlords')->where('company_id', $cid)->where('is_self', true)->count(),
                DB::table('rental_payments')->where('company_id', $cid)->whereNotNull('reversed_at')->where('reversed_at', '<', '2002-01-01')->count(),
                DB::table('rental_charges')->where('company_id', $cid)->where('source', 'import')->count(),
            ] : null);
            if ($seen !== null && ! str_starts_with($r['short'], 'month-end/')) {
                [$closed, , $books, $selves, $old, $imported] = $seen;
                if (substr((string) $closed, 0, 10) !== '2026-02-28') {
                    $bad[] = "{$r['key']}: closed_through became {$closed}";
                }
                if ($books !== $otherBooks || $selves !== 0 || $old !== 0 || $imported !== 0) {
                    $bad[] = "{$r['key']}: an extra field wrote something";
                }
            }
        }
        $this->assertGreaterThan(25, $tested);
        $this->assertSame([], $bad, "\n".implode("\n", $bad));
        $this->assertBooksAgree($cid);
    }

    /** A value that only looks like a lease's id must not settle on some other lease: an id in a list, a float, text after the digits. */
    public function test_an_id_in_a_body_is_read_whole_or_refused(): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        $h = $w['tokens']['owner'];
        $good = (int) $w['brian']->id;
        $routes = ['payments' => ['amount_minor' => 1000, 'method' => 'cash'], 'charges' => ['amount_minor' => 1000, 'description' => 'x'], 'follow-ups' => ['channel' => 'note']];
        foreach ($routes as $path => $body) {
            foreach ([[$good], ['id' => $good], "{$good}abc", $good + 0.9, true, ' '.$good.'x'] as $bad) {
                $before = $this->books($cid);
                $res = $this->postJson("/api/v1/rentals/{$path}", ['lease_id' => $bad] + $body, $h);
                $this->assertContains($res->getStatusCode(), [404, 422], "{$path} lease_id=".json_encode($bad).': '.$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 200));
                $this->assertSame($before, $this->books($cid));
            }
            $this->assertLessThan(300, $this->tried('POST', "/api/v1/rentals/{$path}", ['lease_id' => (string) $good] + $body, $h)[0]->getStatusCode(), 'digits as text are still its id');
        }
        foreach ([[$w['margaret']->id], "{$w['margaret']->id}abc", true] as $bad) {
            $res = $this->postJson('/api/v1/rentals/payouts', ['landlord_id' => $bad, 'amount_minor' => 1000, 'method' => 'cash'], $h);
            $this->assertContains($res->getStatusCode(), [404, 422], 'payouts landlord_id='.json_encode($bad));
        }
    }

    public function test_uploading_a_file_refuses_anything_but_a_file(): void
    {
        $w = $this->sweepWorld();
        $h = $w['tenantH'];
        $url = '/api/v1/rentals/me/files';
        $this->postJson($url, [], $h)->assertStatus(422);
        $this->postJson($url, ['uuid' => 'nope'], $h)->assertStatus(422);
        $this->postJson($url, ['uuid' => ['x'], 'file' => ['y']], $h)->assertStatus(422);
        $this->post($url, ['uuid' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('evil.php', 10, 'text/x-php')], $h + ['Accept' => 'application/json'])->assertStatus(422);
        $this->post($url, ['uuid' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('huge.pdf', 20000, 'application/pdf')], $h + ['Accept' => 'application/json'])->assertStatus(422);
        $uuid = (string) Str::uuid();
        $one = $this->post($url, ['uuid' => $uuid, 'file' => UploadedFile::fake()->image('tap.jpg')], $h + ['Accept' => 'application/json'])->assertStatus(201);
        $this->assertSame(['file_uuid', 'purpose'], array_keys($one->json('data')), 'never a path');
        $this->post($url, ['uuid' => $uuid, 'file' => UploadedFile::fake()->image('tap.jpg')], $h + ['Accept' => 'application/json'])->assertStatus(200);
        $this->assertSame(1, StoredFile::withoutGlobalScopes()->where('uuid', $uuid)->count());
        // A landlord uploads nothing here; another tenant cannot take the same id.
        $this->post($url, ['uuid' => (string) Str::uuid(), 'file' => UploadedFile::fake()->image('tap.jpg')], $w['landlordH'] + ['Accept' => 'application/json'])->assertStatus(404);
        $this->post($url, ['uuid' => $uuid, 'file' => UploadedFile::fake()->image('tap.jpg')], $w['tenant2H'] + ['Accept' => 'application/json'])->assertStatus(409);
    }

    // ── 5b. Nonsense in a query ────────────────────────────────────────────

    public function test_every_read_survives_nonsense_in_its_query(): void
    {
        $w = $this->sweepWorld();
        $queries = ['page=99999999999999999999', 'page=-1', 'page=abc', 'page[]=1', 'per_page=-1', 'per_page=0', 'per_page=99999999', 'per_page=abc', 'per_page[]=1',
            'q[]=x', 'q[a][b]=x', 'q=%FF%FE', 'q=%00', 'q='.urlencode("'; DROP TABLE rental_payments; --"), 'q='.str_repeat('x', 5000), 'q=🏠',
            'from=0000-00-00', 'from=2026-13-45', 'from[]=x', 'to=0000-00-00', 'to=2026-02-31', 'to[]=x', 'from=2027-01-01&to=2026-01-01', 'from=0001-01-01&to=9999-12-31',
            'month=2026-13', 'month[]=x', 'month=0000-00', 'month=%FF', 'as_of=0000-00-00', 'as_of[]=x', 'on=abc', 'year=abc', 'year[]=1', 'year=99999999999', 'days=abc', 'days[]=1', 'days=-5', 'days=99999999999',
            'lease_id[]=x', 'lease_id=abc', 'lease_id=99999999999999999999', 'property_id[]=x', 'property_id=99999999999999999999', 'landlord_id[]=x', 'landlord_id=99999999999999999999', 'tenant_id[]=x', 'unit_id[]=x',
            'status[]=x', 'status=%FF', 'kind[]=x', 'state[]=x', 'method[]=x', 'format=evil', 'format[]=x', 'above=abc', 'above[]=x', 'above=-5', 'amount_minor=abc', 'amount_minor[]=x', 'amount_minor=-5', 'amount_minor=99999999999999999999',
            'owner_type[]=x', 'owner_id[]=x', 'owner_type=lease&owner_id=99999999999999999999', 'priority[]=x', 'utility[]=x', 'category[]=x', 'charged_to[]=x', 'bucket[]=x', 'sort[]=x', 'min_days[]=x', 'min_days=abc', 'promise_on[]=x', 'promise_on=0000-00-00',
            'subject_type[]=x', 'subject_id[]=x', 'action[]=x', 'actor_type[]=x', 'view[]=x', 'check[]=x', 'maintenance_id[]=x', 'overdue[]=x'];
        $bad = [];
        $ask = function (string $path, array $h, string $query, string $where) use (&$bad) {
            $started = microtime(true);
            $res = $this->getJson($path.'?'.$query, $h);
            if ($res->getStatusCode() >= 500) {
                $bad[] = "{$where}?".substr($query, 0, 60).': '.$res->getStatusCode();
            }
            if ($res->getStatusCode() !== 304 && (! is_array($res->json()) || ! array_key_exists('code', $res->json()))) {
                $bad[] = "{$where}?".substr($query, 0, 60).': not the envelope';
            }
            if (microtime(true) - $started > 10) {
                $bad[] = "{$where}?".substr($query, 0, 60).': took '.round(microtime(true) - $started).' seconds';
            }
        };
        foreach ($this->routes() as $r) {
            if ($r['method'] !== 'GET' || $r['kind'] === 'public' || str_starts_with($r['short'], 'reports/')) {
                continue;
            }
            $h = $this->caller($r, $w);
            $path = '/api/v1/rentals/'.$this->path('GET', $r['short'], $w);
            foreach ($queries as $query) {
                $ask($path, $h, $query, "GET {$r['short']}");
            }
        }
        // Every report, with the filters reports read.
        $forReports = ['from=0000-00-00', 'from=2026-13-45', 'from[]=x', 'to[]=x', 'from=0001-01-01&to=9999-12-31', 'from=2027-01-01&to=2026-01-01', 'month=2026-13', 'month[]=x', 'month=0000-00', 'year=abc', 'year[]=1', 'year=9999', 'year=0000', 'days=abc', 'days[]=1', 'days=99999999999',
            'as_of=0000-00-00', 'as_of[]=x', 'lease_id[]=x', 'lease_id=99999999999999999999', 'property_id[]=x', 'landlord_id[]=x', 'q[]=x', 'q=%FF%FE', 'status[]=x', 'state[]=x', 'method[]=x', 'category[]=x', 'bucket[]=x', 'view[]=x'];
        foreach (\App\Services\Rentals\Reports\RentalReports::keys() as $key) {
            foreach ($forReports as $query) {
                $ask("/api/v1/rentals/reports/{$key}", $w['tokens']['owner'], $query, "report {$key}");
            }
        }
        $this->assertSame([], array_slice($bad, 0, 200), count($bad).' problems');
    }

    // ── 7. The same request twice ──────────────────────────────────────────

    public function test_the_same_idempotency_key_replays_on_every_staff_write_and_another_body_is_refused(): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        $h = $w['tokens']['owner'];
        $bad = [];
        foreach ($this->writes($w) as $r) {
            if ($r['kind'] !== 'staff') {
                continue;
            }
            DB::beginTransaction();
            try {
                $key = ['Idempotency-Key' => 'sweep-'.md5($r['key'])];
                $first = $this->json($r['method'], $r['path'], $r['body'], $h + $key);
                $books = $this->books($cid);
                $again = $this->json($r['method'], $r['path'], $r['body'], $h + $key);
                if ($first->getStatusCode() >= 300) {
                    $bad[] = "{$r['key']}: the first answer was ".$first->getStatusCode();
                } elseif ($again->getStatusCode() !== $first->getStatusCode() || $again->headers->get('Idempotent-Replay') !== 'true' || $again->getContent() !== $first->getContent()) {
                    $bad[] = "{$r['key']}: the replay differs: ".$again->getStatusCode().' '.substr((string) $again->getContent(), 0, 160);
                }
                if ($this->books($cid) !== $books) {
                    $bad[] = "{$r['key']}: the replay wrote something";
                }
                $other = $this->json($r['method'], $r['path'], $r['body'] + ['zz_other' => 1], $h + $key);
                if ($other->getStatusCode() !== 422 || $other->json('errors.code') !== 'idempotency_key_reused') {
                    $bad[] = "{$r['key']}: the same key with another body: ".$other->getStatusCode();
                }
            } finally {
                DB::rollBack();
            }
        }
        $this->assertSame([], $bad, "\n".implode("\n", $bad));
    }

    public function test_the_same_uuid_is_one_record_and_with_another_body_a_clear_answer(): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        $h = $w['tokens']['owner'];
        $bodies = $this->bodies($w);
        $count = fn (string $table, string $uuid) => DB::table($table)->where('company_id', $cid)->where('uuid', $uuid)->count();
        $cases = [
            ['payments', 'rental_payments', ['lease_id' => $w['brian']->id, 'amount_minor' => 5000, 'method' => 'cash'], ['amount_minor' => 6000], $h],
            ['follow-ups', 'rental_follow_ups', ['lease_id' => $w['brian']->id, 'channel' => 'call', 'note' => 'Rang'], ['lease_id' => $w['carol']->id], $h],
            ['maintenance', 'rental_maintenance', ['unit_id' => $w['units'][0]->id, 'title' => 'A tap'], ['title' => 'Another thing'], $h],
            ['meter-readings', 'rental_meter_readings', ['unit_id' => $w['units'][1]->id, 'utility' => 'water', 'reading' => '30'], ['reading' => '31'], $h],
            ['me/paid-reports', 'rental_paid_reports', ['amount_minor' => 5000, 'method' => 'cash', 'lease_id' => $w['amina']->id], ['amount_minor' => 6000], $w['tenantH']],
            ['me/maintenance', 'rental_maintenance', ['lease_id' => $w['amina']->id, 'title' => 'A leak'], ['title' => 'Something else'], $w['tenantH']],
        ];
        foreach ($cases as [$path, $table, $body, $change, $headers]) {
            $uuid = (string) Str::uuid();
            $url = "/api/v1/rentals/{$path}";
            $first = $this->postJson($url, $body + ['uuid' => $uuid], $headers)->assertStatus(201);
            $id = $first->json('data.id');
            $entries = DB::table('rental_entries')->where('company_id', $cid)->count();
            // Two more, as two phones' retries would.
            foreach ([1, 2] as $try) {
                $again = $this->postJson($url, $body + ['uuid' => $uuid], $headers)->assertStatus(200);
                $this->assertSame($id, $again->json('data.id'), $path);
            }
            $this->assertSame(1, $count($table, $uuid), $path);
            $this->assertSame($entries, DB::table('rental_entries')->where('company_id', $cid)->count(), "{$path}: a replay posted money");
            // The same uuid with another body: the first record, or a conflict; never a second record and never a 500.
            $other = $this->postJson($url, $change + $body + ['uuid' => $uuid], $headers);
            $this->assertContains($other->getStatusCode(), [200, 409], "{$path}: ".$other->getContent());
            if ($other->getStatusCode() === 200) {
                $this->assertSame($id, $other->json('data.id'), $path);
            } else {
                $this->assertSame('idempotency_conflict', $other->json('errors.code'), $path);
            }
            $this->assertSame(1, $count($table, $uuid), $path);
        }

        // The notice, and the first payment of a move-in, carry a uuid too.
        $uuid = (string) Str::uuid();
        $notice = ['lease_id' => $w['amina']->id, 'move_out_on' => '2026-06-01', 'uuid' => $uuid];
        $this->postJson('/api/v1/rentals/me/notice', $notice, $w['tenantH'])->assertStatus(201);
        $this->postJson('/api/v1/rentals/me/notice', $notice, $w['tenantH'])->assertStatus(200);
        $this->assertSame(1, DB::table('rental_activities')->where('company_id', $cid)->where('action', 'tenant.notice_given')->where('subject_id', $w['amina']->id)->count());
        $this->postJson('/api/v1/rentals/me/notice', ['lease_id' => $w['carol']->id, 'move_out_on' => '2027-02-31'], $w['tenant2H'])->assertStatus(422)->assertJsonPath('errors.code', 'date_invalid');
        $uuid = (string) Str::uuid();
        $moveIn = $bodies['POST leases'];
        $moveIn['first_payment']['uuid'] = $uuid;
        $this->postJson('/api/v1/rentals/leases', $moveIn, $h)->assertStatus(201);
        $this->assertSame(1, $count('rental_payments', $uuid));
        $this->assertBooksAgree($cid);
    }

    /** Two phones at once: the second lost the race between looking for the uuid and writing it. The twin is written just before its insert. */
    public function test_two_sends_of_one_uuid_at_the_same_moment_answer_with_the_one_record(): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        $twin = function (string $model, array $row) {
            $fired = false;
            $model::creating(function ($m) use (&$fired, $row) {
                if ($fired) {
                    return;
                }
                $fired = true;
                DB::table($m->getTable())->insert(['uuid' => $m->uuid] + $row);
            });
        };
        $uuid = (string) Str::uuid();
        $twin(\App\Models\RentalPaidReport::class, ['company_id' => $cid, 'login_id' => $w['tenantLogin']->id, 'lease_id' => $w['amina']->id, 'tenant_id' => $w['amina']->tenant_id, 'unit_id' => $w['amina']->unit_id, 'property_id' => $w['amina']->property_id, 'amount_minor' => 5000, 'method' => 'cash', 'paid_on' => '2026-04-02', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $res = $this->postJson('/api/v1/rentals/me/paid-reports', ['amount_minor' => 5000, 'method' => 'cash', 'lease_id' => $w['amina']->id, 'uuid' => $uuid], $w['tenantH']);
        $this->assertContains($res->getStatusCode(), [200, 409], 'paid report: '.substr((string) $res->getContent(), 0, 200));
        $this->assertSame(1, DB::table('rental_paid_reports')->where('company_id', $cid)->where('uuid', $uuid)->count());
        \App\Models\RentalPaidReport::flushEventListeners();
        $this->assertBooksAgree($cid);
    }

    // ── 8. What the answers hold ───────────────────────────────────────────

    /** @param array<string, mixed>|list<mixed>|mixed $value @return list<string> the keys of a JSON answer, everywhere in it */
    private function keysIn(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $out[] = $k;
            }
            $out = [...$out, ...$this->keysIn($v)];
        }

        return array_values(array_unique($out));
    }

    /** Whatever the caller, a JSON answer keeps its envelope and holds no secret. @return list<string> */
    private function hygiene(string $where, mixed $res, array $w, bool $tokenAllowed = false): array
    {
        $bad = [];
        $status = $res->getStatusCode();
        $text = (string) $res->getContent();
        if ($status === 304) {
            return [];
        }
        $json = $res->json();
        if (! is_array($json) || ! array_key_exists('code', $json) || ! array_key_exists('message', $json) || ! array_key_exists('data', $json)) {
            return ["{$where}: not the envelope: ".substr($text, 0, 120)];
        }
        if ($status < 400 && $json['code'] !== 1) {
            $bad[] = "{$where}: success with code ".json_encode($json['code']);
        }
        if ($status >= 400 && ($json['code'] !== 0 || $json['data'] !== null || ! is_string($json['message']) || $json['message'] === '')) {
            $bad[] = "{$where}: refusal not in the envelope: ".substr($text, 0, 160);
        }
        foreach ($this->keysIn($json) as $k) {
            $l = strtolower($k);
            if (in_array($l, ['password', 'password_hash', 'remember_token', 'api_token', 'secret', 'pepper', 'id_number', 'nin', 'path', 'disk', 'stored_path', 'proof_secret', 'otp', 'code_hash'], true) || ($l === 'token' && ! $tokenAllowed)) {
                $bad[] = "{$where}: key {$k}";
            }
        }
        foreach (['CF90SECRET789Z', 'CM99FRESH', 'CM88FRESH', $this->privateRoot, 'SQLSTATE', 'vendor/laravel', '$2y$', 'select * from'] as $never) {
            if (str_contains($text, $never)) {
                $bad[] = "{$where}: shows {$never}";
            }
        }
        if (preg_match('~files/\d+/\d{4}/\d{2}/~', stripslashes($text))) {
            $bad[] = "{$where}: a private file's path";
        }

        return $bad;
    }

    /** Every read, as each person: their answer, for what follows. @return array<string, array<string, mixed>> */
    private function reads(array $w, array $h, string $kind): array
    {
        $out = [];
        foreach ($this->routes() as $r) {
            if ($r['method'] !== 'GET' || $r['kind'] !== $kind) {
                continue;
            }
            $path = '/api/v1/rentals/'.$this->path('GET', $r['short'], $w);
            $query = str_starts_with($r['short'], 'search') ? '?q=Lake' : ($r['short'] === 'documents' ? '?owner_type=tenant&owner_id='.$w['amina']->tenant_id : '?per_page=100');
            $out[$r['short']] = $this->getJson($path.$query, $h);
        }

        return $out;
    }

    public function test_no_answer_holds_a_secret_and_every_answer_keeps_the_envelope(): void
    {
        $w = $this->sweepWorld();
        $bad = [];
        foreach (['owner', 'manager', 'accountant', 'caretaker', 'viewer'] as $role) {
            foreach ($this->reads($w, $w['tokens'][$role], 'staff') as $short => $res) {
                $bad = [...$bad, ...$this->hygiene("{$role} GET {$short}", $res, $w)];
                if ($res->getStatusCode() >= 500) {
                    $bad[] = "{$role} GET {$short}: 500";
                }
            }
        }
        foreach (['tenantH', 'tenant2H', 'landlordH', 'landlord2H'] as $who) {
            foreach ($this->reads($w, $w[$who], 'person') as $short => $res) {
                $bad = [...$bad, ...$this->hygiene("{$who} GET {$short}", $res, $w)];
                if ($res->getStatusCode() >= 500) {
                    $bad[] = "{$who} GET {$short}: 500";
                }
            }
        }
        foreach ($this->writes($w) as $r) {
            if ($r['kind'] === 'public' || $r['short'] === 'me/files') {
                continue;
            }
            [$res] = $this->tried($r['method'], $r['path'], $r['body'], $this->caller($r, $w));
            $bad = [...$bad, ...$this->hygiene($r['key'], $res, $w)];
            [$res] = $this->tried($r['method'], $r['path'], [], $this->caller($r, $w));
            $bad = [...$bad, ...$this->hygiene($r['key'].' empty', $res, $w)];
        }
        $this->assertSame([], $bad, "\n".implode("\n", $bad));
    }

    public function test_private_files_open_only_through_a_signed_link_made_for_that_person(): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        $paper = $w['paperUuid'];
        $this->get("/api/v1/rentals/files/{$paper}?company_id={$cid}&by=staff&by_id=".$w['owner']->id)->assertStatus(403);
        $link = (string) \App\Services\Rentals\RentalLinks::file($cid, $paper, 'staff', (int) $w['owner']->id);
        $this->assertNull(\App\Services\Rentals\RentalLinks::file($cid, $paper), 'never a link for nobody');
        $url = str_replace(config('app.url'), '', $link);
        $this->get($url)->assertOk();
        $this->get(str_replace('by_id='.$w['owner']->id, 'by_id='.$w['viewer']->id, $url))->assertStatus(403);
        $this->get($url.'&x=1')->assertStatus(403);
        // An ID copy is for those who manage tenants: a link made for a viewer opens nothing.
        $this->get(str_replace(config('app.url'), '', (string) \App\Services\Rentals\RentalLinks::file($cid, $paper, 'staff', (int) $w['viewer']->id)))->assertStatus(404);
        // A tenant is never shown papers on a record, and a link does not cross accounts.
        $this->get(str_replace(config('app.url'), '', (string) \App\Services\Rentals\RentalLinks::file($cid, $paper, 'portal', (int) $w['tenantLogin']->id)))->assertStatus(404);
        $this->assertNull(\App\Services\Rentals\RentalLinks::file((int) $w['x']['company']->id, $paper, 'staff', (int) $w['x']['owner']->id));
        // A signed link with a word for its format fails cleanly.
        foreach (['xml', 'evil'] as $format) {
            $u = \App\Services\Rentals\RentalLinks::report($cid, (int) $w['owner']->id, 'rent_roll', $format, []);
            $this->assertLessThan(500, $this->get(str_replace(config('app.url'), '', $u['url']))->getStatusCode(), $format);
        }
        $u = \App\Services\Rentals\RentalLinks::report($cid, (int) $w['owner']->id, 'no_such_report', 'csv', []);
        $this->assertSame(404, $this->get(str_replace(config('app.url'), '', $u['url']))->getStatusCode());
    }

    public function test_a_tenant_reads_only_their_own_and_nothing_between_the_agency_and_the_landlord(): void
    {
        $w = $this->sweepWorld();
        $bad = [];
        $forbiddenKeys = ['commission', 'commission_minor', 'commission_bp', 'commissionable', 'landlord_minor', 'agency_minor', 'landlord_share', 'goes_to', 'landlords_minor', 'balance_minor', 'expense_limit_minor', 'payout_details', 'payout_method', 'follow_ups', 'quote_minor', 'cost_minor', 'expense_id', 'assignee_name'];
        $never = ['Brian Sample', 'Carol Madeup', 'Dan Invented', 'Staff note', 'Peter Sample', 'Lake View', '0772100002', '0772100003', 'Demo Bank', 'Rang him', 'Margaret Fictional'];
        foreach ($this->reads($w, $w['tenantH'], 'person') as $short => $res) {
            if (! $this->forKind($short, 'tenant')) {
                continue;
            }
            $this->assertLessThan(400, $res->getStatusCode(), "tenant GET {$short}: ".substr((string) $res->getContent(), 0, 200));
            $json = $res->json();
            foreach ($this->keysIn($json) as $k) {
                // A null or an empty list is not an answer: the keys a screen reads may stay.
                if (in_array($k, $forbiddenKeys, true) && $this->anyValue($json, $k)) {
                    $bad[] = "{$short}: key {$k}";
                }
            }
            foreach ($never as $word) {
                if (str_contains((string) $res->getContent(), $word)) {
                    $bad[] = "{$short}: shows {$word}";
                }
            }
        }
        // Another tenant's records are not found, by any id.
        foreach (['me/lease/'.$w['carol']->id, 'me/lease/'.$w['carol']->id.'/statement-link', 'me/payments/'.$w['payment']->id, 'me/payments/'.$w['payment']->id.'/receipt'] as $path) {
            $this->getJson("/api/v1/rentals/{$path}", $w['tenantH'])->assertStatus(404);
        }
        $this->assertSame([], $this->getJson('/api/v1/rentals/me/charges?lease_id='.$w['carol']->id, $w['tenantH'])->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/rentals/me/payments?lease_id='.$w['carol']->id, $w['tenantH'])->assertOk()->json('data'));
        $this->postJson('/api/v1/rentals/me/maintenance', ['lease_id' => $w['carol']->id, 'title' => 'x'], $w['tenantH'])->assertStatus(404);
        $this->postJson('/api/v1/rentals/me/notice', ['lease_id' => $w['carol']->id, 'move_out_on' => '2026-06-01'], $w['tenantH'])->assertStatus(404);
        $this->postJson('/api/v1/rentals/me/paid-reports', ['lease_id' => $w['carol']->id, 'amount_minor' => 1000, 'method' => 'cash'], $w['tenantH'])->assertStatus(404);
        // A receipt's words say what was paid, not how it is shared.
        $receipt = (string) $this->getJson('/api/v1/rentals/me/payments/'.$w['aminaPayment']->id.'/receipt', $w['tenantH'])->assertOk()->json('data.text');
        $this->assertStringNotContainsStringIgnoringCase('commission', $receipt);
        $this->assertSame([], $bad, "\n".implode("\n", $bad));
    }

    private function anyValue(mixed $value, string $key): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $k => $v) {
            if ($k === $key && $v !== null && $v !== []) {
                return true;
            }
            if ($this->anyValue($v, $key)) {
                return true;
            }
        }

        return false;
    }

    public function test_a_landlord_reads_only_their_own(): void
    {
        $w = $this->sweepWorld();
        $bad = [];
        $never = ['Peter Sample', 'Lake View', 'Carol Madeup', 'Dan Invented', 'Spare Rooms', 'Lake View roof', '0772100001', '+256772100001', '0772100002', '+256772100002', 'CF90SECRET', 'Staff note', 'Rang him', 'A plumber'];
        $forbiddenKeys = ['expense_limit_minor', 'assigned_to', 'expense_id', 'assignee_name', 'follow_ups', 'landlords_minor', 'staff'];
        foreach ($this->reads($w, $w['landlordH'], 'person') as $short => $res) {
            if (! $this->forKind($short, 'landlord')) {
                continue;
            }
            $this->assertLessThan(400, $res->getStatusCode(), "landlord GET {$short}: ".substr((string) $res->getContent(), 0, 200));
            foreach ($never as $word) {
                if (str_contains((string) $res->getContent(), $word)) {
                    $bad[] = "{$short}: shows {$word}";
                }
            }
            foreach ($forbiddenKeys as $k) {
                if ($this->anyValue($res->json(), $k)) {
                    $bad[] = "{$short}: key {$k} has a value";
                }
            }
        }
        // Another landlord's repair cannot be answered; a tenant's phone never comes through a unit.
        $askedThere = app(Maintenance::class)->open($w['owner'], (int) $w['company']->id, ['unit_id' => $w['others'][0]->id, 'title' => 'Across town']);
        $this->postJson('/api/v1/rentals/me/approvals/'.$askedThere->id, ['yes' => true], $w['landlordH'])->assertStatus(404);
        $this->assertSame([], $bad, "\n".implode("\n", $bad));
    }

    /** @return list<string> what a caretaker's answers hold of another building */
    private function outOfScope(array $w, array $h): array
    {
        $bad = [];
        $never = ['Lake View', 'Carol Madeup', 'Dan Invented', 'Peter Sample', 'Spare Rooms', 'Roof at Lake View', '0772100003', '0772100004', 'Demo Bank', 'Staff note about Margaret'];
        foreach ($this->reads($w, $h, 'staff') as $short => $res) {
            $this->assertLessThan(500, $res->getStatusCode(), "caretaker GET {$short}");
            if ($res->getStatusCode() >= 400) {
                continue;
            }
            foreach ($never as $word) {
                if (str_contains((string) $res->getContent(), $word)) {
                    $bad[] = "{$short}: shows {$word}";
                }
            }
            $data = $res->json('data');
            foreach (['commission_minor', 'landlord_minor', 'agency_minor', 'balance_minor', 'landlords_minor', 'commission', 'commission_bp', 'payout_details', 'expense_limit_minor', 'owed_to_landlords_minor'] as $key) {
                if ($key === 'balance_minor' && $short === 'leases/{id}/statement') {
                    continue; // there it is the tenant's own running balance
                }
                if ($this->anyValue($data, $key)) {
                    $bad[] = "{$short}: {$key} has a value";
                }
            }
        }

        return $bad;
    }

    public function test_a_caretaker_is_held_to_their_buildings_on_every_read(): void
    {
        $w = $this->sweepWorld();
        $bad = $this->outOfScope($w, $w['tokens']['caretaker']);
        $this->assertSame([], $bad, "\n".implode("\n", $bad));
        // A record of another building is not there, at every id route they can reach.
        $h = $w['tokens']['caretaker'];
        foreach (['landlords/'.$w['peter']->id, 'properties/'.$w['q']->id, 'units/'.$w['others'][0]->id, 'tenants/'.$w['carol']->tenant_id, 'leases/'.$w['carol']->id, 'leases/'.$w['carol']->id.'/reminder', 'leases/'.$w['carol']->id.'/payment-preview?amount_minor=5', 'maintenance/'.$w['urgentThere']->id] as $path) {
            $this->getJson("/api/v1/rentals/{$path}", $h)->assertStatus(404);
        }
    }

    public function test_a_caretaker_given_every_permission_still_reads_and_moves_nothing_outside_their_buildings(): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        foreach (['rentals_view', 'rentals_manage', 'rentals_collect', 'rentals_bill', 'rentals_reverse', 'rentals_landlords', 'rentals_expenses', 'rentals_maintenance', 'rentals_reports', 'rentals_settings', 'rentals_close', 'rentals_reopen', 'manage_settings'] as $permission) {
            DB::table('company_role_permissions')->insert(['company_id' => $cid, 'role' => 'rentals_caretaker', 'permission' => $permission, 'allowed' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        \App\Services\Team\Permissions::flush();
        $h = $w['tokens']['caretaker'];
        $bad = $this->outOfScope($w, $h);

        // Writes that name another building's record, and those that cover the whole account.
        $refused = [
            ['POST', 'payments', ['lease_id' => $w['carol']->id, 'amount_minor' => 1000, 'method' => 'cash']],
            ['POST', 'charges', ['lease_id' => $w['carol']->id, 'amount_minor' => 1000, 'description' => 'x']],
            ['POST', 'payouts', ['landlord_id' => $w['peter']->id, 'amount_minor' => 1000, 'method' => 'cash']],
            ['POST', 'payouts', ['landlord_id' => $w['margaret']->id, 'amount_minor' => 1000, 'method' => 'cash']],
            ['POST', 'payouts/'.$w['payout']->id.'/reverse', ['reason' => 'x']],
            ['POST', 'expenses', ['property_id' => $w['q']->id, 'category' => 'Repairs', 'amount_minor' => 100, 'method' => 'cash']],
            ['POST', 'expenses', ['category' => 'Office', 'charged_to' => 'agency', 'amount_minor' => 100, 'method' => 'cash']],
            ['POST', 'expenses/'.$w['otherExpense']->id.'/reverse', ['reason' => 'x']],
            ['POST', 'cash/transfers', ['from' => 'cash', 'to' => 'bank', 'amount_minor' => 100]],
            ['POST', 'cash/opening', ['method' => 'bank', 'amount_minor' => 100]],
            ['POST', 'cash/drawings', ['method' => 'cash', 'amount_minor' => 100, 'below_zero_agreed' => true]],
            ['POST', 'cash/drawings/'.$w['drawing']->id.'/reverse', ['reason' => 'x']],
            ['POST', 'landlords', ['name' => 'New', 'phone' => '0772222222']],
            ['PUT', 'landlords/'.$w['peter']->id, ['name' => 'New']],
            ['PUT', 'properties/'.$w['q']->id, ['name' => 'New']],
            ['PUT', 'properties/'.$w['q']->id.'/staff', ['user_ids' => [$w['caretaker']->id]]],
            ['POST', 'properties/'.$w['q']->id.'/handover', ['landlord_id' => $w['margaret']->id, 'reason' => 'x']],
            ['PUT', 'units/'.$w['others'][0]->id, ['code' => 'New']],
            ['POST', 'units', ['property_id' => $w['q']->id, 'code' => 'NEW1', 'rent_minor' => 100]],
            ['PUT', 'tenants/'.$w['carol']->tenant_id, ['name' => 'New']],
            ['POST', 'tenants/'.$w['carol']->tenant_id.'/login', ['email' => 'c@example.com']],
            ['POST', 'landlords/'.$w['peter']->id.'/login', ['email' => 'p@example.com']],
            ['POST', 'leases', ['unit_id' => $w['units'][2]->id, 'tenant_id' => $w['carol']->tenant_id, 'start_date' => '2026-04-01']],
            ['POST', 'leases', ['unit_id' => $w['others'][0]->id, 'tenant' => ['name' => 'New'], 'start_date' => '2026-04-01']],
            ['POST', 'leases/'.$w['carol']->id.'/move-out', ['date' => '2026-04-02']],
            ['POST', 'leases/'.$w['carol']->id.'/items', ['name' => 'x', 'amount_minor' => 100]],
            ['POST', 'charges/'.RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $w['carol']->id)->value('id').'/waive', ['amount_minor' => 1, 'reason' => 'x']],
            ['POST', 'billing/run', ['lease_id' => $w['carol']->id]],
            ['POST', 'documents', ['owner_type' => 'lease', 'owner_id' => $w['carol']->id, 'file_uuid' => $w['photoUuid'], 'name' => 'x']],
            ['POST', 'maintenance', ['unit_id' => $w['others'][0]->id, 'title' => 'x']],
            ['POST', 'meter-readings', ['unit_id' => $w['others'][0]->id, 'utility' => 'water', 'reading' => '5']],
            ['POST', 'follow-ups', ['lease_id' => $w['carol']->id, 'channel' => 'note']],
            ['POST', 'month-end/close', ['month' => '2026-03']],
            ['POST', 'month-end/reopen', ['month' => '2026-02', 'reason' => 'x']],
            ['PUT', 'settings', ['grace_days' => 1]],
            ['POST', 'setup', ['grace_days' => 1]],
        ];
        $before = $this->books($cid);
        foreach ($refused as [$m, $path, $body]) {
            [$res] = $this->tried($m, "/api/v1/rentals/{$path}", $body, $h);
            if (! in_array($res->getStatusCode(), [403, 404], true)) {
                $bad[] = "{$m} {$path}: ".$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 140);
            }
        }
        // Raising every bill is for those who answer for every lease; theirs are their own.
        $beforeOther = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $w['q']->id)->count();
        Carbon::setTestNow('2026-05-01 09:00:00');
        [$res, $after] = $this->tried('POST', '/api/v1/rentals/billing/run', [], $h, fn () => RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $w['q']->id)->count());
        Carbon::setTestNow('2026-04-02 09:00:00');
        if ($res->getStatusCode() < 300 && $after !== $beforeOther) {
            $bad[] = 'POST billing/run raised bills in another building';
        }
        $this->assertSame($before, $this->books($cid));
        $this->assertSame([], $bad, "\n".implode("\n", $bad));
    }

    // ── 9. Lists, queries, and the rent book ───────────────────────────────

    /** More of everything: $n more landlords with a building, a door, a tenant, money, repairs; and $n more payments and repairs on the first tenant's lease. */
    private function grow(array $w, int $n): void
    {
        $owner = $w['owner'];
        $cid = (int) $w['company']->id;
        $svc = app(Payments::class);
        for ($i = 0; $i < $n; $i++) {
            $tag = uniqid();
            $landlord = app(\App\Services\Rentals\Directory\Landlords::class)->create($owner, ['name' => 'Grown Landlord '.$tag, 'phone' => '0772'.random_int(100000, 999999)]);
            $property = app(\App\Services\Rentals\Directory\Properties::class)->create($owner, ['name' => 'Grown Court '.$tag, 'landlord_id' => $landlord->id]);
            $unit = app(\App\Services\Rentals\Directory\Units::class)->create($owner, ['property_id' => $property->id, 'code' => 'G1', 'rent_minor' => 100000]);
            $lease = $this->tenantIn($owner, $unit, '2026-03-01', ['deposit_minor' => 0, 'tenant' => ['name' => 'Grown Tenant '.$tag, 'phone' => '0701'.random_int(100000, 999999)]]);
            $svc->receive($owner, $lease, ['amount_minor' => 50000, 'method' => 'cash']); // still behind, so arrears has a row
            app(FollowUps::class)->log($owner, $lease, 'call', 'Rang');
            app(Expenses::class)->record($owner, ['property_id' => $property->id, 'category' => 'Repairs', 'amount_minor' => 100, 'method' => 'cash']);
            app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 100, 'method' => 'cash']);
            app(Maintenance::class)->open($owner, $cid, ['unit_id' => $unit->id, 'title' => 'Grown tap']);
            app(Meters::class)->record($owner, ['unit_id' => $unit->id, 'utility' => 'water', 'reading' => '5']);
            app(PayoutRuns::class)->save($owner, [['landlord_id' => $landlord->id, 'amount_minor' => 100, 'method' => 'cash']]);
            // On the people who sign in: more of their own.
            $svc->receive($owner, $w['amina'], ['amount_minor' => 100, 'method' => 'cash']);
            app(\App\Services\Rentals\Billing\Billing::class)->charge($owner, $w['amina'], ['description' => 'Grown bill', 'amount_minor' => 100]);
            app(Maintenance::class)->open(null, $cid, ['unit_id' => $w['units'][0]->id, 'title' => 'Grown leak'], ['tenant', (int) $w['amina']->tenant_id]);
            app(PaidReports::class)->report($w['tenantLogin'], ['amount_minor' => 100, 'method' => 'cash', 'lease_id' => $w['amina']->id, 'uuid' => (string) Str::uuid()]);
            app(Expenses::class)->record($owner, ['property_id' => $w['p']->id, 'category' => 'Repairs', 'amount_minor' => 10, 'method' => 'cash', 'over_limit_reason' => 'x']);
            app(Payouts::class)->record($owner, $w['margaret'], ['amount_minor' => 10, 'method' => 'cash']);
            $unitHers = app(\App\Services\Rentals\Directory\Units::class)->create($owner, ['property_id' => $w['p']->id, 'code' => 'H'.$tag, 'rent_minor' => 100000]);
            $this->tenantIn($owner, $unitHers, '2026-03-01', ['deposit_minor' => 0, 'tenant' => ['name' => 'Her Tenant '.$tag, 'phone' => '0702'.random_int(100000, 999999)]]);
        }
    }

    /** @return array{0: int, 1: mixed} how many queries a read took, and its answer */
    private function measured(string $path, array $h): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $res = $this->getJson($path, $h);
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        $shapes = array_count_values(array_map(fn ($q) => substr((string) $q['query'], 0, 110), $log));
        arsort($shapes);

        return [count($log), $res, array_slice($shapes, 0, 3, true)];
    }

    public function test_every_list_is_bounded_and_its_queries_do_not_grow_with_its_rows(): void
    {
        $w = $this->sweepWorld();
        $this->grow($w, 3);
        // Lists that are bounded another way, and why.
        $bounded = ['reports' => 'the registry', 'documents' => 'one record\'s papers', 'me/statements' => 'one a month', 'payout-runs/candidates' => 'one row per landlord, read in few queries', 'team' => 'the account\'s people', 'year-summaries' => 'one row per landlord', 'me/paid-reports' => 'at most 50, the contract says', 'me/approvals' => 'at most 100', 'month-end/withholding' => 'one row per landlord', 'rent-book' => 'one document'];
        $reads = fn (string $kind) => collect($this->routes())->filter(fn ($r) => $r['method'] === 'GET' && $r['kind'] === $kind && ! str_contains($r['short'], '{'))->pluck('short')->all();
        $bases = [];
        foreach ($reads('staff') as $short) {
            $q = $short === 'search' ? '?q=Grown' : ($short === 'documents' ? '?owner_type=tenant&owner_id='.$w['amina']->tenant_id : ($short === 'expenses/landlord-effect' ? '?property_id='.$w['p']->id.'&amount_minor=5' : ''));
            $bases['owner '.$short] = ['/api/v1/rentals/'.$short.$q, $w['tokens']['owner'], $short];
        }
        foreach ($reads('person') as $short) {
            if ($this->forKind($short, 'tenant')) {
                $bases['tenant '.$short] = ['/api/v1/rentals/'.$short, $w['tenantH'], $short];
            }
            if ($this->forKind($short, 'landlord')) {
                $bases['landlord '.$short] = ['/api/v1/rentals/'.$short, $w['landlordH'], $short];
            }
        }
        $few = [];
        foreach ($bases as $name => [$path, $h]) {
            [$few[$name]] = $this->measured($path, $h);
        }
        $this->grow($w, 27);
        $bad = [];
        foreach ($bases as $name => [$path, $h, $short]) {
            [$many, $res, $shapes] = $this->measured($path, $h);
            $this->assertLessThan(400, $res->getStatusCode(), "{$name}: ".substr((string) $res->getContent(), 0, 200));
            if ($many > $few[$name] + 5) {
                $bad[] = "{$name}: {$few[$name]} queries with a few rows, {$many} with thirty more: ".json_encode($shapes);
            }
            $data = $res->json('data');
            if (is_array($data) && array_is_list($data) && count($data) > 1 && ! isset($bounded[$short])) {
                // A list says how long it is, and caps what a caller may ask for.
                $sep = str_contains($path, '?') ? '&' : '?';
                $paged = $this->getJson($path.$sep.'per_page=2', $h);
                if ($res->json('meta.pagination') === null) {
                    $bad[] = "{$name}: a list with no pagination";
                } elseif (count($paged->json('data')) > 2) {
                    $bad[] = "{$name}: per_page=2 gave ".count($paged->json('data'));
                }
                $this->assertLessThanOrEqual(100, count($this->getJson($path.$sep.'per_page=100000', $h)->json('data')), $name);
            }
        }
        $this->assertSame([], $bad, "\n".implode("\n", $bad));
    }

    public function test_the_rent_book_answers_304_and_changes_after_every_kind_of_write_it_shows(): void
    {
        $w = $this->sweepWorld();
        $h = $w['tokens']['owner'];
        $book = fn () => $this->getJson('/api/v1/rentals/rent-book', $h)->assertOk();
        $etag = (string) $book()->headers->get('ETag');
        $same = $this->getJson('/api/v1/rentals/rent-book', $h + ['If-None-Match' => $etag])->assertStatus(304);
        $this->assertSame('', (string) $same->getContent());

        $writes = ['POST payments', 'POST payments/{id}/reverse', 'POST charges', 'POST charges/{id}/void', 'POST charges/{id}/waive', 'POST follow-ups', 'POST leases', 'POST leases/{id}/notice', 'POST leases/{id}/withdraw-notice', 'POST leases/{id}/renew',
            'POST leases/{id}/move-out', 'POST leases/{id}/cancel', 'POST leases/{id}/rent-change', 'POST leases/{id}/refund-credit', 'POST units', 'PUT units/{id}', 'POST properties', 'PUT properties/{id}', 'PUT tenants/{id}', 'PUT landlords/{id}', 'PUT settings',
            'POST paid-reports/{id}/confirm', 'POST meter-readings'];
        $by = collect($this->writes($w))->keyBy('key');
        $bad = [];
        foreach ($writes as $key) {
            $r = $by->get($key);
            $this->assertNotNull($r, $key);
            DB::beginTransaction();
            try {
                $res = $this->json($r['method'], $r['path'], $r['body'], $h);
                $this->assertLessThan(300, $res->getStatusCode(), $key.': '.$res->getContent());
                $after = $this->getJson('/api/v1/rentals/rent-book', $h + ['If-None-Match' => $etag]);
                if ($after->getStatusCode() === 304) {
                    $bad[] = "{$key}: the book says nothing changed";
                }
                $this->assertNotSame($etag, $after->headers->get('ETag'), $key);
            } finally {
                DB::rollBack();
            }
        }
        // What a book does not show moves no ETag.
        foreach (['POST expenses', 'POST cash/transfers', 'POST maintenance', 'POST documents'] as $key) {
            $r = $by->get($key);
            [$res, $after] = $this->tried($r['method'], $r['path'], $r['body'], $h, fn () => $this->getJson('/api/v1/rentals/rent-book', $h + ['If-None-Match' => $etag])->getStatusCode());
            $this->assertLessThan(300, $res->getStatusCode(), $key);
            $this->assertSame(304, $after, "{$key}: not in the book");
        }
        // A caretaker's book has its own ETag, and a write in another building leaves it alone.
        $care = $w['tokens']['caretaker'];
        $mine = (string) $this->getJson('/api/v1/rentals/rent-book', $care)->assertOk()->headers->get('ETag');
        $this->assertNotSame($etag, $mine);
        app(Payments::class)->receive($w['owner'], $w['carol'], ['amount_minor' => 1000, 'method' => 'cash']);
        $this->getJson('/api/v1/rentals/rent-book', $care + ['If-None-Match' => $mine])->assertStatus(304);
        $this->assertSame([], $bad, "\n".implode("\n", $bad));
    }

    // ── 10. Plan, module, closed months, the demo ──────────────────────────

    public function test_with_the_module_off_or_the_plan_lapsed_nothing_moves_and_people_are_told(): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        $bodies = $this->bodies($w);
        $before = $this->books($cid);

        // A lapsed plan: every staff route answers 402, and the people's sign-in stops.
        $sub = Subscription::where('company_id', $cid)->firstOrFail();
        $sub->forceFill(['status' => 'expired', 'trial_ends_at' => now()->subDays(90), 'ends_at' => now()->subDays(90)])->save();
        Company::withoutGlobalScopes()->whereKey($cid)->update(['license_expire' => null]);
        $bad = [];
        foreach ($this->routes() as $r) {
            if ($r['kind'] === 'public') {
                continue;
            }
            [$res] = $this->tried($r['method'], '/api/v1/rentals/'.$this->path($r['method'], $r['short'], $w), $bodies[$r['method'].' '.$r['short']] ?? [], $this->caller($r, $w));
            $want = $r['kind'] === 'staff' ? 402 : 401;
            if ($res->getStatusCode() !== $want) {
                $bad[] = "lapsed: {$r['method']} {$r['short']}: ".$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 140);
            }
        }
        $this->assertSame($before, $this->books($cid));
        $sub->forceFill(['status' => 'active', 'trial_ends_at' => null, 'ends_at' => '2030-01-01 00:00:00'])->save();

        // The module off: staff 403 module_off, people signed out. A person's token still names the sign-in, but finds no account.
        [$tenantH] = $this->person($w['owner'], 'tenant', (int) $w['brian']->tenant_id);
        $before = $this->books($cid);
        Company::withoutGlobalScopes()->whereKey($cid)->update(['enabled_modules' => json_encode(['shop']), 'business_type' => 'retail']);
        \App\Services\Team\Permissions::flush();
        foreach ($this->routes() as $r) {
            if ($r['kind'] === 'public') {
                continue;
            }
            $h = $r['kind'] === 'staff' ? $w['tokens']['owner'] : $tenantH;
            [$res] = $this->tried($r['method'], '/api/v1/rentals/'.$this->path($r['method'], $r['short'], $w), $bodies[$r['method'].' '.$r['short']] ?? [], $h);
            $want = $r['kind'] === 'staff' ? 403 : 401;
            if ($res->getStatusCode() !== $want || ($r['kind'] === 'staff' && $res->json('errors.code') !== 'module_off')) {
                $bad[] = "module off: {$r['method']} {$r['short']}: ".$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 140);
            }
        }
        $this->assertSame($before, $this->books($cid));
        $this->assertSame([], $bad, "\n".implode("\n", $bad));
    }

    public function test_a_closed_month_takes_no_write_at_any_money_route(): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        $h = $w['tokens']['owner'];
        $closed = '2026-02-15';
        $by = collect($this->writes($w))->keyBy('key');
        $cases = ['POST payments' => ['paid_on'], 'POST payouts' => ['paid_on'], 'POST expenses' => ['paid_on'], 'POST cash/transfers' => ['on'], 'POST cash/opening' => ['on'], 'POST cash/drawings' => ['on'], 'POST payout-runs/{id}/post' => ['paid_on'],
            'POST leases/{id}/refund-deposit' => ['on'], 'POST leases/{id}/refund-credit' => ['on'], 'POST leases/{id}/move-out' => ['date'], 'PATCH maintenance/{id}' => ['paid_on'], 'POST properties/{id}/handover' => ['from'], 'POST leases' => ['first_payment.paid_on'],
            'POST charges' => ['due_date'], 'POST meter-readings' => ['read_on'], 'POST leases/{id}/rent-change' => ['effective_from'], 'POST month-end/withholding' => ['paid_on']];
        $before = $this->books($cid);
        $bad = [];
        foreach ($cases as $key => $fields) {
            $r = $by->get($key);
            $this->assertNotNull($r, $key);
            foreach ($fields as $field) {
                $body = $r['body'];
                data_set($body, $field, $closed);
                if ($key === 'POST payments') {
                    unset($body['split']);
                }
                [$res] = $this->tried($r['method'], $r['path'], $body, $h);
                if ($res->getStatusCode() !== 422 || ! in_array($res->json('errors.code'), ['period_closed', 'period_billed', 'date_invalid', 'reading_order'], true)) {
                    $bad[] = "{$key} {$field} in a closed month: ".$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 200);
                }
            }
        }
        // Dated today it goes through, and the statement already issued does not move.
        $proof = RentalStatement::withoutGlobalScopes()->whereKey($w['statement']->id)->value('proof');
        foreach (['POST payments', 'POST payouts', 'POST expenses', 'POST payments/{id}/reverse', 'POST payouts/{id}/reverse', 'POST expenses/{id}/reverse', 'POST charges/{id}/waive', 'POST charges/{id}/void', 'POST cash/drawings/{id}/reverse'] as $key) {
            $r = $by->get($key);
            [$res] = $this->tried($r['method'], $r['path'], $r['body'], $h);
            if ($res->getStatusCode() >= 300) {
                $bad[] = "{$key} today: ".$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 200);
            }
        }
        $this->assertSame($proof, RentalStatement::withoutGlobalScopes()->whereKey($w['statement']->id)->value('proof'));
        $this->assertSame($before, $this->books($cid));
        $this->assertBooksAgree($cid);
        $this->assertSame([], $bad, "\n".implode("\n", $bad));
    }

    public function test_the_second_setup_cannot_change_how_an_account_works_once_a_tenant_is_on_the_books(): void
    {
        $w = $this->sweepWorld();
        $h = $w['tokens']['owner'];
        $this->postJson('/api/v1/rentals/setup', ['mode' => 'owner'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'mode_locked');
        $this->assertSame('agency', DB::table('rental_settings')->where('company_id', $w['company']->id)->value('mode'));
        $this->putJson('/api/v1/rentals/settings', ['mode' => 'owner'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'mode_locked');
        $this->postJson('/api/v1/rentals/setup', ['currency' => 'USD'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'currency_locked');
    }

    public function test_the_demo_refuses_what_it_refuses_on_the_web(): void
    {
        $w = $this->sweepWorld();
        $h = $w['tokens']['owner'];
        $cid = (int) $w['company']->id;
        // The demo's own locks: its settings, its sign-ins, who looks after a building, its papers, a person's password. Its money stays open to click on.
        $memo = new \ReflectionProperty(\App\Services\Rentals\Demo\RentalsDemo::class, 'isDemo');
        Company::withoutGlobalScopes()->whereKey($cid)->update(['is_demo' => true, 'demo_parent_id' => null, 'onboarding_state' => json_encode([\App\Services\Rentals\Demo\RentalsDemo::MARK => ['built_at' => now()->toIso8601String()]])]);
        $memo->setValue(null, []);
        $this->assertTrue(\App\Services\Rentals\Demo\RentalsDemo::isDemoCompany($cid));
        $by = collect($this->writes($w))->keyBy('key');
        $bad = [];
        foreach (['PUT settings', 'PATCH settings', 'POST setup', 'PUT properties/{id}/staff', 'POST tenants/{id}/login', 'POST landlords/{id}/login', 'POST documents', 'DELETE documents/{id}'] as $key) {
            $r = $by->get($key);
            [$res] = $this->tried($r['method'], $r['path'], $r['body'], $h);
            if ($res->getStatusCode() !== 422 || $res->json('errors.code') !== 'demo_locked') {
                $bad[] = "{$key}: ".$res->getStatusCode().' '.substr((string) $res->getContent(), 0, 160);
            }
        }
        [$res] = $this->tried('POST', '/api/v1/rentals/me/password', ['password' => 'Fresh-pass-2026'], $w['tenantH']);
        if ($res->getStatusCode() !== 422 || $res->json('errors.code') !== 'demo_locked') {
            $bad[] = 'POST me/password: '.$res->getStatusCode();
        }
        foreach (['POST payments', 'POST expenses', 'POST maintenance', 'POST follow-ups'] as $key) {
            $r = $by->get($key);
            [$res] = $this->tried($r['method'], $r['path'], $r['body'], $h);
            if ($res->getStatusCode() >= 300) {
                $bad[] = "{$key} in the demo: ".$res->getStatusCode();
            }
        }
        $memo->setValue(null, []);
        $this->assertSame([], $bad, "\n".implode("\n", $bad));
    }

    /** A period's close is the period's own, whatever the rows a screen is shown: past the limit a sum of the rows closed on the wrong balance. */
    public function test_the_cash_book_closes_on_the_balance_however_few_rows_it_shows(): void
    {
        $w = $this->sweepWorld();
        $cid = (int) $w['company']->id;
        $all = CashBook::statement($cid, '2026-01-01', '2026-04-02');
        $this->assertGreaterThan(2, count($all['rows']));
        $few = CashBook::statement($cid, '2026-01-01', '2026-04-02', null, 2);
        $this->assertCount(2, $few['rows']);
        $this->assertSame([$all['closing_minor'], $all['in_minor'], $all['out_minor']], [$few['closing_minor'], $few['in_minor'], $few['out_minor']]);
        $this->assertSame((int) CashBook::balances($cid)['total'], $few['closing_minor']);
    }
}
