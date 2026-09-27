<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Services\Team\TeamService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Admin\AdminTestCase;

/** Team chat (App\Services\Chat\ChatService): membership, company isolation, unread, delete, pictures, presence. */
class ChatServiceTest extends AdminTestCase
{
    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chat = new ChatService(Storage::fake('chat'));
    }

    private function member(Company $company, string $name, string $role = 'cashier', string $status = 'active'): User
    {
        $u = new User;
        $u->forceFill(['name' => $name, 'first_name' => $name, 'email' => strtolower($name).'_'.uniqid('', true).'@example.test',
            'username' => strtolower($name).'_'.uniqid('', true), 'password' => bcrypt('secret123'), 'status' => $status === 'active' ? 'Active' : 'Inactive',
            'company_id' => $company->id])->save();
        CompanyMember::create(['company_id' => $company->id, 'user_id' => $u->id, 'role' => $role, 'status' => $status, 'joined_at' => now()]);

        return $u->fresh();
    }

    private function refused(callable $fn): string
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return $e->errorCode();
        }
        $this->fail('Expected a refusal.');
    }

    public function test_direct_chat_is_one_per_pair_and_only_between_active_members_of_the_same_shop(): void
    {
        $t = $this->makeTenant();
        [$owner, $co] = [$t['user'], $t['company']];
        $ann = $this->member($co, 'Ann');
        $gone = $this->member($co, 'Gone', 'cashier', 'inactive');
        $other = $this->makeTenant();

        $c = $this->chat->directWith($co, $owner, $ann);
        $this->assertSame($c->id, $this->chat->directWith($co, $ann, $owner)->id, 'the same chat either way round');
        $this->assertSame('direct', $c->type);
        $this->assertSame(2, DB::table('chat_participants')->where('conversation_id', $c->id)->count());

        $this->assertSame('chat_not_member', $this->refused(fn () => $this->chat->directWith($co, $owner, $gone)));
        $this->assertSame('chat_not_member', $this->refused(fn () => $this->chat->directWith($co, $owner, $other['user'])));
        $this->assertSame('chat_self', $this->refused(fn () => $this->chat->directWith($co, $owner, $owner)));

        // Any role chats, including a viewer.
        $viewer = $this->member($co, 'Vic', 'viewer');
        $this->assertNotNull($this->chat->directWith($co, $viewer, $ann));
    }

    public function test_reading_is_for_participants_of_the_shop_only(): void
    {
        $t = $this->makeTenant();
        [$owner, $co] = [$t['user'], $t['company']];
        $ann = $this->member($co, 'Ann');
        $bob = $this->member($co, 'Bob');
        $c = $this->chat->directWith($co, $owner, $ann);
        $this->chat->send($c, $owner, 'Hello Ann');

        $this->assertTrue($this->chat->canRead($c, $ann));
        $this->assertFalse($this->chat->canRead($c, $bob), 'not in this direct chat');
        $this->expectException(AuthorizationException::class);
        $this->chat->messages($c, $bob);
    }

    public function test_another_shop_never_sees_or_reaches_a_chat(): void
    {
        $t = $this->makeTenant();
        $ann = $this->member($t['company'], 'Ann');
        $c = $this->chat->directWith($t['company'], $t['user'], $ann);
        $this->chat->send($c, $ann, 'Private');
        $x = $this->makeTenant();

        $this->assertNull($this->chat->find($x['user'], $c->id));
        $this->assertFalse($this->chat->canRead($c, $x['user']));
        $this->assertSame(0, $this->chat->unreadCount($x['user']));
        $list = $this->chat->conversationsFor($x['user']);
        $this->assertCount(1, $list, 'only their own team group');
        $this->assertSame('team', $list[0]['type']);
        $this->assertNotSame($c->id, $list[0]['id']);
        try {
            $this->chat->send($c, $x['user'], 'Hi');
            $this->fail('Another shop sent into the chat.');
        } catch (AuthorizationException) {
        }
        $this->assertSame(1, ChatMessage::withoutGlobalScopes()->where('conversation_id', $c->id)->count());
    }

    public function test_the_team_group_holds_every_active_member_and_counts_unread(): void
    {
        $t = $this->makeTenant();
        [$owner, $co] = [$t['user'], $t['company']];
        $ann = $this->member($co, 'Ann');
        $bob = $this->member($co, 'Bob', 'viewer');
        $gone = $this->member($co, 'Gone', 'cashier', 'inactive');

        $team = $this->chat->teamConversation($co);
        $this->assertSame($team->id, $this->chat->teamConversation($co)->id);
        $this->assertEqualsCanonicalizing([$owner->id, $ann->id, $bob->id],
            DB::table('chat_participants')->where('conversation_id', $team->id)->pluck('user_id')->map(fn ($i) => (int) $i)->all());
        $this->assertFalse($this->chat->canRead($team, $gone));

        $this->chat->send($team, $owner, 'Morning all');
        $this->chat->send($team, $owner, 'Stock count at 5');
        $this->assertSame(2, $this->chat->unreadCount($ann));
        $this->assertSame(2, $this->chat->unreadCount($bob));
        $this->assertSame(0, $this->chat->unreadCount($owner), 'one\'s own messages are read');

        // A member who joins later starts with the history already read, but can scroll it.
        $cara = app(TeamService::class)->createMember($co, $owner, 'Cara Late', 'cara_'.uniqid().'@example.test', null, 'secret-pass', 'cashier');
        $list = $this->chat->conversationsFor($cara);
        $this->assertSame(0, $list[0]['unread']);
        $this->assertCount(2, $this->chat->messages($team, $cara));

        $this->assertTrue($this->chat->markRead($team, $ann));
        $this->assertFalse($this->chat->markRead($team, $ann), 'nothing new: no write');
        $this->assertSame(0, $this->chat->unreadCount($ann));

        // Seen ticks: seen by everyone else in the group only when all have read.
        $max = (int) ChatMessage::withoutGlobalScopes()->where('conversation_id', $team->id)->max('id');
        $this->assertLessThan($max, ChatService::seenUpTo($this->chat->others($team, $owner)), 'Bob has not read yet');
        $this->chat->markRead($team, $bob);
        $this->chat->markRead($team, $cara);
        $this->assertSame($max, ChatService::seenUpTo($this->chat->others($team, $owner)),
            'a deactivated member does not hold the ticks back');
    }

    public function test_conversations_for_lists_last_message_unread_and_the_other_person(): void
    {
        $t = $this->makeTenant();
        [$owner, $co] = [$t['user'], $t['company']];
        $ann = $this->member($co, 'Ann');
        $c = $this->chat->directWith($co, $owner, $ann);
        $this->chat->send($c, $ann, 'First');
        $this->chat->send($c, $ann, 'Second');
        DB::table('admin_users')->where('id', $ann->id)->update(['last_active_at' => now()->subMinute()]);

        $list = $this->chat->conversationsFor($owner);
        $direct = collect($list)->firstWhere('type', 'direct');
        $this->assertSame('Ann', $direct['title']);
        $this->assertSame($ann->id, $direct['other']->id);
        $this->assertTrue($direct['other']->online);
        $this->assertSame('Second', $direct['last']->body);
        $this->assertFalse($direct['last']->mine);
        $this->assertSame(2, $direct['unread']);
        $this->assertSame(['team', 'direct'], array_column($list, 'type'), 'the team group sits on top until it has a message');

        $this->chat->markRead($c, $owner);
        $this->assertSame(0, collect($this->chat->conversationsFor($owner))->firstWhere('type', 'direct')['unread']);

        DB::table('admin_users')->where('id', $ann->id)->update(['last_active_at' => now()->subMinutes(6)]);
        $this->assertFalse(collect($this->chat->conversationsFor($owner))->firstWhere('type', 'direct')['other']->online);
    }

    public function test_paging_and_polling(): void
    {
        $t = $this->makeTenant();
        $ann = $this->member($t['company'], 'Ann');
        $c = $this->chat->directWith($t['company'], $t['user'], $ann);
        $ids = [];
        for ($i = 1; $i <= 35; $i++) {
            $ids[] = $this->chat->send($c, $i % 2 ? $ann : $t['user'], "m{$i}")->id;
        }
        $page = $this->chat->messages($c, $ann);
        $this->assertCount(30, $page);
        $this->assertSame('m6', $page->first()->body);
        $this->assertSame('m35', $page->last()->body);
        $earlier = $this->chat->messages($c, $ann, $page->first()->id);
        $this->assertSame(['m1', 'm2', 'm3', 'm4', 'm5'], $earlier->pluck('body')->all());
        $this->assertSame(['m34', 'm35'], $this->chat->messages($c, $ann, null, 30, $ids[32])->pluck('body')->all());
    }

    public function test_delete_is_for_own_messages_and_blanks_body_and_picture(): void
    {
        $t = $this->makeTenant();
        $ann = $this->member($t['company'], 'Ann');
        $c = $this->chat->directWith($t['company'], $t['user'], $ann);
        $m = $this->chat->send($c, $ann, 'Look', UploadedFile::fake()->image('shelf.jpg', 40, 40));
        $this->assertTrue(Storage::disk('chat')->exists($m->attachment_path));

        $this->assertSame('chat_not_yours', $this->refused(fn () => $this->chat->delete($m, $t['user'])));
        $since = now()->subSecond();
        $this->chat->delete($m->fresh(), $ann);
        $m = $m->fresh();
        $this->assertNotNull($m->deleted_at);
        $this->assertNull($m->body);
        $this->assertNull($m->attachment_path);
        $this->assertCount(0, Storage::disk('chat')->allFiles());
        $this->assertSame([$m->id], $this->chat->deletedSince($c, $since));
        $this->assertSame(0, $this->chat->unreadCount($t['user']), 'a deleted message is not unread');
    }

    public function test_pictures_only_and_size_limit(): void
    {
        $t = $this->makeTenant();
        $ann = $this->member($t['company'], 'Ann');
        $c = $this->chat->directWith($t['company'], $t['user'], $ann);

        $m = $this->chat->send($c, $ann, null, UploadedFile::fake()->image('p.png', 20, 20));
        $this->assertSame('image/png', $m->attachment_mime);
        $this->assertStringStartsWith('chat/'.$t['company']->id.'/', $m->attachment_path);
        $this->assertStringEndsWith('.png', $m->attachment_path);
        $this->assertNull($m->body);

        $this->assertSame('chat_bad_file', $this->refused(fn () => $this->chat->send($c, $ann, null, UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'))));
        $this->assertSame('chat_bad_file', $this->refused(fn () => $this->chat->send($c, $ann, null,
            UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo 1;'))), 'the content decides, not the name');
        $big = UploadedFile::fake()->image('big.jpg', 10, 10)->size(6000);
        $this->assertSame('chat_file_too_big', $this->refused(fn () => $this->chat->send($c, $ann, null, $big)));
        $this->assertSame('chat_empty', $this->refused(fn () => $this->chat->send($c, $ann, '   ')));
        $this->assertSame('chat_too_long', $this->refused(fn () => $this->chat->send($c, $ann, str_repeat('a', ChatService::MAX_BODY + 1))));
        $this->assertCount(1, Storage::disk('chat')->allFiles());
    }

    public function test_a_deactivated_person_cannot_receive_or_read(): void
    {
        $t = $this->makeTenant();
        $ann = $this->member($t['company'], 'Ann');
        $c = $this->chat->directWith($t['company'], $t['user'], $ann);
        app(TeamService::class)->setActive($t['company'], $ann, false);

        $this->assertSame('chat_not_member', $this->refused(fn () => $this->chat->send($c, $t['user'], 'Still there?')));
        $this->assertFalse($this->chat->canRead($c, $ann->fresh()));
        $this->assertFalse(collect($this->chat->conversationsFor($t['user']))->firstWhere('type', 'direct')['other']->active);
        $this->assertSame([], $this->chat->conversationsFor($ann->fresh()));
    }

    public function test_presence_is_written_at_most_once_a_minute_and_typing_expires(): void
    {
        $t = $this->makeTenant();
        $ann = $this->member($t['company'], 'Ann');
        $c = $this->chat->directWith($t['company'], $t['user'], $ann);

        $this->chat->touch($ann);
        $first = DB::table('admin_users')->where('id', $ann->id)->value('last_active_at');
        $this->assertNotNull($first);
        DB::table('admin_users')->where('id', $ann->id)->update(['last_active_at' => null]);
        $this->chat->touch($ann);
        $this->assertNull(DB::table('admin_users')->where('id', $ann->id)->value('last_active_at'), 'second touch within the minute: no write');
        Cache::forget('chat:active:'.$ann->id);

        $others = $this->chat->others($c, $t['user']);
        $this->assertSame([], $this->chat->typers($c, $others));
        $this->chat->typing($c, $ann);
        $this->assertSame(['Ann'], $this->chat->typers($c, $others));
        $this->chat->send($c, $ann, 'Done typing');
        $this->assertSame([], $this->chat->typers($c, $others), 'sending ends the typing');
        $this->travel(7)->seconds();
        $this->chat->typing($c, $ann);
        $this->travel(7)->seconds();
        $this->assertSame([], $this->chat->typers($c, $others));
    }
}
