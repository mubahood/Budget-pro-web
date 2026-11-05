<?php

namespace Tests\Feature\Budget;

use App\Exceptions\BusinessRuleException;
use App\Services\Budget\PledgeService;
use Illuminate\Support\Facades\DB;

/**
 * PB-01: pasting a list never guesses money. Every line of the review's §5.2, and the lines around them: a phone
 * number without its 0 is a phone, currency marks are stripped, two numbers are a question, and a figure out
 * of the ordinary waits for a tick.
 */
class PledgePasteTest extends PledgeTestCase
{
    private function rows(string $text): array
    {
        $program = $this->programModel($this->program());
        DB::table('companies')->where('id', $this->cid())->update(['country' => 'UG']);

        return app(PledgeService::class)->parse($program, $text);
    }

    private function row(string $line): array
    {
        return $this->rows($line)[0];
    }

    public static function lines(): array
    {
        return [
            'phone without 0, the amount after it' => ['Peter, 772123456, 50000', 'Peter', '+256772123456', '50000.00', null],
            'phone with 256' => ['Peter, 256772123456, 50000, Family', 'Peter', '+256772123456', '50000.00', 'Family'],
            'tabs from a spreadsheet' => ["Peter\t772123456\t50000\tFamily", 'Peter', '+256772123456', '50000.00', 'Family'],
            'the Ugandan way to write money' => ['Peter, 200,000/=', 'Peter', null, '200000.00', null],
            'with a phone and a group' => ['Peter, 0772123456, 200,000/=, Family', 'Peter', '+256772123456', '200000.00', 'Family'],
            'ugx after' => ['Peter, 50,000 ugx', 'Peter', null, '50000.00', null],
            'ugx before' => ['Peter, UGX 200,000', 'Peter', null, '200000.00', null],
            'shs before' => ['Peter, shs 50,000', 'Peter', null, '50000.00', null],
            'sh and ush' => ['Peter, ush 75000', 'Peter', null, '75000.00', null],
            'slash dash' => ['Peter, 100,000/-', 'Peter', null, '100000.00', null],
            'phone first, then the name' => ['0772123456, Peter, 40000', 'Peter', '+256772123456', '40000.00', null],
            'bare phone first' => ['772123456, Peter, 40000', 'Peter', '+256772123456', '40000.00', null],
            'short forms still read' => ['Peter, 0772 123 456, 1.5m, Friends', 'Peter', '+256772123456', '1500000.00', 'Friend'],
            'group before the amount' => ['Uncle Sam, Family, 500000', 'Uncle Sam', null, '500000.00', 'Family'],
            'a digit in the name' => ['Peter 7, 1,500,000', 'Peter 7', null, '1500000.00', null],
            'semicolons' => ['Peter; 772123456; 50,000; Family', 'Peter', '+256772123456', '50000.00', 'Family'],
        ];
    }

    /** @dataProvider lines */
    public function test_each_line_reads_as_a_person_would_read_it(string $line, string $name, ?string $phone, string $amount, ?string $group): void
    {
        $r = $this->row($line);
        $this->assertNull($r['error'], (string) $r['error']);
        $this->assertNull($r['check'], (string) $r['check']);
        $this->assertSame([$name, $phone, $amount, $group], [$r['name'], $r['phone'], $r['amount'], $r['group']]);
    }

    public function test_two_numbers_that_could_both_be_the_amount_are_a_question_and_never_a_group(): void
    {
        $r = $this->row('Peter, 50000, 30000');
        $this->assertSame('Two amounts on this line. Which one?', $r['error']);
        $this->assertNull($r['group']);
    }

    public function test_two_phones_are_a_question_and_the_second_is_never_money(): void
    {
        $r = $this->row('Peter, 0772123456, 0701222333, 50000');
        $this->assertSame('Two phone numbers on this line. Which one?', $r['error']);
        $this->assertSame('50000.00', $r['amount']);
    }

