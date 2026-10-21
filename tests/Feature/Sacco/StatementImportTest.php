<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoApproval;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoStatementLine;
use App\Models\User;
use App\Services\Sacco\Channels\SheetReader;
use App\Services\Sacco\Channels\StatementImport;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Payments\SaccoCollections;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoTransactions;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P3.4 (04 §3.5): statements from a bank, MTN, Airtel and Flutterwave read exactly (headers by name,
 * written amounts to minor units), matched by amount, side, date and reference; ties and strangers left for a person;
 * nothing posts on import; a missing entry becomes a manual journal only through its approval; the same file twice is
 * refused; over HTTP with its permission and another SACCO's statements answering 404.
 */
class StatementImportTest extends ApiTestCase
{
    use MakesSaccoLoans;

    /** @return array<string, string> */
    private function as(User $u): array
    {
        return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    /** A multipart upload with the guards forgotten first (ApiTestCase does it for JSON requests only). @param array<string, mixed> $data @param array<string, string> $headers */
    private function upload(array $data, array $headers): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->post('/api/v1/sacco/statement-imports', $data, $headers);
    }

    public function test_amounts_and_dates_are_read_exactly(): void
    {
        $this->assertSame(1_500_000, SheetReader::minor('1,500,000.00', 'UGX'));
        $this->assertSame(-5_000, SheetReader::minor('(5,000)', 'UGX'));
        $this->assertSame(-250, SheetReader::minor('-2.50', 'KES'));
        $this->assertSame(1_234_567, SheetReader::minor('UGX 1 234 567', 'UGX'));
        $this->assertNull(SheetReader::minor('', 'UGX'));
        $this->assertSame('invalid_amount', $this->errorCode(fn () => SheetReader::minor('12.5', 'UGX')));
        $this->assertSame('2026-10-03', SheetReader::date('03/10/2026')->toDateString(), 'd/m/Y, the Ugandan way');
        $this->assertSame('2026-10-03', SheetReader::date('2026-10-03 14:22:10')->toDateString());
        $this->assertSame('2026-10-03', SheetReader::date('46298')->toDateString(), 'an Excel serial day');
    }

