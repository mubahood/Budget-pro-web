<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalPaidReport;
use App\Models\RentalPayment;
use App\Services\Rentals\Leases\Leases;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** "I have paid", from both ends: the tenant's report and its uuid, the queue, one receipt however many times it is confirmed, and who may see which. */
class RentalsPaidReportsApiTest extends RentalsPhoneApiCase
{
    private function report(array $w, array $h, array $over = []): array
    {
        return $this->postJson('/api/v1/rentals/me/paid-reports', $over + ['uuid' => (string) Str::uuid(), 'amount_minor' => 200000, 'method' => 'mobile_money', 'reference' => 'MP2603261', 'paid_on' => '2026-03-26', 'note' => 'Sent this morning'], $h)
            ->assertStatus(201)->json('data');
    }

    public function test_a_report_waits_in_the_queue_and_becomes_one_receipt(): void
    {
        $w = $this->world();
        [$tenant] = $this->person($w['owner'], 'tenant', (int) $w['brian']->tenant_id);
        $owedBefore = Leases::position($w['brian']->fresh())['owed_minor'];
        $made = $this->report($w, $tenant);
        $this->assertKeys($made, ['id', 'uuid', 'lease_id', 'amount_minor', 'method', 'reference', 'paid_on', 'note', 'photo_url', 'status', 'decline_reason', 'decided_at', 'receipt', 'reported_at'], 'report');
        $this->assertSame('pending', $made['status']);
        $this->assertSame($owedBefore, Leases::position($w['brian']->fresh())['owed_minor'], 'a claim touches no bill');

        // The queue, for those who can receive money at that property.
        $owner = $this->h($w['owner']);
        $queue = $this->getJson('/api/v1/rentals/paid-reports', $owner)->assertOk();
        $row = $queue->json('data.0');
        $this->assertKeys($row, ['id', 'lease_id', 'tenant_id', 'tenant', 'unit', 'property', 'amount_minor', 'method', 'reference', 'paid_on', 'note', 'photo_uuid', 'photo_url', 'reported_at', 'same_receipt', 'same_report'], 'queue row');
        $this->assertSame('Brian Sample', $row['tenant']);
        $this->assertSame(1, $queue->json('meta.pagination.total'));
        $this->assertSame('2026-03-26', $row['paid_on']);
        $this->assertSame(1, $this->getJson('/api/v1/rentals/today', $owner)->assertOk()->json('data.paid_reports_waiting'));
        $this->fixture('paid_reports', $queue->json());

        // Confirm: the Payment, with a receipt, once.
        $key = ['Idempotency-Key' => 'confirm-'.$row['id']];
        $confirmed = $this->postJson('/api/v1/rentals/paid-reports/'.$row['id'].'/confirm', [], $owner + $key)->assertStatus(201);
        $this->assertSame(200000, $confirmed->json('data.amount_minor'));
        $this->assertKeys($confirmed->json('data'), ['id', 'number', 'lease', 'receipt', 'allocations', 'owed_after_minor'], 'payment');
        $this->assertSame($owedBefore - 200000, Leases::position($w['brian']->fresh())['owed_minor']);
        // The same key replays the first answer; another try is refused and names the receipt.
        $replay = $this->postJson('/api/v1/rentals/paid-reports/'.$row['id'].'/confirm', [], $owner + $key)->assertStatus(201);
        $this->assertSame('true', $replay->headers->get('Idempotent-Replay'));
        $this->assertSame($confirmed->json('data.id'), $replay->json('data.id'));
        $again = $this->postJson('/api/v1/rentals/paid-reports/'.$row['id'].'/confirm', [], $owner)->assertStatus(422);
        $again->assertJsonPath('errors.code', 'already_confirmed');
        $this->assertStringContainsString($confirmed->json('data.number'), $again->json('message'));
        $this->assertSame(1, RentalPayment::withoutGlobalScopes()->where('uuid', $made['uuid'])->count());
        $this->assertSame([], $this->getJson('/api/v1/rentals/paid-reports', $owner)->assertOk()->json('data'));
        $this->assertBooksAgree((int) $w['company']->id);

        // The tenant reads it became a receipt.
        $mine = $this->getJson('/api/v1/rentals/me/paid-reports', $tenant)->assertOk();
        $this->assertSame('confirmed', $mine->json('data.0.status'));
        $this->assertSame($confirmed->json('data.number'), $mine->json('data.0.receipt.number'));
        $this->assertSame(1, $mine->json('meta.pagination.total'));
        $this->fixture('me_paid_reports', $mine->json());
    }

