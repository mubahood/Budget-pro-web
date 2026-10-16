<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\IdempotentRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** D5: Idempotency-Key on authenticated API writes — replay, reuse, in progress, what is never stored, pruning. */
class IdempotencyKeyTest extends ApiTestCase
{
    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->registerTenant();
        $this->h = $this->auth($this->t['token']);
    }

    private function rows(): int
    {
        return DB::table(IdempotentRequests::TABLE)->where('user_id', $this->t['user_id'])->count();
    }

    public function test_a_retry_replays_the_first_answer_and_writes_nothing_twice(): void
    {
        $key = (string) Str::uuid();
        $body = ['name' => 'Akello '.uniqid(), 'phone' => '0772000111'];
        $first = $this->postJson('/api/v1/customers', $body, $this->h + ['Idempotency-Key' => $key])->assertStatus(201);
        $this->assertNull($first->headers->get('Idempotent-Replay'));

        $again = $this->postJson('/api/v1/customers', $body, $this->h + ['Idempotency-Key' => $key])->assertStatus(201);
        $this->assertSame('true', $again->headers->get('Idempotent-Replay'));
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, DB::table('customers')->where('company_id', $this->t['company_id'])->where('name', $body['name'])->count());

        // The same key with a different body is refused; a new key is a new action.
        $this->postJson('/api/v1/customers', ['name' => 'Someone else'] + $body, $this->h + ['Idempotency-Key' => $key])
            ->assertStatus(422)->assertJsonPath('errors.code', 'idempotency_key_reused');
        $this->postJson('/api/v1/customers', ['phone' => '0772000222'] + $body, $this->h + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201);

        // Keys belong to one person: another member's same key runs afresh.
        $other = $this->registerTenant();
        $this->postJson('/api/v1/customers', $body, $this->auth($other['token']) + ['Idempotency-Key' => $key])->assertStatus(201)->assertHeaderMissing('Idempotent-Replay');

        // Without the header nothing is stored; a bad key is refused.
        $before = $this->rows();
        $this->postJson('/api/v1/customers', ['name' => 'No key '.uniqid()], $this->h)->assertStatus(201);
        $this->assertSame($before, $this->rows());
        $this->postJson('/api/v1/customers', ['name' => 'Bad key'], $this->h + ['Idempotency-Key' => 'has spaces'])->assertStatus(422)->assertJsonPath('errors.code', 'idempotency_key_invalid');
    }

    public function test_in_progress_stale_and_answers_that_are_not_stored(): void
    {
        $key = 'k-'.uniqid();
        $body = ['name' => 'Running '.uniqid()];
        $this->postJson('/api/v1/customers', $body, $this->h + ['Idempotency-Key' => $key])->assertStatus(201);
        // Pretend the first request is still running: the same request now answers 409 in_progress.
        DB::table(IdempotentRequests::TABLE)->where('key', $key)->update(['status' => null, 'body' => null, 'created_at' => now()]);
        $this->postJson('/api/v1/customers', $body, $this->h + ['Idempotency-Key' => $key])->assertStatus(409)->assertJsonPath('errors.code', 'in_progress');
        // A "running" row older than two minutes died with its request: the retry runs.
        DB::table(IdempotentRequests::TABLE)->where('key', $key)->update(['created_at' => now()->subMinutes(5)]);
        $this->postJson('/api/v1/customers', ['name' => $body['name'].' b'], $this->h + ['Idempotency-Key' => $key])->assertStatus(201)->assertHeaderMissing('Idempotent-Replay');
        $this->assertNotNull(DB::table(IdempotentRequests::TABLE)->where('key', $key)->value('status'));

        // A validation error is not stored: the fixed request with the same key runs.
        $fix = 'fix-'.uniqid();
        $this->postJson('/api/v1/customers', ['phone' => '0772'], $this->h + ['Idempotency-Key' => $fix])->assertStatus(422);
        $this->assertSame(0, DB::table(IdempotentRequests::TABLE)->where('key', $fix)->count());
        $this->postJson('/api/v1/customers', ['name' => 'Fixed '.uniqid()], $this->h + ['Idempotency-Key' => $fix])->assertStatus(201);

        // 5xx answers and bodies over 1 MB are never stored.
        Route::middleware(['api', 'auth:sanctum', 'api.tenant', 'api.idempotent'])->post('api/v1/_test/answer', fn () => response()->json(['code' => request('status') < 300 ? 1 : 0], (int) request('status')));
        $this->postJson('/api/v1/_test/answer', ['status' => 500], $this->h + ['Idempotency-Key' => 'boom'])->assertStatus(500);
        $this->postJson('/api/v1/_test/answer', ['status' => 503], $this->h + ['Idempotency-Key' => 'boom'])->assertStatus(503);
        $this->assertSame(0, DB::table(IdempotentRequests::TABLE)->where('key', 'boom')->count());
        $this->postJson('/api/v1/_test/answer', ['status' => 200, 'blob' => str_repeat('x', IdempotentRequests::MAX_BODY_BYTES + 10)], $this->h + ['Idempotency-Key' => 'big'])->assertOk();
        $this->assertSame(0, DB::table(IdempotentRequests::TABLE)->where('key', 'big')->count());
        $this->postJson('/api/v1/_test/answer', ['status' => 200], $this->h + ['Idempotency-Key' => 'small'])->assertOk();
        $this->assertSame(1, DB::table(IdempotentRequests::TABLE)->where('key', 'small')->count());
    }

    public function test_sync_is_left_alone_and_every_authenticated_write_carries_the_middleware(): void
    {
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device, 'name' => 'Till'], $this->h)->assertOk();
        $this->postJson('/api/v1/sync/push', ['batches' => []], $this->h + ['X-Device-Id' => $device, 'Idempotency-Key' => 'sync-1']);
        $this->assertSame(0, DB::table(IdempotentRequests::TABLE)->where('key', 'sync-1')->count());

        $missing = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $mw = $route->gatherMiddleware();
            if (str_starts_with($route->uri(), 'api/v1/') && in_array('auth:sanctum', $mw, true) && array_diff($route->methods(), ['GET', 'HEAD']) !== []
                && $route->uri() !== 'api/v1/pingpin/auth/verify-password' && ! in_array('api.idempotent', $mw, true)) {
                $missing[] = $route->uri();
            }
        }
        $this->assertSame([], $missing, 'authenticated writes without api.idempotent');
    }

    public function test_pruning_removes_expired_answers_and_old_links(): void
    {
        DB::table(IdempotentRequests::TABLE)->insert([
            ['company_id' => $this->t['company_id'], 'user_id' => $this->t['user_id'], 'key' => 'old', 'fingerprint' => str_repeat('a', 64), 'status' => 201, 'created_at' => now()->subDays(2), 'expires_at' => now()->subDay()],
            ['company_id' => $this->t['company_id'], 'user_id' => $this->t['user_id'], 'key' => 'new', 'fingerprint' => str_repeat('b', 64), 'status' => 201, 'created_at' => now(), 'expires_at' => now()->addDay()],
        ]);
        DB::table('web_link_tokens')->insert(['nonce_hash' => hash('sha256', uniqid()), 'company_id' => $this->t['company_id'], 'user_id' => $this->t['user_id'], 'path' => '/sales', 'expires_at' => now()->subDays(2), 'created_at' => now()->subDays(2)]);
        $this->artisan('api:prune-keys')->assertSuccessful();
        $this->assertSame(['new'], DB::table(IdempotentRequests::TABLE)->where('user_id', $this->t['user_id'])->pluck('key')->all());
        $this->assertSame(0, DB::table('web_link_tokens')->where('user_id', $this->t['user_id'])->count());
    }
}