    public function test_a_name_and_a_group_must_contain_a_letter(): void
    {
        $this->assertSame('No name.', $this->row('11, 22')['error']);
        $r = $this->row('Peter, 50000, #1');
        $this->assertSame('The group needs a letter in it.', $r['error']);
        $this->assertNull($r['group']);
    }

    public function test_a_phone_number_alone_is_no_amount(): void
    {
        $r = $this->row('Peter, 772123456');
        $this->assertSame('No amount.', $r['error']);
        $this->assertSame('+256772123456', $r['phone']);
    }

    public function test_a_figure_out_of_the_ordinary_waits_for_the_tick(): void
    {
        $program = $this->programModel($this->program());
        $svc = app(PledgeService::class);
        $rows = $svc->parse($program, "Big Giver, 60,000,000\nSmall Giver, 500\nRight Giver, 50,000");
        $this->assertSame('Check this amount', $rows[0]['check']);
        $this->assertSame('Check this amount', $rows[1]['check']);
        $this->assertNull($rows[2]['check']);

        $this->assertSame(1, $svc->import($this->ownerUser(), $program, $rows));
        $this->assertSame(['Right Giver'], DB::table('contribution_records')->where('budget_program_id', $program->id)->pluck('name')->all());

        $rows = $svc->parse($program, "Big Giver, 60,000,000\nSmall Giver, 500");
        $this->assertSame(2, $svc->import($this->ownerUser(), $program, $rows, true));
        $this->assertSame('60000000.00', (string) DB::table('contribution_records')->where('budget_program_id', $program->id)->where('name', 'Big Giver')->value('amount'));
    }

    public function test_under_a_thousand_is_only_odd_in_a_currency_without_cents(): void
    {
        $usd = $this->registerTenant(['currency' => 'USD']);
        $h = $this->auth($usd['token']);
        $id = (int) $this->postJson('/api/v1/budget-programs', ['name' => 'Fund '.uniqid()], $h)->assertStatus(201)->json('data.id');
        $r = app(PledgeService::class)->parse($this->programModel($id), 'Peter, 500.50');
        $this->assertNull($r[0]['error']);
        $this->assertNull($r[0]['check']);
        $this->assertSame('500.50', $r[0]['amount']);
    }

    public function test_a_fraction_of_a_shilling_is_a_question(): void
    {
        $this->assertSame('Whole shillings only.', $this->row('Peter, 50000.75')['error']);
    }

    public function test_the_same_person_twice_is_skipped_and_another_with_the_same_name_is_added_and_marked(): void
    {
        $id = $this->program();
        $this->pledge($id, ['name' => 'Nakato Sarah', 'phone' => '0772123456']);
        $program = $this->programModel($id);
        $svc = app(PledgeService::class);
        $rows = $svc->parse($program, "Nakato Sarah, 0772123456, 50000\nnakato  sarah, 0701222333, 60000\nKato Paul, 0702000111, 70000\nKato Paul, 0703000111, 80000\nKato Paul, 0702000111, 90000");

        $this->assertTrue($rows[0]['duplicate'], 'same name, same phone');
        $this->assertFalse($rows[1]['duplicate']);
        $this->assertStringContainsString('Same name as a pledge already here', (string) $rows[1]['same_as']);
        $this->assertFalse($rows[3]['duplicate']);
        $this->assertSame('Same name as line 3', $rows[3]['same_as']);
        $this->assertTrue($rows[4]['duplicate'], 'the same line twice in the paste');

        $this->assertSame(3, $svc->import($this->ownerUser(), $program, $rows));
        $this->assertSame(2, DB::table('contribution_records')->where('budget_program_id', $id)->where('name', 'Kato Paul')->count());
    }

    public function test_only_what_is_ready_is_added_and_nothing_ready_is_refused(): void
    {
        $program = $this->programModel($this->program());
        $svc = app(PledgeService::class);
        $this->expectException(BusinessRuleException::class);
        $svc->import($this->ownerUser(), $program, $svc->parse($program, 'Peter, 50000, 30000'));
    }
}
