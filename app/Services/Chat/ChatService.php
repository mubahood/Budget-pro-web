<?php

namespace App\Services\Chat;

use App\Exceptions\BusinessRuleException;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Team chat between the members of one shop (direct chats and the automatic "Whole team" group).
 *
 * The rules, for every caller (the new web app today, the phone app later):
 *  - only ACTIVE members of the SAME company chat, whatever their role;
 *  - a user reads only the conversations they belong to (the team group: every active member);
 *  - every query carries the company, so one shop never sees another's chats;
 *  - only images are attached (jpg/png/webp, 5 MB at most), kept in budget-pro's public storage
 *    under chat/<company_id>/ with an unguessable name;
 *  - a member deletes only their own messages: the row stays, the body and the picture go.
 *
 * Presence: admin_users.last_active_at, set at most once a minute by touch(); "online" = active in
 * the last 5 minutes. Typing: a 6-second cache key per conversation and user.
 */
class ChatService
{
    public const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_BODY = 4000;

    public const ONLINE_MINUTES = 5;

    public const TYPING_SECONDS = 6;

    public const TEAM_KEY = 'team';

    public const TEAM_TITLE = 'Whole team';

    public function __construct(private ?Filesystem $disk = null) {}

    /** Where pictures live: budget-pro's public storage (the new app points `budgetpro.media_root` at the same folder). */
    public function disk(): Filesystem
    {
        return $this->disk ??= Storage::build(['driver' => 'local', 'root' => config('budgetpro.media_root') ?: public_path('storage')]);
    }

    /** The public URL of an attachment. */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $base = config('budgetpro.media_url') ?: rtrim((string) config('app.url'), '/').'/storage';

