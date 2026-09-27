<?php

namespace Tests\Feature\Api;

use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Services\Team\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Team chat for the phone (ChatController over ChatService): list, direct, send, read, typing, delete, unread, presence; isolation. */
class ChatApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ChatService::class, new ChatService(Storage::fake('chat')));
    }

    private function member(array $t, string $name, string $role = 'cashier'): array
    {
        $u = new User;
        $u->forceFill(['name' => $name, 'email' => strtolower($name).uniqid('', true).'@example.test', 'password' => bcrypt('secret123'),
            'status' => 'Active', 'company_id' => $t['company_id']])->save();
        CompanyMember::create(['company_id' => $t['company_id'], 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);
        Permissions::flush();

        return ['id' => (int) $u->id, 'h' => $this->auth($u->createToken('t')->plainTextToken)];
    }

    public function test_direct_chat_send_read_typing_and_unread(): void
    {
        $this->getJson('/api/v1/chat/conversations')->assertStatus(401);
        $t = $this->registerTenant();
        $owner = ['id' => (int) $t['user_id'], 'h' => $this->auth($t['token'])];
        $ann = $this->member($t, 'Ann', 'viewer'); // any role chats

        $list = $this->getJson('/api/v1/chat/conversations', $owner['h'])->assertOk();
        $this->assertSame('team', $list->json('data.conversations.0.type'));
        $this->assertSame([$ann['id']], array_column($list->json('data.members'), 'id'));
        $this->assertSame($owner['id'], $list->json('data.me.id'));

        $cid = $this->postJson('/api/v1/chat/direct', ['user_id' => $ann['id']], $owner['h'])->assertOk()->json('data.conversation.id');
        $this->assertSame($cid, $this->postJson('/api/v1/chat/direct', ['user_id' => $owner['id']], $ann['h'])->json('data.conversation.id'), 'one chat per pair');
        $this->postJson('/api/v1/chat/direct', ['user_id' => $owner['id']], $owner['h'])->assertStatus(422)->assertJsonPath('errors.code', 'chat_self');

        $this->postJson("/api/v1/chat/conversations/$cid/messages", ['body' => '  '], $owner['h'])->assertStatus(422)->assertJsonPath('errors.code', 'chat_empty');
        $m1 = $this->postJson("/api/v1/chat/conversations/$cid/messages", ['body' => 'Hello Ann'], $owner['h'])->assertStatus(201);
        $m1->assertJsonPath('data.mine', true)->assertJsonPath('data.body', 'Hello Ann')->assertJsonPath('data.deleted', false);
        $id1 = $m1->json('data.id');

        $this->getJson('/api/v1/chat/unread', $ann['h'])->assertOk()->assertJsonPath('data.unread', 1);
        $row = collect($this->getJson('/api/v1/chat/conversations', $ann['h'])->json('data.conversations'))->firstWhere('id', $cid);
        $this->assertSame(1, $row['unread']);
        $this->assertSame('Hello Ann', $row['last']['body']);
        $this->assertFalse($row['last']['mine']);
        $this->assertSame($owner['id'], $row['other']['id']);

        // Typing shows in the other person's poll.
        $this->postJson("/api/v1/chat/conversations/$cid/typing", [], $owner['h'])->assertOk();
        $poll = $this->getJson("/api/v1/chat/conversations/$cid/messages?after=0", $ann['h'])->assertOk();
        $this->assertSame([$id1], array_column($poll->json('data.messages'), 'id'));
        $this->assertSame(['Test Owner'], $poll->json('data.typing'));
        $this->assertFalse($poll->json('data.messages.0.mine'));

        // Read: partially, then all; the seen pointer reaches the sender.
        $id2 = $this->postJson("/api/v1/chat/conversations/$cid/messages", ['body' => 'Second'], $owner['h'])->json('data.id');
        $this->postJson("/api/v1/chat/conversations/$cid/read", ['message_id' => $id1], $ann['h'])->assertOk()->assertJsonPath('data.unread', 1);
        $this->assertSame($id1, $this->getJson("/api/v1/chat/conversations/$cid/messages", $owner['h'])->json('data.seen_up_to'));
        $this->postJson("/api/v1/chat/conversations/$cid/read", [], $ann['h'])->assertOk()->assertJsonPath('data.unread', 0);
        $this->assertSame($id2, $this->getJson("/api/v1/chat/conversations/$cid/messages", $owner['h'])->json('data.seen_up_to'));
        $this->postJson("/api/v1/chat/conversations/$cid/read", ['message_id' => $id1], $ann['h'])->assertOk();
        $this->assertSame($id2, $this->getJson("/api/v1/chat/conversations/$cid/messages", $owner['h'])->json('data.seen_up_to'), 'the pointer never moves back');

        // Paging: the latest page, "load earlier", and the poll after an id.
        for ($i = 3; $i <= 5; $i++) {
            $this->postJson("/api/v1/chat/conversations/$cid/messages", ['body' => "Msg $i"], $ann['h'])->assertStatus(201);
        }
        $page = $this->getJson("/api/v1/chat/conversations/$cid/messages?limit=2", $owner['h'])->assertOk();
        $this->assertSame(['Msg 4', 'Msg 5'], array_column($page->json('data.messages'), 'body'), 'oldest first');
        $this->assertTrue($page->json('data.has_more'));
        $earlier = $this->getJson("/api/v1/chat/conversations/$cid/messages?limit=10&before=".$page->json('data.messages.0.id'), $owner['h']);
        $this->assertSame(['Hello Ann', 'Second', 'Msg 3'], array_column($earlier->json('data.messages'), 'body'));
        $this->assertFalse($earlier->json('data.has_more'));
        $this->assertSame(['Msg 3', 'Msg 4', 'Msg 5'], array_column($this->getJson("/api/v1/chat/conversations/$cid/messages?after=$id2", $owner['h'])->json('data.messages'), 'body'));
    }

    public function test_pictures_delete_and_presence(): void
    {
        $t = $this->registerTenant();
        $owner = ['id' => (int) $t['user_id'], 'h' => $this->auth($t['token'])];
        $ann = $this->member($t, 'Ann');
        $cid = $this->postJson('/api/v1/chat/direct', ['user_id' => $ann['id']], $owner['h'])->json('data.conversation.id');

        $pic = $this->post("/api/v1/chat/conversations/$cid/messages", ['body' => 'Shelf', 'file' => UploadedFile::fake()->image('shelf.jpg', 30, 30)],
            $owner['h'] + ['Accept' => 'application/json'])->assertStatus(201);
        $this->assertStringContainsString("chat/{$t['company_id']}/", (string) $pic->json('data.image_url'));
        $this->post("/api/v1/chat/conversations/$cid/messages", ['file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')],
            $owner['h'] + ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.code', 'chat_bad_file');

        $id = $pic->json('data.id');
        $this->deleteJson("/api/v1/chat/messages/$id", [], $ann['h'])->assertStatus(422)->assertJsonPath('errors.code', 'chat_not_yours');
        $this->deleteJson("/api/v1/chat/messages/$id", [], $owner['h'])->assertOk()
            ->assertJsonPath('data.deleted', true)->assertJsonPath('data.body', null)->assertJsonPath('data.image_url', null);
        $poll = $this->getJson("/api/v1/chat/conversations/$cid/messages?after=$id", $ann['h'])->assertOk();
        $this->assertContains($id, $poll->json('data.deleted_ids'));
        $this->getJson('/api/v1/chat/unread', $ann['h'])->assertJsonPath('data.unread', 0, 'a deleted message is not unread');

        Cache::forget('chat:active:'.$ann['id']);
        DB::table('admin_users')->where('id', $ann['id'])->update(['last_active_at' => null]);
        $this->postJson('/api/v1/presence', [], $ann['h'])->assertOk()->assertJsonPath('data.online', true);
        $this->assertNotNull(DB::table('admin_users')->where('id', $ann['id'])->value('last_active_at'));
        $members = $this->getJson('/api/v1/chat/conversations', $owner['h'])->json('data.members');
        $this->assertTrue($members[0]['online']);
    }

    public function test_isolation_other_shop_404_and_non_participant_403(): void
    {
        $t = $this->registerTenant();
        $owner = ['id' => (int) $t['user_id'], 'h' => $this->auth($t['token'])];
        $ann = $this->member($t, 'Ann');
        $bob = $this->member($t, 'Bob');
        $cid = $this->postJson('/api/v1/chat/direct', ['user_id' => $ann['id']], $owner['h'])->json('data.conversation.id');
        $mid = $this->postJson("/api/v1/chat/conversations/$cid/messages", ['body' => 'Private'], $owner['h'])->json('data.id');

        // Bob is in the shop but not in this chat: 403.
        $this->getJson("/api/v1/chat/conversations/$cid/messages", $bob['h'])->assertStatus(403);
        $this->postJson("/api/v1/chat/conversations/$cid/messages", ['body' => 'Hi'], $bob['h'])->assertStatus(403);
        $this->postJson("/api/v1/chat/conversations/$cid/read", [], $bob['h'])->assertStatus(403);
        $this->postJson("/api/v1/chat/conversations/$cid/typing", [], $bob['h'])->assertStatus(403);
        $this->deleteJson("/api/v1/chat/messages/$mid", [], $bob['h'])->assertStatus(403);
        $this->assertNotContains($cid, array_column($this->getJson('/api/v1/chat/conversations', $bob['h'])->json('data.conversations'), 'id'));

        // Another shop: 404 for the conversation, the message and the person.
        $x = $this->registerTenant();
        $xh = $this->auth($x['token']);
        $this->getJson("/api/v1/chat/conversations/$cid/messages", $xh)->assertStatus(404);
        $this->postJson("/api/v1/chat/conversations/$cid/messages", ['body' => 'Hi'], $xh)->assertStatus(404);
        $this->postJson("/api/v1/chat/conversations/$cid/read", [], $xh)->assertStatus(404);
        $this->deleteJson("/api/v1/chat/messages/$mid", [], $xh)->assertStatus(404);
        $this->postJson('/api/v1/chat/direct', ['user_id' => $ann['id']], $xh)->assertStatus(404);
        $this->postJson('/api/v1/chat/direct', ['user_id' => (int) $x['user_id']], $owner['h'])->assertStatus(404);
        $this->getJson('/api/v1/chat/unread', $xh)->assertJsonPath('data.unread', 0);

        // A deactivated member reads nothing and cannot be messaged.
        DB::table('company_members')->where('user_id', $ann['id'])->update(['status' => 'inactive']);
        Permissions::flush();
        $this->postJson("/api/v1/chat/conversations/$cid/messages", ['body' => 'Still there?'], $owner['h'])->assertStatus(422)->assertJsonPath('errors.code', 'chat_not_member');
    }
}
