<?php

namespace App\Services\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\UnityAnnouncement;
use App\Services\Billing\Quotas;
use App\Services\Messaging\Messenger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reaching members: the noticeboard (announcements, each also a notice in every member's app) and messages to a chosen
 * set of members, as notices or SMS. SMS counts against the plan's monthly allowance; when the server's SMS sending is
 * off (the log driver) nothing is sent and the messages count as skipped. A member who opted out of messages gets none.
 */
final class Voice
{
    public const AUDIENCES = ['all', 'chapter', 'category', 'region'];

    /** The current members an announcement is for. */
    public static function audience(int $cid, string $audience, ?int $id): \Illuminate\Database\Query\Builder
    {
        $q = MemberFilters::current($cid);

        return match ($audience) {
            'chapter' => $q->where('chapter_id', (int) $id),
            'category' => $q->where('category_id', (int) $id),
            'region' => $q->whereIn('chapter_id', DB::table('unity_chapters')->where('company_id', $cid)->where('parent_id', (int) $id)->where('is_deleted', 0)->select('id')),
            default => $q,
        };
    }

    public static function checkAudience(int $cid, string $audience, ?int $id): void
    {
        if ($audience === 'all') {
            return;
        }
        $ok = match ($audience) {
            'chapter' => DB::table('unity_chapters')->where('company_id', $cid)->where('id', (int) $id)->where('kind', 'chapter')->where('is_deleted', 0)->exists(),
            'region' => DB::table('unity_chapters')->where('company_id', $cid)->where('id', (int) $id)->where('kind', 'region')->where('is_deleted', 0)->exists(),
            'category' => DB::table('unity_categories')->where('company_id', $cid)->where('id', (int) $id)->where('is_deleted', 0)->exists(),
            default => false,
        };
        if (! $ok) {
            throw BusinessRuleException::make('validation', 'Choose who the announcement is for.', ['audience_id' => ['Unknown '.$audience.'.']]);
        }
    }

    /**
     * Publishes an announcement: a notice to each member in the audience (unless send_notice is false) and SMS when asked.
     *
     * @param  array<string, mixed>  $d
     */
    public static function announce(Company $company, array $d, int $userId): UnityAnnouncement
    {
        $cid = (int) $company->id;
        $audience = $d['audience'] ?? 'all';
        $audienceId = $audience === 'all' ? null : (int) ($d['audience_id'] ?? 0);
        self::checkAudience($cid, $audience, $audienceId);
        $a = UnityAnnouncement::create(['company_id' => $cid, 'title' => $d['title'], 'body' => $d['body'], 'audience' => $audience,
            'audience_id' => $audienceId, 'pinned' => (bool) ($d['pinned'] ?? false), 'published_at' => now(), 'created_by_id' => $userId]);
        $ids = self::audience($cid, $audience, $audienceId)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if ($d['send_notice'] ?? true) {
            $a->notices_sent = self::notices($cid, $ids, $a->title, $a->body);
        }
        if ($d['send_sms'] ?? false) {
            $a->sms_sent = self::sms($company, $ids, $a->title.': '.$a->body, $userId)['sent'];
        }
        $a->save();

        return $a->fresh();
    }

    /**
     * A message to chosen members (ids, or the members filters) as notices or SMS.
     *
     * @param  list<int>  $ids
     * @return array{recipients: int, sent: int, skipped_no_phone: int, quota_left: ?int, skipped_off?: int, skipped_opted_out?: int}
     */
    public static function message(Company $company, array $ids, string $body, string $channel, int $userId): array
    {
        $cid = (int) $company->id;
        if ($channel === 'notice') {
            $sent = self::notices($cid, $ids, 'A message from '.$company->name, $body);

            return ['recipients' => count($ids), 'sent' => $sent, 'skipped_no_phone' => 0, 'quota_left' => self::quotaLeft($company)];
        }

        return ['recipients' => count($ids)] + self::sms($company, $ids, $body, $userId);
    }

    /** @param list<int> $ids @return int notices written */
    public static function notices(int $cid, array $ids, string $title, string $body): int
    {
        $now = now();
        $rows = array_map(fn ($id) => ['company_id' => $cid, 'member_id' => $id, 'kind' => 'unity_notice', 'title' => Str::limit($title, 147),
            'body' => Str::limit($body, 497), 'created_at' => $now], $ids);
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('sacco_member_notices')->insert($chunk);
        }

        return count($rows);
    }

    public static function quotaLeft(Company $company): ?int
    {
        $q = new Quotas();
        $limit = $q->limit($company, 'sms');

        return $limit === null ? null : max(0, $limit - $q->used($company, 'sms'));
    }

    /** @param list<int> $ids @return array{sent: int, skipped_no_phone: int, quota_left: ?int, skipped_off: int, skipped_opted_out: int} */
    public static function sms(Company $company, array $ids, string $body, int $userId): array
    {
        $cid = (int) $company->id;
        $q = new Quotas();
        if ($q->limit($company, 'sms') === 0) {
            throw BusinessRuleException::make('feature_not_in_plan', 'SMS comes with the Group and Organisation plans. Notices work on every plan.');
        }
        $members = DB::table('sacco_members')->where('company_id', $cid)->whereIn('id', $ids ?: [0])->get(['id', 'phone_e164', 'messages_opt_out_at']);
        $withPhone = $members->filter(fn ($m) => filled($m->phone_e164));
        $reachable = $withPhone->filter(fn ($m) => $m->messages_opt_out_at === null);
        $out = ['sent' => 0, 'skipped_no_phone' => $members->count() - $withPhone->count(), 'quota_left' => self::quotaLeft($company),
            'skipped_off' => 0, 'skipped_opted_out' => $withPhone->count() - $reachable->count()];
        if ($reachable->isEmpty()) {
            return $out;
        }
        if ($out['quota_left'] !== null && $reachable->count() > $out['quota_left']) {
            throw BusinessRuleException::make('plan_limit_reached', "This sends {$reachable->count()} SMS but only {$out['quota_left']} are left this month. Choose fewer members or send a notice.",
                ['limit' => 'max_sms_per_month', 'used' => $q->used($company, 'sms'), 'quota_left' => $out['quota_left']]);
        }
        if (config('messaging.sms.driver', 'log') === 'log') {
            $out['skipped_off'] = $reachable->count(); // SMS sending is off on this server: nothing leaves, nothing is counted

            return $out;
        }
        $messenger = app(Messenger::class);
        foreach ($reachable as $m) {
            $messenger->send((string) $m->phone_e164, Str::limit($body, 459), ['sms'], ['company_id' => $cid, 'user_id' => $userId, 'purpose' => 'unity_message']);
            $out['sent']++;
        }
        $out['quota_left'] = self::quotaLeft($company);

        return $out;
    }

    /** @return array<string, mixed> */
    public static function shape(UnityAnnouncement $a, array $names = []): array
    {
        return ['id' => (int) $a->id, 'uuid' => $a->uuid, 'title' => $a->title, 'body' => $a->body, 'audience' => $a->audience,
            'audience_id' => $a->audience_id ? (int) $a->audience_id : null, 'audience_name' => $a->audience_id ? ($names[(int) $a->audience_id] ?? null) : null,
            'pinned' => (bool) $a->pinned, 'published_at' => Meetings::iso((int) $a->company_id, $a->getRawOriginal('published_at')),
            'notices_sent' => (int) $a->notices_sent, 'sms_sent' => (int) $a->sms_sent, 'version' => (int) $a->version];
    }
}
