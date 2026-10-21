<?php

namespace Tests\Feature\Sacco\Simple;

use App\Models\SaccoSetting;
use Tests\Concerns\MakesSimpleSacco;
use Tests\Feature\Api\ApiTestCase;

/** Flow 1: shares bought by number at the SACCO's share price, the register (shares, value, the SACCO's total) and the receipt. */
class SharesFlowTest extends ApiTestCase
{
    use MakesSimpleSacco;

    public function test_shares_are_bought_by_number_and_shown_per_member_and_in_total(): void
    {
        [$company, $owner] = $this->simpleSacco();
        $price = (int) SaccoSetting::for((int) $company->id)->share_par_minor;
        $a = $this->saver($company, $owner, 0, 'Amina');
        $b = $this->saver($company, $owner, 0, 'Brian');
        $h = $this->staffHeaders($owner);

        $r = $this->postJson('/api/v1/sacco/shares/purchase', ['member_id' => $a->id, 'shares_count' => 5, 'channel' => 'bank', 'value_date' => now()->subDay()->toDateString()], $h)
            ->assertCreated()->assertJsonPath('data.amount_minor', 5 * $price)->assertJsonPath('data.channel', 'bank');
        $this->postJson('/api/v1/sacco/shares/purchase', ['member_id' => $b->id, 'shares_count' => 2, 'channel' => 'cash'], $h)->assertCreated();
        $this->getJson('/api/v1/sacco/transactions/'.$r->json('data.id').'/receipt', $h)->assertOk();
        $this->postJson('/api/v1/sacco/shares/purchase', ['member_id' => $a->id, 'shares_count' => 1.5], $h)->assertStatus(422);

        $reg = $this->getJson('/api/v1/sacco/shares', $h)->assertOk();
        $this->assertSame($price, $reg->json('data.share_price_minor'));
        $this->assertSame(7, $reg->json('data.total_shares'));
        $this->assertSame(7 * $price, $reg->json('data.total_value_minor'));
        $rows = collect($reg->json('data.rows'))->keyBy('member_id');
        $this->assertSame([5, 5 * $price], [$rows[$a->id]['shares'], $rows[$a->id]['value_minor']]);

        $me = $this->getJson('/api/v1/sacco/me/shares', $this->memberHeaders($company, $a, $owner))->assertOk();
        $this->assertSame([5, 7], [$me->json('data.shares'), $me->json('data.sacco_total_shares')]);
    }
}
