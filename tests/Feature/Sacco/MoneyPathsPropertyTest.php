<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoShareAccount;
use App\Models\SaccoTransaction;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Shares\ShareService;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoBooks;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.11/P1.12 (ADR 0009 §9): the ledger property test carried onto the real sub-ledgers. 200 seeded
 * random steps through the services a teller uses (deposits, withdrawals under and over the threshold and their
 * approvals or rejections, fees, transfers, share purchases at and past the cap, receipt reversals, phone receipts
 * held and allocated, replays). After every step every verifier check holds, and the savings accounts and share
 * counts equal a model kept beside the books in plain integers.
 */
class MoneyPathsPropertyTest extends ApiTestCase
{
    use MakesSaccoBooks, MakesSaccoTeam;

    /** Refusals a random step may meet; anything else fails the test. */
    private const EXPECTED = ['insufficient_available', 'insufficient_balance', 'share_cap_exceeded', 'not_whole_shares', 'not_enough_shares', 'already_reversed', 'withdrawal_limit'];

    public function test_the_real_money_paths_keep_the_books_sound(): void
    {
        mt_srand(20261021);
        $s = $this->saccoCompany();
        $c = $s['company'];
        $cid = (int) $c->id;
        $svc = app(SaccoTransactions::class);
        $approvals = app(SaccoApprovals::class);
        $accounts = [];
        $members = [];
        $model = [];
        $shares = [];
        for ($i = 0; $i < 5; $i++) {
            $m = $this->activeMember($c, $s['owner'], 'Prop'.$i);
            $members[] = (int) $m->id;
            $a = $this->savingsAccount($c, $m, $s['teller']);
            $accounts[] = $a;
            $model[(int) $a->id] = 0;
            $shares[(int) $m->id] = 0;
        }
        $posted = [];
        $uuids = [];

        for ($step = 0; $step < 200; $step++) {
            $roll = mt_rand(1, 100);
            $a = $accounts[array_rand($accounts)];
            $amount = mt_rand(1, 300) * 1000;
            try {
                if ($roll <= 28) {
                    $uuid = (string) Str::uuid();
                    $tx = $this->deposit($c, $a, $amount, $s['teller'], ['uuid' => $uuid]);
                    $uuids[] = [$uuid, $a, $amount];
                    $posted[] = $tx;
                    $model[(int) $a->id] += $amount;
                } elseif ($roll <= 45) {
                    $tx = $this->withdraw($c, $a, $amount * 5, $s['teller']);
                    if ($tx->status === 'pending_approval') {
                        if (mt_rand(0, 1)) {
                            $approvals->approve($c, (int) $tx->approval_id, $s['treasurer']);
                            $model[(int) $a->id] -= $amount * 5;
                            $posted[] = $tx->fresh();
                        } else {
                            $approvals->reject($c, (int) $tx->approval_id, $s['treasurer'], 'Property step');
                        }
                    } else {
                        $model[(int) $a->id] -= $amount * 5;
                        $posted[] = $tx;
                    }
                } elseif ($roll <= 52) {
                    $svc->record($c, ['kind' => 'fee', 'savings_account_id' => $a->id, 'amount_minor' => 500], $s['teller']);
                    $model[(int) $a->id] -= 500;
                } elseif ($roll <= 60) {
                    $to = SaccoSavingsAccount::withoutGlobalScopes()->where('member_id', $a->member_id)->where('product_id', $this->product($c, 'CMP')->id)->first()
                        ?? $this->savingsAccount($c, $a->member()->first(), $s['teller'], 'CMP');
                    $model[(int) $to->id] ??= 0;
                    $svc->record($c, ['kind' => 'internal_transfer', 'savings_account_id' => $a->id, 'counter_account_id' => $to->id, 'amount_minor' => $amount], $s['teller']);
                    $model[(int) $a->id] -= $amount;
                    $model[(int) $to->id] += $amount;
                } elseif ($roll <= 72) {
                    $memberId = $members[array_rand($members)];
                    $minor = mt_rand(1, 6) * 10_000 + (mt_rand(1, 10) === 1 ? 5_000 : 0);
                    $tx = app(ShareService::class)->purchase($c, ['member_id' => $memberId, 'amount_minor' => $minor], $s['teller'])['transaction'];
                    $shares[$memberId] += (int) $tx->shares_count;
                    $posted[] = $tx;
                } elseif ($roll <= 80 && $posted !== []) {
                    $target = $posted[array_rand($posted)];
                    $req = $svc->reverse($c, (int) $target->id, 'Property step '.$step, $s['teller']);
                    $approvals->approve($c, (int) $req->id, $s['treasurer']);
                    if ($target->kind === 'share_purchase') {
                        $shares[(int) $target->member_id] -= (int) $target->shares_count;
                    } else {
                        $model[(int) $target->savings_account_id] += $target->kind === 'deposit' ? -(int) $target->amount_minor : (int) $target->amount_minor + (int) $target->fee_minor;
                    }
                } elseif ($roll <= 88) {
                    // A phone receipt for an account the server does not know: held in 2150, then allocated by two officers.
                    $r = $svc->applySynced($c, ['kind' => 'deposit', 'member_id' => $a->member_id, 'savings_account_id' => 999999999, 'amount_minor' => $amount], $s['teller'], 'prop', (string) Str::uuid());
                    $this->assertSame('held', $r['model']->status);
                    $req = $approvals->request($c, 'held_receipt_allocation', ['held_entry_id' => $r['model']->journal_entry_id, 'amount_minor' => $amount,
                        'target' => ['type' => 'savings', 'id' => $a->id, 'account_id' => $a->ledger_account_id, 'member_id' => $a->member_id]], $s['teller']);
                    $approvals->approve($c, (int) $req->id, $s['treasurer']);
                    $model[(int) $a->id] += $amount;
                    $this->assertSame('posted', $r['model']->fresh()->status);
                } elseif ($uuids !== []) {
                    [$uuid, $acc, $minor] = $uuids[array_rand($uuids)];
                    $this->assertTrue($svc->record($c, ['kind' => 'deposit', 'uuid' => $uuid, 'savings_account_id' => $acc->id, 'amount_minor' => $minor], $s['teller'])['replayed']);
                }
            } catch (BusinessRuleException $e) {
                $this->assertContains($e->errorCode(), self::EXPECTED, "step {$step}: ".$e->getMessage());
            }

            $result = (new LedgerVerifier())->verify($cid);
            $this->assertSame([], LedgerVerifier::failed($result), "step {$step}: ".json_encode($result['checks']));
            if ($step % 20 === 0 || $step === 199) {
                foreach ($model as $id => $balance) {
                    $this->assertSame($balance, (int) SaccoSavingsAccount::withoutGlobalScopes()->whereKey($id)->value('balance_minor'), "step {$step}: account {$id}");
                }
                foreach ($shares as $memberId => $n) {
                    $this->assertSame($n, (int) SaccoShareAccount::withoutGlobalScopes()->where('member_id', $memberId)->value('shares_count'), "step {$step}: shares of {$memberId}");
                }
            }
        }
        $this->assertGreaterThan(80, SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'posted')->count());
        $this->assertGreaterThan(0, SaccoTransaction::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'reversal')->count());
    }
}
