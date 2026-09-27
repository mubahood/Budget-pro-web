<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Team chat for the phone app, over budget-pro's ChatService (the same rules as the web's Chat screens):
 * any active member of the shop chats, whatever their role. Another shop's conversation or message
 * answers 404; a conversation of one's own shop that one is not in answers 403.
 *
 * Realtime is polling: the phone asks for `messages?after=<last id>` every few seconds while a thread is
 * open (the answer also carries who is typing, the seen pointer and recent deletions) and for `unread`
 * in the background.
 */
class ChatController extends Controller
{
    use ApiResponse;

    public const PAGE = 30;

    /** Deleted messages reported to an open thread (so it can blank them). */
    public const DELETED_WINDOW_MINUTES = 10;

    public function __construct(private readonly ChatService $chat)
    {
    }

    /** GET chat/conversations */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $this->chat->touch($me);
        $company = $request->attributes->get('company') ?? $me->company;
        $conversations = array_map(fn (array $c) => [
            'id' => $c['id'], 'type' => $c['type'], 'title' => $c['title'],
            'other' => $c['other'] ? $this->person($c['other']) + ['active' => (bool) ($c['other']->active ?? true)] : null,
            'last' => $c['last'] ? [
                'id' => $c['last']->id, 'mine' => $c['last']->mine, 'sender' => $c['last']->sender,
                'body' => $c['last']->deleted ? null : $c['last']->body, 'image' => $c['last']->image && ! $c['last']->deleted,
                'deleted' => $c['last']->deleted, 'created_at' => $this->iso($c['last']->created_at),
            ] : null,
            'unread' => $c['unread'],
            'last_message_at' => $this->iso($c['last_message_at']),
        ], $this->chat->conversationsFor($me));