    public function test_bank_matching_review_and_the_journal_through_its_approval(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $treasurer = $this->loanTreasurer($company);
        $manager = $this->loanStaffOf($company)['manager'];
        $member = $this->loanMember($company, $owner);
        $account = $this->loanSavings($company, $member, 10_000, $owner);
        $stx = app(SaccoTransactions::class);
        $day = now()->subDays(2)->toDateString();
        $dep = $stx->record($company, ['kind' => 'deposit', 'channel' => 'bank', 'savings_account_id' => $account->id, 'amount_minor' => 150_000, 'reference' => 'CHQ-0042',
            'value_date' => $day], $treasurer)['transaction'];
        $stx->record($company, ['kind' => 'deposit', 'channel' => 'bank', 'savings_account_id' => $account->id, 'amount_minor' => 50_000, 'value_date' => $day], $treasurer);
        $stx->record($company, ['kind' => 'deposit', 'channel' => 'bank', 'savings_account_id' => $account->id, 'amount_minor' => 50_000, 'value_date' => $day], $treasurer);
        $entries = SaccoJournalEntry::where('company_id', $cid)->count();
        $d = \Illuminate\Support\Carbon::parse($day)->format('d/m/Y');
        $csv = "Bank statement,,,,,\nTransaction Date,Narration,Ref No,Debit,Credit,Balance\n"
            ."{$d},Cheque deposit,CHQ-0042,,\"150,000.00\",\"80,150,000.00\"\n"
            ."{$d},Cash deposit,,,\"50,000.00\",\"80,200,000.00\"\n"
            ."{$d},Monthly ledger fee,BC-77,\"5,000.00\",,\"80,195,000.00\"\n"
            .",Closing balance,,,,\"80,195,000.00\"\n";

        $svc = app(StatementImport::class);
        $import = $svc->import($company, $treasurer, $csv, 'october.csv', 'bank');
        $this->assertSame([3, ChartOfAccounts::id($cid, 'bank.main'), 80_195_000], [$import->lines_count, (int) $import->ledger_account_id, $import->closing_balance_minor]);
        $this->assertSame($entries, SaccoJournalEntry::where('company_id', $cid)->count(), 'an import posts nothing');
        $lines = SaccoStatementLine::where('import_id', $import->id)->orderBy('line_no')->get();
        $this->assertSame(['proposed', 'unmatched', 'unmatched'], $lines->pluck('match_status')->all());
        $this->assertSame((int) $dep->journal_entry_id, (int) $lines[0]->journal_entry_id, 'the reference picks the cheque');
        $this->assertStringContainsString('Several entries', (string) $lines[1]->note, 'two equal deposits: a person chooses');
        $this->assertSame([150_000, 50_000, -5_000], $lines->pluck('amount_minor')->all());
        $this->assertSame('already_imported', $this->errorCode(fn () => $svc->import($company, $treasurer, $csv, 'again.csv', 'bank')));

        $svc->review($company, (int) $import->id, (int) $lines[0]->id, 'confirm', $treasurer);
        $choice = $svc->summary($company, (int) $import->id)['unmatched_entries'];
        $fifty = collect($choice)->firstWhere('amount_minor', 50_000);
        $this->assertSame('not_the_same', $this->errorCode(fn () => $svc->review($company, (int) $import->id, (int) $lines[2]->id, 'link', $treasurer, ['journal_line_id' => $fifty['journal_line_id']])));
        $svc->review($company, (int) $import->id, (int) $lines[1]->id, 'link', $treasurer, ['journal_line_id' => $fifty['journal_line_id']]);

        $charges = \App\Models\SaccoLedgerAccount::where('company_id', $cid)->where('code', '5720')->value('id');
        $line = $svc->review($company, (int) $import->id, (int) $lines[2]->id, 'journal', $treasurer, ['counter_account_id' => $charges]);
        $this->assertSame($entries, SaccoJournalEntry::where('company_id', $cid)->count(), 'the journal waits for a second officer');
        $this->assertSame('self_approval', $this->errorCode(fn () => app(SaccoApprovals::class)->approve($company, (int) $line->approval_id, $treasurer)));
        app(SaccoApprovals::class)->approve($company, (int) $line->approval_id, $manager);
        $this->assertSame('executed', SaccoApproval::find($line->approval_id)->status);
        $this->assertSame(['proposed' => 1, 'ambiguous' => 0, 'unmatched' => 0], $svc->match($company, (int) $import->id, $treasurer), 'the new entry names the statement line');
        $svc->review($company, (int) $import->id, (int) $lines[2]->id, 'confirm', $treasurer);
        $s = $svc->summary($company, (int) $import->id);
        $this->assertSame(3, $s['statuses']['matched']['count']);
        $this->assertSame(1, count(array_filter($s['unmatched_entries'], fn ($e) => $e['amount_minor'] === 50_000)), 'the other 50,000 is still unexplained');
        $this->assertSame('forbidden', $this->errorCode(fn () => $svc->review($company, (int) $import->id, (int) $lines[1]->id, 'reject', $this->saccoStaff($company, 'sacco_teller'))));
    }

