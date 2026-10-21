<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoAccountBalance;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoLedgerHead;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\PostingRules;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Ledger\SubRef;
use App\Support\Sacco\Amount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\MakesSaccoLedger;
use Tests\Support\Sacco\PotLedger;
use Tests\TestCase;

/**
 * Akiba Ledger P1.6 (ADR 0009 §3): the standard chart, seeded per SACCO. Seeding again changes nothing; the office
 * chart has every account the posting rules use and the group chart every one a group posts to; every control
 * account carries its sub-ledger; a SACCO adds its own accounts without touching the ones the code relies on.
 */
class ChartOfAccountsTest extends TestCase
{
    use DatabaseTransactions, MakesSaccoLedger;

    protected function tearDown(): void
    {
        $this->restorePots();
        parent::tearDown();
    }

    public function test_seeding_is_idempotent_and_builds_the_books(): void
    {
        [$company] = $this->saccoBooks();
        $cid = (int) $company->id;
        $count = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->count();
        $this->assertSame(count(ChartOfAccounts::rows('office')), $count);
        $this->assertSame(0, (new ChartOfAccounts())->seed($cid, 'office'));
        $this->assertSame($count, SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->assertSame($count, SaccoAccountBalance::withoutGlobalScopes()->where('company_id', $cid)->count());
        $this->assertSame(PostingService::GENESIS, SaccoLedgerHead::withoutGlobalScopes()->find($cid)->last_hash);

        $header = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('code', '1000')->first();
        $this->assertFalse($header->is_postable);
        $this->assertSame($header->id, SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('code', '1010')->value('parent_id'));
        $this->assertSame('credit', SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('code', '1390')->value('normal_side'), 'the loan-loss allowance is a contra asset');
        foreach (['1000', '1300', '2110', '2150', '3100', '3900', '4100'] as $code) {
            $this->assertNotNull(SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('code', $code)->first(), $code);
        }
    }

    public function test_each_mode_has_every_account_its_postings_use(): void
    {
        foreach (PostingRules::SYSTEM_KEYS as $mode => $keys) {
            [$company] = $this->saccoBooks($mode);
            foreach ($keys as $key) {
                $this->assertSame($key, ChartOfAccounts::account((int) $company->id, $key)->system_key, "{$mode}: {$key}");
            }
            // Every control account names its sub-ledger.
            foreach (SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('control_of')->get() as $a) {
                $this->assertNotNull($a->subType(), "{$mode}: {$a->code}");
            }
        }
        [$group] = $this->saccoBooks('group');
        $gid = (int) $group->id;
        $codes = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $gid)->pluck('code')->all();
        foreach (['1020', '1100', '1150', '1300', '1320', '1440', '2110', '2150', '2430', '2510', '3100', '3410', '3900', '4100', '4400', '4410', '5730'] as $code) {
            $this->assertContains($code, $codes, "the group chart has {$code}");
        }
        $this->assertNotContains('1010', $codes, 'a group has no office till');
        $this->assertNotContains('2120', $codes);

        // A group's postings go through on its own chart.
        $this->usePots();
        $pot = PotLedger::open($gid, ChartOfAccounts::id($gid, 'savings.voluntary'));
        $rules = new PostingRules($gid, 'group');
        $ref = new SubRef('savings', $pot, ChartOfAccounts::id($gid, 'savings.voluntary'));
        foreach (['deposit' => $rules->deposit('txn:g1', $ref, Amount::of(5000, 'UGX'), 'cash'), 'entrance' => $rules->entranceFee('txn:g2', 1, Amount::of(2000, 'UGX'), 'cash'),
            'held' => $rules->heldReceipt('txn:g3', Amount::of(1000, 'UGX')), 'alloc' => $rules->heldReceiptAllocation('apr:g4', $ref, Amount::of(1000, 'UGX')),
            'withdrawal' => $rules->withdrawal('txn:g5', $ref, Amount::of(3000, 'UGX'), 'cash')] as $what => $draft) {
            $this->assertNotNull((new PostingService())->post($draft)->id, $what);
        }
        $this->assertSame(3000, PotLedger::balance($pot));
    }

    public function test_a_sacco_adds_its_own_accounts_and_keeps_the_systems(): void
    {
        [$company, $user] = $this->saccoBooks();
        $cid = (int) $company->id;
        $chart = new ChartOfAccounts();
        $parent = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('code', '1200')->first();
        $child = $chart->addAccount($cid, ['code' => '1201', 'name' => 'Balance with Kampala SACCO', 'type' => 'asset', 'parent_id' => $parent->id], $user->id);
        $this->assertTrue($child->is_postable);
        $this->assertSame('debit', $child->normal_side);
        $this->assertFalse($parent->fresh()->is_postable, 'the parent becomes a header');
        $this->assertSame($user->id, $child->created_by_id);
        $this->assertNotNull(SaccoAccountBalance::withoutGlobalScopes()->where('ledger_account_id', $child->id)->first());

        foreach ([
            ['invalid_account', fn () => $chart->addAccount($cid, ['code' => '1201', 'name' => 'Twin', 'type' => 'asset'], $user->id)],
            ['invalid_account', fn () => $chart->addAccount($cid, ['code' => '1202', 'name' => 'Wrong type', 'type' => 'income', 'parent_id' => $parent->id], $user->id)],
            ['account_in_use', fn () => $chart->addAccount($cid, ['code' => '1011', 'name' => 'Till 1', 'type' => 'asset', 'parent_id' => ChartOfAccounts::id($cid, 'cash.office')], $user->id)],
            ['system_account', fn () => ChartOfAccounts::account($cid, 'cash.office')->update(['is_active' => false])],
            ['account_not_found', fn () => ChartOfAccounts::account($cid, 'no.such.key')],
        ] as [$code, $fn]) {
            try {
                $fn();
                $this->fail("expected {$code}");
            } catch (BusinessRuleException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }
        // Another SACCO's chart is untouched.
        [$other] = $this->saccoBooks();
        $this->assertNull(SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $other->id)->where('code', '1201')->first());
    }

    public function test_the_chart_file_is_found_from_the_kernel_whatever_the_apps_database_path(): void
    {
        $kernel = dirname(__DIR__, 3);
        $this->assertSame($kernel.'/database/data/sacco_chart.php', ChartOfAccounts::path());
        $this->assertFileExists(ChartOfAccounts::path());
        $this->app->useDatabasePath(sys_get_temp_dir().'/another-app/database'); // the web portal's own folder
        try {
            $this->assertNotEmpty(ChartOfAccounts::rows('office'));
            $this->assertStringStartsWith($kernel, ChartOfAccounts::path());
        } finally {
            $this->app->useDatabasePath($kernel.'/database');
        }
    }

    public function test_the_auditor_reads_every_page_that_shows_the_books(): void
    {
        $section = collect(\App\Support\ShopMenu::sections())->firstWhere('key', 'sacco');
        $pages = collect($section['entries'])->flatMap(fn ($e) => $e['items'] ?? [$e])->keyBy('route');
        foreach (['sacco.overview', 'sacco.members', 'sacco.approvals', 'sacco.savings', 'sacco.shares', 'sacco.accounts', 'sacco.journal', 'sacco.periods',
            'sacco.reports', 'sacco.officers', 'sacco.settings', 'sacco.audit'] as $route) {
            $this->assertContains('sacco_audit_view', (array) $pages[$route]['can'], $route);
        }
        foreach (['sacco.teller', 'sacco.withdrawals'] as $route) {
            $this->assertNotContains('sacco_audit_view', (array) $pages[$route]['can'], $route.' moves money: not an auditor\'s page');
        }
    }
}