        return $this->success([
            'conversations' => $conversations,
            'members' => $this->chat->members($company, $me)->map(fn ($m) => $this->person($m))->values(),
            'unread' => array_sum(array_column($conversations, 'unread')),
            'me' => ['id' => (int) $me->id, 'name' => ChatService::displayName($me)],
        ], 'Conversations.');
    }

    /** GET chat/conversations/{id}/messages?before=&after=&limit= */
    public function messages(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'], 'after' => ['nullable', 'integer', 'min:0'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $me = $request->user();
        $conversation = $this->readable($me, $id);
        $this->chat->touch($me);
        $limit = (int) ($data['limit'] ?? self::PAGE);
        $after = isset($data['after']) ? (int) $data['after'] : null;
        $before = isset($data['before']) ? (int) $data['before'] : null;

        $page = $this->chat->messages($conversation, $me, $before, $limit, $after);
        $hasMore = false;
        if ($after === null && $page->count() >= $limit) {
            $hasMore = ChatMessage::withoutGlobalScopes()->where('company_id', $conversation->company_id)
                ->where('conversation_id', $conversation->id)->where('id', '<', (int) $page->first()->id)->exists();
        }
        $others = $this->chat->others($conversation, $me);
        $names = $this->names((int) $conversation->company_id);

        return $this->success([
            'conversation' => $this->conversationRow($conversation, $others),
            'messages' => $page->map(fn (ChatMessage $m) => $this->message($m, $me, $names))->values(),
            'has_more' => $hasMore,
            'seen_up_to' => ChatService::seenUpTo($others),
            'typing' => $this->chat->typers($conversation, $others),
            'others' => $others->map(fn ($o) => $this->person($o) + ['last_read_message_id' => (int) $o->last_read_message_id])->values(),
            'deleted_ids' => $this->chat->deletedSince($conversation, now()->subMinutes(self::DELETED_WINDOW_MINUTES)),
        ], 'Messages.');
    }

    /** POST chat/conversations/{id}/messages — JSON {body}, or multipart with `file` (a picture) and an optional `body`. */
    public function send(Request $request, int $id): JsonResponse
    {
        $request->validate(['body' => ['nullable', 'string', 'max:20000']]);
        $me = $request->user();
        $conversation = $this->readable($me, $id);
        $file = $request->file('file');
        $message = $this->chat->send($conversation, $me, $request->input('body'), is_array($file) ? ($file[0] ?? null) : $file);

        return $this->success($this->message($message->fresh(), $me, $this->names((int) $conversation->company_id)), 'Message sent.', 201);
    }

    /** POST chat/direct {user_id} — finds or starts the direct chat with a member of one's shop. */
    public function direct(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'min:1']]);
        $me = $request->user();
        $other = User::withoutGlobalScopes()->where('company_id', (int) $me->company_id)->find((int) $data['user_id']);
        if ($other === null) {
            return $this->notFound('That person is not on your team.');
        }
        $company = $request->attributes->get('company') ?? $me->company;
        $conversation = $this->chat->directWith($company, $me, $other);

        return $this->success(['conversation' => $this->conversationRow($conversation, $this->chat->others($conversation, $me))], 'Conversation.');
    }

    /** POST chat/conversations/{id}/read {message_id?} */
    public function read(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['message_id' => ['nullable', 'integer', 'min:1']]);
        $me = $request->user();
        $conversation = $this->readable($me, $id);
        $this->chat->markRead($conversation, $me, isset($data['message_id']) ? (int) $data['message_id'] : null);

        return $this->success(['unread' => $this->chat->unreadCount($me)], 'Read.');
    }

    /** POST chat/conversations/{id}/typing {} — "typing…" for the next few seconds. */
    public function typing(Request $request, int $id): JsonResponse
    {
        $this->chat->typing($this->readable($request->user(), $id), $request->user());

        return $this->success(['typing' => true, 'seconds' => ChatService::TYPING_SECONDS], 'Typing.');
    }

    /** DELETE chat/messages/{id} — one's own message: the words and the picture go, the row stays. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $me = $request->user();
        $message = ChatMessage::withoutGlobalScopes()->where('company_id', (int) $me->company_id)->find($id);
        if ($message === null) {
            return $this->notFound('Message not found.');
        }
        $conversation = ChatConversation::withoutGlobalScopes()->where('company_id', (int) $me->company_id)->find($message->conversation_id);
        if ($conversation === null || ! $this->chat->canRead($conversation, $me)) {
            return $this->forbidden('You are not in this conversation.');
        }
        $this->chat->delete($message, $me);

        return $this->success($this->message($message->fresh(), $me, $this->names((int) $me->company_id)), 'Message deleted.');
    }

    /** GET chat/unread — the badge count. */
    public function unread(Request $request): JsonResponse
    {
        return $this->success(['unread' => $this->chat->unreadCount($request->user())], 'Unread.');
    }

    /** POST presence {} — "online" (last_active_at is written at most once a minute). */
    public function presence(Request $request): JsonResponse
    {
        $this->chat->touch($request->user());

        return $this->success(['online' => true, 'online_minutes' => ChatService::ONLINE_MINUTES], 'Online.');
    }

    // ── Helpers ──────────────────────────────────────────────────

    /** The conversation, if it is the user's shop's (else 404) and the user is in it (else 403). */
    private function readable(User $user, int $id): ChatConversation
    {
        $conversation = $this->chat->find($user, $id);
        abort_if($conversation === null, 404, 'Conversation not found.');
        abort_unless($this->chat->canRead($conversation, $user), 403, 'You are not in this conversation.');

        return $conversation;
    }

    private function conversationRow(ChatConversation $c, $others): array
    {
        $other = $c->isTeam() ? null : $others->first();

        return [
            'id' => (int) $c->id, 'type' => $c->type,
            'title' => $c->isTeam() ? ($c->title ?: ChatService::TEAM_TITLE) : ($other->name ?? 'Former member'),
            'other' => $other ? $this->person($other) + ['active' => true] : null,
            'members' => $others->count() + 1,
        ];
    }

    private function person(object $p): array
    {
        return ['id' => (int) $p->id, 'name' => $p->name, 'avatar' => $p->avatar ?? null, 'online' => (bool) $p->online, 'last_active_at' => $this->iso($p->last_active_at ?? null)];
    }

    /** @param  array<int, string>  $names */
    private function message(ChatMessage $m, User $me, array $names): array
    {
        $deleted = $m->deleted_at !== null;

        return [
            'id' => (int) $m->id, 'conversation_id' => (int) $m->conversation_id, 'user_id' => (int) $m->user_id,
            'sender' => $names[(int) $m->user_id] ?? 'Former member', 'mine' => (int) $m->user_id === (int) $me->id,
            'body' => $deleted ? null : $m->body, 'image_url' => $deleted ? null : ChatService::url($m->attachment_path),
            'deleted' => $deleted, 'created_at' => $this->iso($m->created_at),
        ];
    }

    /** @return array<int, string> user id => display name, for the senders */
    private function names(int $companyId): array
    {
        return User::withoutGlobalScopes()->where('company_id', $companyId)->get(['id', 'name', 'first_name', 'last_name', 'username'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => ChatService::displayName($u)])->all();
    }

    private function iso(mixed $at): ?string
    {
        return $at === null ? null : Carbon::parse($at)->toIso8601String();
    }
}