    public function test_mtn_airtel_and_flutterwave_formats(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $treasurer = $this->loanTreasurer($company);
        $svc = app(StatementImport::class);
        $mtn = "Id,External Transaction Id,Date,Status,Type,From,To,Amount,Fee,Balance\n"
            ."9001,EXT1,2026-10-01 09:00:00,Successful,TRANSFER,256772,256700,-200000,0,800000\n"
            ."9002,EXT2,2026-10-01 10:00:00,Failed,DEBIT,256772,256701,-50000,0,800000\n"
            ."9003,EXT3,2026-10-02 10:00:00,Successful,DEPOSIT,256701,256772,25000,0,825000\n";
        $i = $svc->import($company, $treasurer, $mtn, 'mtn.csv', 'mtn');
        $this->assertSame([2, ChartOfAccounts::id($cid, 'momo.float'), 825_000], [$i->lines_count, (int) $i->ledger_account_id, $i->closing_balance_minor], 'a failed MTN line is not money');
        $this->assertSame([-200_000, 25_000], SaccoStatementLine::where('import_id', $i->id)->orderBy('line_no')->pluck('amount_minor')->all());

        $airtel = "Transaction ID;Transaction Date;Service Name;Dr/Cr;Amount;Post Balance;Status\nA1;05-10-2026;Cash out;DR;30,000;70,000;Success\nA2;06-10-2026;Cash in;CR;10,000;80,000;Success\n";
        $a = $svc->import($company, $treasurer, $airtel, 'airtel.csv', 'airtel');
        $this->assertSame([-30_000, 10_000], SaccoStatementLine::where('import_id', $a->id)->orderBy('line_no')->pluck('amount_minor')->all());

        // A collection settled through Flutterwave is found by its tx_ref on 1160.
        config(['sacco.collections.driver' => 'simulated', 'sacco.collections.simulated_approve_after' => 0]);
        $member = $this->loanMember($company, $owner);
        $savings = $this->loanSavings($company, $member, 10_000, $owner);
        $req = app(SaccoCollections::class)->request($company, ['purpose' => 'sacco_deposit', 'savings_account_id' => $savings->id, 'amount_minor' => 40_000], $this->saccoStaff($company, 'sacco_teller'));
        app(SaccoCollections::class)->settle($req->tx_ref);
        $flw = "id,tx_ref,flw_ref,amount,currency,status,payment_type,created_at\n"
            ."5501,{$req->tx_ref},FLW-1,40000,UGX,successful,mobilemoneyug,".now()->toDateTimeString()."\n"
            .'5502,OTHER-1,FLW-2,40000,UGX,successful,mobilemoneyug,'.now()->toDateTimeString()."\n";
        $f = $svc->import($company, $treasurer, $flw, 'flw.csv', 'flutterwave');
        $this->assertSame(ChartOfAccounts::id($cid, 'momo.clearing'), (int) $f->ledger_account_id);
        $lines = SaccoStatementLine::where('import_id', $f->id)->orderBy('line_no')->get();
        $this->assertSame(['proposed', 'unmatched'], $lines->pluck('match_status')->all(), 'the tx_ref finds the collection; the stranger waits');
        $this->assertSame('unknown_format', $this->errorCode(fn () => $svc->import($company, $treasurer, "foo,bar\n1,2\n", 'x.csv', 'bank')));
    }

    public function test_http_permissions_and_isolation(): void
    {
        [$a] = $this->loanSacco();
        [$b] = $this->loanSacco('office', 'Other SACCO');
        $ta = $this->as($this->loanTreasurer($a));
        $hb = $this->as($this->loanTreasurer($b));
        $teller = $this->as($this->saccoStaff($a, 'sacco_teller'));
        $file = fn () => UploadedFile::fake()->createWithContent('bank.csv', "Date,Description,Amount\n01/10/2026,Interest,1200\n");
        $this->upload(['source' => 'bank', 'file' => $file()], $teller)->assertStatus(403);
        $r = $this->upload(['source' => 'bank', 'file' => $file()], $ta)->assertCreated()->assertJsonPath('data.statuses.unmatched.count', 1);
        $id = $r->json('data.import.id');
        $line = $this->getJson("/api/v1/sacco/statement-imports/{$id}", $ta)->assertOk()->json('data.lines.0.id');
        $this->getJson("/api/v1/sacco/statement-imports/{$id}", $hb)->assertNotFound();
        $this->postJson("/api/v1/sacco/statement-imports/{$id}/match", [], $hb)->assertNotFound();
        $this->postJson("/api/v1/sacco/statement-imports/{$id}/lines/{$line}/ignore", ['note' => 'x'], $hb)->assertNotFound();
        $this->getJson('/api/v1/sacco/statement-imports', $hb)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/sacco/statement-imports/{$id}/lines/{$line}/ignore", [], $ta)->assertStatus(422)->assertJsonPath('errors.code', 'note_required');
        $this->postJson("/api/v1/sacco/statement-imports/{$id}/lines/{$line}/ignore", ['note' => 'Interest is booked quarterly'], $ta)->assertOk()->assertJsonPath('data.match_status', 'ignored');
        $this->upload(['source' => 'bank', 'ledger_account_id' => ChartOfAccounts::id((int) $a->id, 'cash.office'), 'file' => $file()], $ta)
            ->assertNotFound();
    }
}
