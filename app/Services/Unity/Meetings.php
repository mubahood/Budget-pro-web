<?php

namespace App\Services\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\StoredFile;
use App\Models\UnityAttendance;
use App\Models\UnityMeeting;
use App\Models\User;
use App\Services\Billing\Quotas;
use App\Support\LocalDate;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Meetings and events: who is expected (a chapter's current members, or everyone for a whole-group meeting), who came
 * (marked from a list or checked in from the member card's QR), RSVPs with event tickets, minutes and resolutions.
 * starts_at is kept in UTC and shown in the group's own time.
 */
final class Meetings
{
    public const KINDS = ['meeting', 'event', 'agm'];

    public const RSVP = ['yes', 'no', 'maybe'];

    /** A stored UTC time as ISO 8601 in the group's timezone. */
    public static function iso(int $cid, mixed $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $at = $utc instanceof \DateTimeInterface ? Carbon::instance($utc) : Carbon::parse((string) $utc, 'UTC');

        return $at->copy()->setTimezone(LocalDate::timezone($cid))->toIso8601String();
    }

    /** A time from the client: with an offset it is that moment; without one it is the group's own time. Returned in UTC. */
    public static function parse(int $cid, string $value): Carbon
    {
        $value = trim($value);
        $hasZone = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value);

        return ($hasZone ? Carbon::parse($value) : Carbon::parse($value, LocalDate::timezone($cid)))->setTimezone('UTC');
    }

    /** The members expected at a meeting: the chapter's current members, or every current member. */
    public static function expected(UnityMeeting $mt): \Illuminate\Database\Query\Builder
    {
        return MemberFilters::current((int) $mt->company_id)->when($mt->chapter_id, fn ($q) => $q->where('chapter_id', $mt->chapter_id));
    }

    /**
     * Saves who came, who sent an apology and who was absent, and marks the meeting held. Only expected members are taken.
     *
     * @param  array{present?: list<int>, absent?: list<int>, apology?: list<int>}  $d
     * @return array{present: int, apology: int, absent: int}
     */
    public static function attendance(UnityMeeting $mt, array $d): array
    {
        if ($mt->status === 'cancelled') {
            throw BusinessRuleException::make('not_open', 'This meeting was cancelled.');
        }
        $present = array_map('intval', $d['present'] ?? []);
        $apology = array_values(array_diff(array_map('intval', $d['apology'] ?? []), $present));
        $absent = array_values(array_diff(array_map('intval', $d['absent'] ?? []), $present, $apology));
        $all = array_values(array_unique([...$present, ...$apology, ...$absent]));
        $valid = $all === [] ? [] : self::expected($mt)->whereIn('id', $all)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (count($valid) !== count($all)) {
            throw BusinessRuleException::make('validation', 'Some of those people are not expected at this meeting.',
                ['present' => ['Not expected: '.implode(', ', array_diff($all, $valid)).'.']]);
        }

        return DB::transaction(function () use ($mt, $present, $apology, $absent) {
            foreach ([[$present, true, false], [$apology, false, true], [$absent, false, false]] as [$ids, $isPresent, $isApology]) {
                foreach ($ids as $id) {
                    $a = self::row($mt, $id);
                    $a->present = $isPresent;
                    $a->apology = $isApology;
                    if ($isPresent) {
                        $a->method ??= 'list';
                        $a->checked_in_at ??= now();
                    }
                    $a->save();
                }
            }
            if ($mt->status !== 'held') {
                $mt->status = 'held';
                $mt->save();
            }

            return ['present' => count($present), 'apology' => count($apology), 'absent' => count($absent)];
        });
    }

    /** Checks a member in (the card's QR token, or their id). Safe to repeat: the first time stays. */
    public static function checkin(UnityMeeting $mt, SaccoMember $m, string $method): UnityAttendance
    {
        if ($mt->status === 'cancelled') {
            throw BusinessRuleException::make('not_open', 'This meeting was cancelled.');
        }
        if (! self::expected($mt)->where('id', $m->id)->exists()) {
            throw BusinessRuleException::make('not_eligible', $mt->chapter_id ? 'This member is not in this meeting\'s chapter, or is not a current member.' : 'This person is not a current member.');
        }

        return DB::transaction(function () use ($mt, $m, $method) {
            $a = self::row($mt, (int) $m->id);
            if (! $a->present) {
                $a->present = true;
                $a->apology = false;
                $a->checked_in_at = now();
                $a->method = $method;
                $a->save();
            }
            if ($mt->status === 'scheduled') {
                $mt->status = 'held';
                $mt->save();
            }

            return $a->fresh();
        });
    }

    /** An answer to the invitation. A yes to an event gets a ticket number (kept if they answer again). */
    public static function rsvp(UnityMeeting $mt, SaccoMember $m, string $answer): UnityAttendance
    {
        if ($mt->status !== 'scheduled') {
            throw BusinessRuleException::make('not_open', 'This meeting is not taking answers any more.');
        }
        if (! self::expected($mt)->where('id', $m->id)->exists()) {
            throw BusinessRuleException::make('not_eligible', 'This invitation is for another chapter\'s members.');
        }

        return DB::transaction(function () use ($mt, $m, $answer) {
            $locked = UnityMeeting::withoutGlobalScopes()->lockForUpdate()->find($mt->id);
            $a = self::row($mt, (int) $m->id);
            if ($answer === 'yes' && $a->rsvp !== 'yes' && $locked->capacity) {
                $yes = UnityAttendance::withoutGlobalScopes()->where('meeting_id', $mt->id)->where('is_deleted', 0)->where('rsvp', 'yes')->count();
                if ($yes >= (int) $locked->capacity) {
                    throw BusinessRuleException::make('not_open', 'This event is full.');
                }
            }
            $a->rsvp = $answer;
            if ($answer === 'yes' && $mt->kind === 'event' && blank($a->ticket_no)) {
                $n = UnityAttendance::withoutGlobalScopes()->where('meeting_id', $mt->id)->whereNotNull('ticket_no')->count() + 1;
                $a->ticket_no = 'E'.$mt->id.'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            }
            $a->save();

            return $a->fresh();
        });
    }

    public static function minutesPhoto(Company $company, UnityMeeting $mt, User $by, UploadedFile $file): UnityMeeting
    {
        $cid = (int) $company->id;
        (new Quotas())->assertCanStore($company, (int) $file->getSize());
        $uuid = (string) Str::uuid();
        $ext = strtolower($file->extension() ?: 'jpg') === 'png' ? 'png' : 'jpg';
        $path = $file->storeAs("files/{$cid}/".now()->format('Y/m'), $uuid.'.'.$ext, 'public');
        $stored = StoredFile::withoutGlobalScopes()->create(['company_id' => $cid, 'uuid' => $uuid, 'purpose' => 'document', 'path' => $path,
            'mime' => $file->getMimeType(), 'size_bytes' => (int) $file->getSize(), 'uploaded_by_id' => $by->id]);
        $mt->minutes_file_id = $stored->id;
        $mt->save();

        return $mt->fresh();
    }

    /** @return array{expected: int, present: int, apologies: int, rsvp: array{yes: int, no: int, maybe: int}} */
    public static function counts(UnityMeeting $mt): array
    {
        $r = DB::table('unity_attendance')->where('meeting_id', $mt->id)->where('is_deleted', 0)
            ->selectRaw("SUM(present) AS p, SUM(apology) AS a, SUM(rsvp = 'yes') AS y, SUM(rsvp = 'no') AS n, SUM(rsvp = 'maybe') AS m")->first();

        return ['expected' => (int) self::expected($mt)->count(), 'present' => (int) $r->p, 'apologies' => (int) $r->a,
            'rsvp' => ['yes' => (int) $r->y, 'no' => (int) $r->n, 'maybe' => (int) $r->m]];
    }

    /** @return array<string, mixed> */
    public static function shape(UnityMeeting $mt, array $chapters = []): array
    {
        $cid = (int) $mt->company_id;

        return ['id' => (int) $mt->id, 'uuid' => $mt->uuid, 'kind' => $mt->kind, 'title' => $mt->title,
            'chapter_id' => $mt->chapter_id ? (int) $mt->chapter_id : null, 'chapter_name' => $mt->chapter_id ? ($chapters[(int) $mt->chapter_id] ?? null) : null,
            'starts_at' => self::iso($cid, $mt->getRawOriginal('starts_at')), 'ends_at' => self::iso($cid, $mt->getRawOriginal('ends_at')),
            'venue' => $mt->venue, 'agenda' => $mt->agenda, 'minutes' => $mt->minutes,
            'minutes_photo_url' => UnityLinks::photo($cid, $mt->minutes_file_id ? (int) $mt->minutes_file_id : null),
            'status' => $mt->status, 'rsvp_open' => (bool) $mt->rsvp_open, 'capacity' => $mt->capacity ? (int) $mt->capacity : null, 'version' => (int) $mt->version];
    }

    private static function row(UnityMeeting $mt, int $memberId): UnityAttendance
    {
        return UnityAttendance::withoutGlobalScopes()->where('meeting_id', $mt->id)->where('member_id', $memberId)->first()
            ?? new UnityAttendance(['company_id' => $mt->company_id, 'meeting_id' => $mt->id, 'member_id' => $memberId]);
    }
}
