<?php

namespace Tests\Feature\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetProgram;
use App\Models\Company;
use App\Models\ContributionRecord;
use App\Models\User;
use App\Services\Budget\ContributionPayments;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Roadmap 02 §6 / §8 "coverage where money moves": payments on one pledge at the same moment all count, the
 * pledge's paid figure always equals the sum of its history, and the "more than owed" check holds under
 * contention. Real processes (pcntl_fork), real MySQL row locks, no wrapping transaction (rows removed in tearDown).
 */
class ConcurrentPledgePaymentsTest extends TestCase
{
    private ?int $companyId = null;

    protected function tearDown(): void
    {
        if ($this->companyId) {
            DB::reconnect();
            foreach (['contribution_payments', 'contribution_records', 'budget_programs', 'number_sequences', 'company_members', 'financial_periods', 'financial_categories',
                'locations', 'subscriptions', 'app_notifications', 'message_log'] as $table) {
                if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                    DB::table($table)->where('company_id', $this->companyId)->delete();
                }
            }
            DB::table((new User())->getTable())->where('company_id', $this->companyId)->delete();
            DB::table('companies')->where('id', $this->companyId)->delete();
        }
        parent::tearDown();
    }

    /** @return array{0: Company, 1: User, 2: ContributionRecord} */
    private function pledge(string $amount): array
    {
        $company = Company::factory()->create(['name' => 'Pledge Concurrency Co', 'status' => 'Active']);
        $this->companyId = $company->id;
        $user = User::factory()->create(['company_id' => $company->id, 'email' => 'plg_'.uniqid().'@example.com', 'password' => bcrypt('secret123')]);
        $company->owner_id = $user->id;
        $company->save();
        $program = new BudgetProgram();
        $program->forceFill(['company_id' => $company->id, 'name' => 'Harambee '.uniqid()])->save();
        $record = new ContributionRecord();
        $record->forceFill(['company_id' => $company->id, 'budget_program_id' => $program->id, 'treasurer_id' => $user->id, 'name' => 'Big pledger', 'amount' => $amount])->save();

        return [$company, $user, $record];
    }

    /** @return array{ok: int, refused: int, errors: int} */
    private function race(int $n, callable $work): array
    {
        DB::disconnect(); // each child opens its own connection
        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                DB::reconnect();
                $code = 2;
                try {
                    $work($i);
                    $code = 0;
                } catch (BusinessRuleException $e) {
                    $code = $e->errorCode() === 'more_than_owed' ? 1 : 2;
                } catch (\Throwable $e) {
                    fwrite(STDERR, 'child error: '.get_class($e).' '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine()."\n");
                }
                DB::disconnect();
                exit($code);
            }
            $pids[] = $pid;
        }
        $out = ['ok' => 0, 'refused' => 0, 'errors' => 0];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            match (pcntl_wexitstatus($status)) {
                0 => $out['ok']++,
                1 => $out['refused']++,
                default => $out['errors']++,
            };
        }
        DB::reconnect();

        return $out;
    }

    public function test_parallel_payments_all_count_and_never_go_past_the_pledge(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        [$company, $user, $record] = $this->pledge('100000.00');

        // Ten payments of 1,000.10 at once: every one counts, to the cent.
        $r = $this->race(10, fn () => (new ContributionPayments())->record($company->id, $record->id, ['amount' => '1000.10'], $user->id));
        $this->assertSame(['ok' => 10, 'refused' => 0, 'errors' => 0], $r);
        $this->assertSame('10001.00', (string) DB::table('contribution_records')->where('id', $record->id)->value('paid_amount'));

        // Twelve payments of 30,000 against the 89,999.00 still owed: exactly two fit.
        $r = $this->race(12, fn () => (new ContributionPayments())->record($company->id, $record->id, ['amount' => '30000'], $user->id));
        $this->assertSame(['ok' => 2, 'refused' => 10, 'errors' => 0], $r);

        $sum = (string) DB::table('contribution_payments')->where('contribution_record_id', $record->id)->sum('amount');
        $row = DB::table('contribution_records')->where('id', $record->id)->first();
        $this->assertSame('70001.00', (string) $row->paid_amount);
        $this->assertSame($sum, (string) $row->paid_amount, 'paid_amount is exactly the sum of the history');
        $this->assertSame('29999.00', (string) $row->not_paid_amount);
        $this->assertSame(12, DB::table('contribution_payments')->where('contribution_record_id', $record->id)->count());
        $this->assertSame(12, DB::table('contribution_payments')->where('contribution_record_id', $record->id)->distinct()->count('receipt_no'), 'receipt numbers are unique');
        $this->assertSame('70001.00', (string) DB::table('budget_programs')->where('id', $record->budget_program_id)->value('total_collected'));
    }
}