    public function test_declining_keeps_the_reason_for_the_tenant_and_moves_nothing(): void
    {
        $w = $this->world();
        [$tenant] = $this->person($w['owner'], 'tenant', (int) $w['brian']->tenant_id);
        $made = $this->report($w, $tenant);
        $owner = $this->h($w['owner']);
        $owed = Leases::position($w['brian']->fresh())['owed_minor'];
        $this->postJson('/api/v1/rentals/paid-reports/'.$made['id'].'/decline', [], $owner)->assertStatus(422)->assertJsonPath('errors.code', 'reason_required');
        $d = $this->postJson('/api/v1/rentals/paid-reports/'.$made['id'].'/decline', ['reason' => 'No such payment on the statement'], $owner)->assertOk()->json('data');
        $this->assertSame(['id', 'status', 'decline_reason', 'decided_at'], array_keys($d));
        $this->assertSame('declined', $d['status']);
        $this->assertSame($owed, Leases::position($w['brian']->fresh())['owed_minor']);
        $this->postJson('/api/v1/rentals/paid-reports/'.$made['id'].'/confirm', [], $owner)->assertStatus(422)->assertJsonPath('errors.code', 'already_declined');
        $this->assertSame('No such payment on the statement', $this->getJson('/api/v1/rentals/me/paid-reports', $tenant)->assertOk()->json('data.0.decline_reason'));
    }

    public function test_the_same_uuid_is_one_report(): void
    {
        $w = $this->world();
        [$tenant] = $this->person($w['owner'], 'tenant', (int) $w['brian']->tenant_id);
        $uuid = (string) Str::uuid();
        $first = $this->report($w, $tenant, ['uuid' => $uuid]);
        $again = $this->postJson('/api/v1/rentals/me/paid-reports', ['uuid' => $uuid, 'amount_minor' => 200000, 'method' => 'mobile_money'], $tenant)->assertStatus(200);
        $this->assertSame('Already reported.', $again->json('message'));
        $this->assertSame($first['id'], $again->json('data.id'));
        $this->assertSame(1, RentalPaidReport::withoutGlobalScopes()->where('uuid', $uuid)->count());

        // Another tenant can't use it.
        [$other] = $this->person($w['owner'], 'tenant', (int) $w['amina']->tenant_id);
        $this->postJson('/api/v1/rentals/me/paid-reports', ['uuid' => $uuid, 'amount_minor' => 200000, 'method' => 'cash'], $other)->assertStatus(409)->assertJsonPath('errors.code', 'idempotency_conflict');
        $this->postJson('/api/v1/rentals/me/paid-reports', ['uuid' => 'nope', 'amount_minor' => 1, 'method' => 'cash'], $tenant)->assertStatus(422)->assertJsonPath('errors.code', 'uuid_invalid');
        $this->postJson('/api/v1/rentals/me/paid-reports', ['uuid' => (string) Str::uuid(), 'amount_minor' => 1.5, 'method' => 'cash'], $tenant)->assertStatus(422)->assertJsonPath('errors.code', 'amount_invalid');
        $this->postJson('/api/v1/rentals/me/paid-reports', ['uuid' => (string) Str::uuid(), 'amount_minor' => 100, 'method' => 'waiver'], $tenant)->assertStatus(422)->assertJsonPath('errors.code', 'method_invalid');
        $this->postJson('/api/v1/rentals/me/paid-reports', ['uuid' => (string) Str::uuid(), 'amount_minor' => 100, 'method' => 'cash', 'lease_id' => $w['carol']->id], $tenant)->assertStatus(404);
    }