        return rtrim((string) $base, '/').'/'.ltrim($path, '/');
    }

    public static function isOnline(mixed $lastActiveAt): bool
    {
        return $lastActiveAt !== null && Carbon::parse($lastActiveAt)->gt(now()->subMinutes(self::ONLINE_MINUTES));
    }

    public static function displayName(object $u): string
    {
        return (string) ($u->name ?: trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: ($u->username ?? 'Member'));
    }

    // ── Membership ───────────────────────────────────────────────

    /** The shop's active members (any role): the owner, and everyone not deactivated. */
    private function activeQuery(Company $company)
    {
        return DB::table('admin_users as u')
            ->leftJoin('company_members as m', fn ($j) => $j->on('m.user_id', '=', 'u.id')->where('m.company_id', '=', $company->id))
            ->where('u.company_id', $company->id)
            ->where(fn ($q) => $q->where('u.id', (int) $company->owner_id)
                ->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('u.status')->orWhere('u.status', '!=', 'Inactive'))
                    ->where(fn ($q) => $q->whereNull('m.id')->orWhere('m.status', 'active'))));
    }

    /** @return list<int> */
    public function activeMemberIds(Company $company): array
    {
        return $this->activeQuery($company)->pluck('u.id')->map(fn ($id) => (int) $id)->all();
    }

    public function isActiveMember(Company $company, User|int $user): bool
    {
        $id = $user instanceof User ? (int) $user->id : (int) $user;

        return $this->activeQuery($company)->where('u.id', $id)->exists();
    }

    /**
     * The people one can chat with, for the "New chat" picker and the "Online now" row.
     *
     * @return Collection<int, object{id: int, name: string, avatar: ?string, last_active_at: ?string, online: bool}>
     */
    public function members(Company $company, ?User $except = null): Collection
    {
        return $this->activeQuery($company)
            ->when($except, fn ($q) => $q->where('u.id', '!=', $except->id))
            ->get(['u.id', 'u.name', 'u.first_name', 'u.last_name', 'u.username', 'u.avatar', 'u.last_active_at'])
            ->map(fn ($u) => (object) ['id' => (int) $u->id, 'name' => self::displayName($u), 'avatar' => $u->avatar ?: null,
                'last_active_at' => $u->last_active_at, 'online' => self::isOnline($u->last_active_at)])
            ->sortBy(fn ($u) => [$u->online ? 0 : 1, mb_strtolower($u->name)])->values();
    }

    private function companyOf(User $user): Company
    {
        $company = $user->company_id ? Company::withoutGlobalScopes()->find($user->company_id) : null;
        if ($company === null) {
            throw new AuthorizationException('This account is not linked to a shop.');
        }

        return $company;
    }

    // ── Conversations ────────────────────────────────────────────

    /** The direct chat between two active members of the shop, created the first time. */
    public function directWith(Company $company, User $a, User $b): ChatConversation
    {
        if ((int) $a->id === (int) $b->id) {
            throw BusinessRuleException::make('chat_self', 'Pick someone else to chat with.');
        }
        foreach ([$a, $b] as $u) {
            if ((int) $u->company_id !== (int) $company->id || ! $this->isActiveMember($company, $u)) {
                throw BusinessRuleException::make('chat_not_member', 'You can only chat with active members of your shop.');
            }
        }
        $key = min((int) $a->id, (int) $b->id).':'.max((int) $a->id, (int) $b->id);
        $conversation = $this->findOrCreate($company, $key, 'direct');
        $now = now();
        DB::table('chat_participants')->insertOrIgnore([
            ['conversation_id' => $conversation->id, 'user_id' => (int) $a->id, 'last_read_message_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['conversation_id' => $conversation->id, 'user_id' => (int) $b->id, 'last_read_message_id' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        return $conversation;
    }

    /** The shop's "Whole team" group, with every active member in it (synced on each open). */
    public function teamConversation(Company $company): ChatConversation
    {
        $conversation = $this->findOrCreate($company, self::TEAM_KEY, 'team', self::TEAM_TITLE);
        $this->syncTeam($conversation, $company);

        return $conversation;
    }

    /** Adds the active members who are not in the team group yet; they start with the history already read. */
    private function syncTeam(ChatConversation $conversation, Company $company, ?array $active = null): void
    {
        $active ??= $this->activeMemberIds($company);
        $in = DB::table('chat_participants')->where('conversation_id', $conversation->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $missing = array_diff($active, $in);
        if ($missing === []) {
            return;
        }
        $lastId = DB::table('chat_messages')->where('conversation_id', $conversation->id)->max('id');
        $now = now();
        DB::table('chat_participants')->insertOrIgnore(array_map(fn ($uid) => [
            'conversation_id' => $conversation->id, 'user_id' => $uid, 'last_read_message_id' => $lastId, 'created_at' => $now, 'updated_at' => $now,
        ], array_values($missing)));
    }

    private function findOrCreate(Company $company, string $key, string $type, ?string $title = null): ChatConversation
    {
        $q = fn () => ChatConversation::withoutGlobalScopes()->where('company_id', $company->id)->where('direct_key', $key)->first();
        if ($c = $q()) {
            return $c;
        }
        try {
            return ChatConversation::withoutGlobalScopes()->create(['company_id' => $company->id, 'type' => $type, 'direct_key' => $key, 'title' => $title]);
        } catch (QueryException $e) {
            return $q() ?? throw $e; // two first messages at once: the unique key keeps one
        }
    }

    /** A conversation of the user's own shop, or null (another shop's id reads as "not found"). */
    public function find(User $user, int $id): ?ChatConversation
    {
        return ChatConversation::withoutGlobalScopes()->where('company_id', (int) $user->company_id)->find($id);
    }

    public function canRead(ChatConversation $conversation, User $user): bool
    {
        if ((int) $conversation->company_id !== (int) $user->company_id) {
            return false;
        }
        $company = $this->companyOf($user);
        if (! $this->isActiveMember($company, $user)) {
            return false;
        }
        if ($conversation->isTeam()) {
            $this->syncTeam($conversation, $company, [(int) $user->id]);

            return true;
        }

        return DB::table('chat_participants')->where('conversation_id', $conversation->id)->where('user_id', $user->id)->exists();
    }

    public function assertCanRead(ChatConversation $conversation, User $user): void
    {
        if (! $this->canRead($conversation, $user)) {
            throw new AuthorizationException('You are not in this conversation.');
        }
    }

    // ── Messages ─────────────────────────────────────────────────

    public function send(ChatConversation $conversation, User $user, ?string $body, ?UploadedFile $file = null): ChatMessage
    {
        $this->assertCanRead($conversation, $user);
        $body = $body === null ? '' : trim(str_replace("\r\n", "\n", $body));
        if ($body === '' && $file === null) {
            throw BusinessRuleException::make('chat_empty', 'Type a message or attach a picture.');
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw BusinessRuleException::make('chat_too_long', 'That message is too long: keep it under '.number_format(self::MAX_BODY).' characters.');
        }
        $company = $this->companyOf($user);
        if ($conversation->isTeam()) {
            $this->syncTeam($conversation, $company);
        } else {
            $other = DB::table('chat_participants')->where('conversation_id', $conversation->id)->where('user_id', '!=', $user->id)->value('user_id');
            if ($other === null || ! $this->isActiveMember($company, (int) $other)) {
                throw BusinessRuleException::make('chat_not_member', 'This person is no longer an active member of your shop, so they cannot receive messages.');
            }
        }

        $path = $mime = null;
        if ($file !== null) {
            $real = $file->getRealPath();
            $mime = $real ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($real) : ''; // the content decides, never the name
            if (! $file->isValid() || ! isset(self::MIMES[$mime])) {
                throw BusinessRuleException::make('chat_bad_file', 'Only pictures can be sent: JPG, PNG or WebP.');
            }
            if ((int) $file->getSize() > self::MAX_BYTES) {
                throw BusinessRuleException::make('chat_file_too_big', 'That picture is too big: the limit is 5 MB.');
            }
            $path = 'chat/'.$conversation->company_id.'/'.Str::random(40).'.'.self::MIMES[$mime];
            if (! $this->disk()->put($path, (string) file_get_contents($real))) {
                throw BusinessRuleException::make('chat_upload_failed', 'The picture could not be saved. Try again.');
            }
        }

        $message = DB::transaction(function () use ($conversation, $user, $body, $path, $mime) {
            $m = ChatMessage::withoutGlobalScopes()->create([
                'company_id' => $conversation->company_id, 'conversation_id' => $conversation->id, 'user_id' => $user->id,
                'body' => $body === '' ? null : $body, 'attachment_path' => $path, 'attachment_mime' => $mime,
            ]);
            DB::table('chat_conversations')->where('id', $conversation->id)->update(['last_message_at' => $m->created_at, 'updated_at' => $m->created_at]);
            DB::table('chat_participants')->where('conversation_id', $conversation->id)->where('user_id', $user->id)
                ->update(['last_read_message_id' => $m->id, 'last_seen_at' => now(), 'updated_at' => now()]);

            return $m;
        });
        Cache::forget($this->typingKey($conversation->id, (int) $user->id));

        return $message;
    }

    /** Marks everything in the conversation as read by the user. Returns whether anything changed. */
    public function markRead(ChatConversation $conversation, User $user): bool
    {
        $this->assertCanRead($conversation, $user);
        $last = DB::table('chat_messages')->where('conversation_id', $conversation->id)->max('id');
        if ($last === null) {
            return false;
        }

        return DB::table('chat_participants')->where('conversation_id', $conversation->id)->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $last))
            ->update(['last_read_message_id' => $last, 'last_seen_at' => now(), 'updated_at' => now()]) > 0;
    }

    /** Deletes one's own message: the row stays ("This message was deleted"), its words and picture go. */
    public function delete(ChatMessage $message, User $user): void
    {
        if ((int) $message->company_id !== (int) $user->company_id || (int) $message->user_id !== (int) $user->id) {
            throw BusinessRuleException::make('chat_not_yours', 'You can only delete your own messages.');
        }
        if ($message->deleted_at !== null) {
            return;
        }
        $path = $message->attachment_path;
        $message->forceFill(['deleted_at' => now(), 'body' => null, 'attachment_path' => null, 'attachment_mime' => null])->save();
        if ($path) {
            $this->disk()->delete($path);
        }
    }

    /**
     * A page of the conversation, oldest first: the latest $limit, or the $limit before $beforeId,
     * or everything after $afterId (what a poll fetches).
     *
     * @return Collection<int, ChatMessage>
     */
    public function messages(ChatConversation $conversation, User $user, ?int $beforeId = null, int $limit = 30, ?int $afterId = null): Collection
    {
        $this->assertCanRead($conversation, $user);
        $q = ChatMessage::withoutGlobalScopes()->where('company_id', $conversation->company_id)->where('conversation_id', $conversation->id);
        if ($afterId !== null) {
            return $q->where('id', '>', $afterId)->orderBy('id')->limit(200)->get();
        }

        return $q->when($beforeId, fn ($q) => $q->where('id', '<', $beforeId))->orderByDesc('id')->limit(max(1, min($limit, 100)))->get()->reverse()->values();
    }

    /** Ids of messages in the conversation deleted since a moment (so an open thread can blank them). @return list<int> */
    public function deletedSince(ChatConversation $conversation, Carbon $since): array
    {
        return DB::table('chat_messages')->where('company_id', $conversation->company_id)->where('conversation_id', $conversation->id)
            ->where('deleted_at', '>=', $since)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The other people in the conversation: name, presence and how far they have read.
     *
     * @return Collection<int, object{id: int, name: string, avatar: ?string, last_active_at: ?string, online: bool, last_read_message_id: int}>
     */
    public function others(ChatConversation $conversation, User $viewer): Collection
    {
        // Only people still active in the shop: a deactivated member neither reads nor holds back the seen ticks.
        return DB::table('chat_participants as p')->join('admin_users as u', 'u.id', '=', 'p.user_id')
            ->join('companies as co', 'co.id', '=', 'u.company_id')
            ->leftJoin('company_members as m', fn ($j) => $j->on('m.user_id', '=', 'u.id')->on('m.company_id', '=', 'u.company_id'))
            ->where('p.conversation_id', $conversation->id)->where('p.user_id', '!=', $viewer->id)->where('u.company_id', $conversation->company_id)
            ->where(fn ($q) => $q->whereColumn('u.id', 'co.owner_id')
                ->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('u.status')->orWhere('u.status', '!=', 'Inactive'))
                    ->where(fn ($q) => $q->whereNull('m.id')->orWhere('m.status', 'active'))))
            ->get(['u.id', 'u.name', 'u.first_name', 'u.last_name', 'u.username', 'u.avatar', 'u.last_active_at', 'p.last_read_message_id'])
            ->map(fn ($u) => (object) ['id' => (int) $u->id, 'name' => self::displayName($u), 'avatar' => $u->avatar ?: null,
                'last_active_at' => $u->last_active_at, 'online' => self::isOnline($u->last_active_at), 'last_read_message_id' => (int) $u->last_read_message_id]);
    }

    /** Messages up to this id are seen: by the other person in a direct chat, by everyone else in the team group. */
    public static function seenUpTo(Collection $others): int
    {
        return $others->isEmpty() ? 0 : (int) $others->min('last_read_message_id');
    }

    // ── Unread, lists ────────────────────────────────────────────

    /** The unread messages of a user, as a query to count (the new app folds it into its one badge query). */
    public function unreadQuery(User $user)
    {
        return DB::table('chat_messages as cm')
            ->join('chat_participants as cp', fn ($j) => $j->on('cp.conversation_id', '=', 'cm.conversation_id')->where('cp.user_id', '=', (int) $user->id))
            ->where('cm.company_id', (int) $user->company_id)
            ->where('cm.user_id', '!=', (int) $user->id)
            ->whereNull('cm.deleted_at')
            ->whereRaw('cm.id > COALESCE(cp.last_read_message_id, 0)');
    }

    public function unreadCount(User $user): int
    {
        return (int) $this->unreadQuery($user)->count();
    }

    /**
     * The user's conversations, latest first: the whole-team group and each direct chat, with the last
     * message, the unread count and (for a direct chat) the other person.
     *
     * @return list<array{id: int, type: string, title: string, other: ?object, last: ?object, unread: int, last_message_at: ?string}>
     */
    public function conversationsFor(User $user): array
    {
        $company = $this->companyOf($user);
        $active = $this->activeMemberIds($company);
        if (! in_array((int) $user->id, $active, true)) {
            return [];
        }
        $team = $this->findOrCreate($company, self::TEAM_KEY, 'team', self::TEAM_TITLE);
        $this->syncTeam($team, $company, $active);

        $rows = DB::table('chat_conversations as c')
            ->join('chat_participants as p', fn ($j) => $j->on('p.conversation_id', '=', 'c.id')->where('p.user_id', '=', (int) $user->id))
            ->where('c.company_id', $company->id)
            ->get(['c.id', 'c.type', 'c.title', 'c.last_message_at', 'c.created_at', 'p.last_read_message_id']);
        if ($rows->isEmpty()) {
            return [];
        }
        $ids = $rows->pluck('id')->all();

        $last = DB::table('chat_messages as m')->leftJoin('admin_users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.id', fn ($q) => $q->from('chat_messages')->selectRaw('MAX(id)')->where('company_id', $company->id)->whereIn('conversation_id', $ids)->groupBy('conversation_id'))
            ->get(['m.id', 'm.conversation_id', 'm.user_id', 'm.body', 'm.attachment_path', 'm.deleted_at', 'm.created_at', 'u.name', 'u.first_name', 'u.last_name', 'u.username'])
            ->keyBy('conversation_id');

        $unread = $this->unreadQuery($user)->whereIn('cm.conversation_id', $ids)->groupBy('cm.conversation_id')
            ->selectRaw('cm.conversation_id, COUNT(*) as n')->pluck('n', 'conversation_id');

        $direct = $rows->where('type', 'direct')->pluck('id')->all();
        $others = $direct === [] ? collect() : DB::table('chat_participants as p')->join('admin_users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('p.conversation_id', $direct)->where('p.user_id', '!=', $user->id)
            ->get(['p.conversation_id', 'u.id', 'u.name', 'u.first_name', 'u.last_name', 'u.username', 'u.avatar', 'u.last_active_at', 'u.company_id'])
            ->keyBy('conversation_id');

        $out = [];
        foreach ($rows as $r) {
            $other = null;
            if ($r->type === 'direct') {
                $o = $others->get($r->id);
                if ($o === null || (int) $o->company_id !== (int) $company->id) {
                    continue; // the other person left the shop
                }
                $other = (object) ['id' => (int) $o->id, 'name' => self::displayName($o), 'avatar' => $o->avatar ?: null, 'last_active_at' => $o->last_active_at,
                    'online' => self::isOnline($o->last_active_at), 'active' => in_array((int) $o->id, $active, true)];
            }
            $m = $last->get($r->id);
            $out[] = [
                'id' => (int) $r->id, 'type' => $r->type,
                'title' => $other?->name ?? ($r->title ?: self::TEAM_TITLE),
                'other' => $other,
                'last' => $m ? (object) ['id' => (int) $m->id, 'mine' => (int) $m->user_id === (int) $user->id, 'sender' => self::displayName($m),
                    'body' => $m->body, 'image' => (bool) $m->attachment_path, 'deleted' => $m->deleted_at !== null, 'created_at' => $m->created_at] : null,
                'unread' => (int) ($unread[$r->id] ?? 0),
                'last_message_at' => $r->last_message_at,
                'sort' => (string) ($r->last_message_at ?? $r->created_at),
            ];
        }
        // Latest activity first; the team group stays on top until it has a message, so it can be found.
        usort($out, fn ($a, $b) => [$a['type'] === 'team' && $a['last'] === null ? 0 : 1, $b['sort']] <=> [$b['type'] === 'team' && $b['last'] === null ? 0 : 1, $a['sort']]);

        return array_map(function ($c) {
            unset($c['sort']);

            return $c;
        }, $out);
    }

    // ── Presence and typing ──────────────────────────────────────

    /** Marks the user as active now, at most once a minute (one UPDATE; the rest is a cache hit). */
    public function touch(User $user): void
    {
        if (Cache::add('chat:active:'.$user->id, 1, 60)) {
            DB::table('admin_users')->where('id', $user->id)->update(['last_active_at' => now()]);
        }
    }

    private function typingKey(int $conversationId, int $userId): string
    {
        return "chat:typing:{$conversationId}:{$userId}";
    }

    /** The user is typing in the conversation (for the next 6 seconds). No database query. */
    public function typing(ChatConversation $conversation, User $user): void
    {
        if ((int) $conversation->company_id === (int) $user->company_id) {
            Cache::put($this->typingKey($conversation->id, (int) $user->id), 1, self::TYPING_SECONDS);
        }
    }

    /**
     * Who among $others is typing right now.
     *
     * @param  Collection<int, object{id: int, name: string}>  $others
     * @return list<string> names
     */
    public function typers(ChatConversation $conversation, Collection $others): array
    {
        if ($others->isEmpty()) {
            return [];
        }
        $keys = $others->mapWithKeys(fn ($o) => [$this->typingKey($conversation->id, $o->id) => $o->name])->all();
        $hit = Cache::many(array_keys($keys));

        return array_values(array_map(fn ($k) => $keys[$k], array_keys(array_filter($hit))));
    }
}