    public function test_the_queue_keeps_to_a_caretakers_properties_and_refuses_those_who_cant_take_money(): void
    {
        $w = $this->world();
        [$brian] = $this->person($w['owner'], 'tenant', (int) $w['brian']->tenant_id);
        [$carol] = $this->person($w['owner'], 'tenant', (int) $w['carol']->tenant_id);
        $here = $this->report($w, $brian, ['reference' => 'SAME-REF']);
        $there = $this->report($w, $carol, ['reference' => 'SAME-REF']);
        $care = $this->h($w['caretaker']);
        $rows = $this->getJson('/api/v1/rentals/paid-reports', $care)->assertOk()->json('data');
        $this->assertSame([$here['id']], array_column($rows, 'id'));
        $this->assertTrue($rows[0]['same_report'], 'another report has this reference');
        $this->postJson('/api/v1/rentals/paid-reports/'.$there['id'].'/confirm', [], $care)->assertStatus(404);
        $this->postJson('/api/v1/rentals/paid-reports/'.$there['id'].'/decline', ['reason' => 'x'], $care)->assertStatus(404);
        $this->postJson('/api/v1/rentals/paid-reports/'.$here['id'].'/confirm', [], $care)->assertStatus(201);

        // A viewer can't take money; another account finds nothing.
        $viewer = $this->h($w['viewer']);
        $this->getJson('/api/v1/rentals/paid-reports', $viewer)->assertStatus(403);
        $this->postJson('/api/v1/rentals/paid-reports/'.$there['id'].'/confirm', [], $viewer)->assertStatus(403);
        $stranger = $this->stranger();
        $this->assertSame([], $this->getJson('/api/v1/rentals/paid-reports', $stranger)->assertOk()->json('data'));
        $this->postJson('/api/v1/rentals/paid-reports/'.$there['id'].'/confirm', [], $stranger)->assertStatus(404);
        $this->getJson('/api/v1/rentals/paid-reports')->assertStatus(401);
    }

    public function test_a_report_can_carry_the_tenants_own_photo_and_the_staff_can_open_it(): void
    {
        Storage::fake('public');
        $w = $this->world();
        [$tenant] = $this->person($w['owner'], 'tenant', (int) $w['brian']->tenant_id);
        [$other] = $this->person($w['owner'], 'tenant', (int) $w['carol']->tenant_id);
        $photo = (string) Str::uuid();
        $this->post('/api/v1/rentals/me/files', ['uuid' => $photo, 'file' => UploadedFile::fake()->image('slip.jpg')], $tenant + ['Accept' => 'application/json'])->assertStatus(201);
        $this->postJson('/api/v1/rentals/me/paid-reports', ['uuid' => (string) Str::uuid(), 'amount_minor' => 100, 'method' => 'cash', 'photo_uuid' => $photo], $other)->assertStatus(422)->assertJsonPath('errors.code', 'file_not_found');
        $made = $this->report($w, $tenant, ['photo_uuid' => $photo]);
        $this->assertNotNull($made['photo_url'], 'the tenant reads their own');
        $url = $this->getJson('/api/v1/rentals/paid-reports', $this->h($w['owner']))->assertOk()->json('data.0.photo_url');
        $this->assertNotNull($url);
        $this->get(str_replace(config('app.url'), '', $url))->assertOk();
    }

    public function test_a_landlord_has_no_i_have_paid(): void
    {
        $w = $this->world();
        [$landlord] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        $this->getJson('/api/v1/rentals/me/paid-reports', $landlord)->assertStatus(404);
        $this->postJson('/api/v1/rentals/me/paid-reports', ['uuid' => (string) Str::uuid(), 'amount_minor' => 100, 'method' => 'cash'], $landlord)->assertStatus(404);
        $this->getJson('/api/v1/rentals/paid-reports', $landlord)->assertStatus(401);
    }
}
