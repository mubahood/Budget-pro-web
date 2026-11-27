<?php

namespace App\Services\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\StoredFile;
use App\Models\UnityChapter;
use App\Models\UnityMemberEvent;
use App\Models\User;
use App\Services\Billing\Quotas;
use App\Support\LocalDate;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What happens to a member over the years: approved or rejected, moved to another chapter, suspended and reinstated, left,
 * died. Each step changes the record and writes one unity_member_events row (never edited), so the history reads like a file.
 */
final class MemberActions
{
    public const EVENT_KINDS = ['joined', 'approved', 'rejected', 'transferred', 'suspended', 'reinstated', 'exited', 'died', 'status'];

    public static function log(SaccoMember $m, string $kind, ?string $from = null, ?string $to = null, ?string $note = null, ?Carbon $on = null, ?int $userId = null): UnityMemberEvent
    {
        return UnityMemberEvent::create([
            'company_id' => $m->company_id, 'member_id' => $m->id, 'kind' => $kind,
            'from_value' => $from === null ? null : Str::limit($from, 117), 'to_value' => $to === null ? null : Str::limit($to, 117),
            'note' => $note === null ? null : Str::limit($note, 497), 'on' => ($on ?? LocalDate::today((int) $m->company_id))->toDateString(), 'by_user_id' => $userId,
        ]);
    }

    /** An applicant becomes an active member; member_since is today when it was empty. */
    public static function approve(SaccoMember $m, User $by): SaccoMember
    {
        self::expect($m, ['applicant'], 'Only an applicant is approved.');
        $today = LocalDate::today((int) $m->company_id);

        return DB::transaction(function () use ($m, $by, $today) {
            $m->status = 'active';
            $m->member_since ??= $today->toDateString();
            $m->setAttribute('status_changed_on', $today->toDateString());
            $m->save();
            self::log($m, 'approved', 'applicant', 'active', null, $today, (int) $by->id);

            return $m->fresh();
        });
    }

    public static function reject(SaccoMember $m, User $by, string $reason): SaccoMember
    {
        self::expect($m, ['applicant'], 'Only an applicant is turned down.');

        return self::setStatus($m, $by, 'rejected', 'rejected', $reason);
    }

    public static function suspend(SaccoMember $m, User $by, string $reason): SaccoMember
    {
        self::expect($m, UnityMembers::CURRENT, 'Only a current member is suspended.');

        return self::setStatus($m, $by, 'suspended', 'suspended', $reason);
    }

    public static function reinstate(SaccoMember $m, User $by): SaccoMember
    {
        self::expect($m, ['suspended', 'exited'], 'Only a suspended or departed member is reinstated.');
        if ($m->status === 'exited') { // back on the books: counted against the plan again
            app(Quotas::class)->assertCanAdd(Company::withoutGlobalScopes()->findOrFail($m->company_id), 'unity_members');
        }

        return self::setStatus($m, $by, 'active', 'reinstated', null);
    }

    public static function exit(SaccoMember $m, User $by, string $reason, ?string $on = null): SaccoMember
    {
        self::expect($m, ['applicant', 'active', 'dormant', 'suspended'], 'This person has already left.');

        return self::setStatus($m, $by, 'exited', 'exited', $reason, self::day($m, $on));
    }

    public static function died(SaccoMember $m, User $by, string $on, ?string $place = null): SaccoMember
    {
        self::expect($m, ['applicant', 'active', 'dormant', 'suspended', 'exited'], 'This member is already recorded as deceased.');
        $day = self::day($m, $on);

        return DB::transaction(function () use ($m, $by, $day, $place) {
            $from = (string) $m->status;
            $m->status = 'deceased';
            $m->date_of_death = $day->toDateString();
            if ($place !== null && $place !== '') {
                $m->place_of_death = Str::limit($place, 117, '');
            }
            $m->setAttribute('status_changed_on', $day->toDateString());
            $m->save();
            self::log($m, 'died', $from, 'deceased', $place, $day, (int) $by->id);

            return $m->fresh();
        });
    }

    public static function transfer(SaccoMember $m, User $by, int $chapterId, ?string $note = null): SaccoMember
    {
        $cid = (int) $m->company_id;
        $to = UnityChapter::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->find($chapterId);
        if ($to === null || $to->kind === 'region') {
            throw BusinessRuleException::make('validation', 'Choose one of the group\'s chapters.', ['chapter_id' => ['Choose a chapter.']]);
        }
        if ((int) $m->chapter_id === $chapterId) {
            throw BusinessRuleException::make('validation', 'The member is already in that chapter.', ['chapter_id' => ['Already in that chapter.']]);
        }
        $from = $m->chapter_id ? UnityChapter::withoutGlobalScopes()->whereKey($m->chapter_id)->value('name') : null;

        return DB::transaction(function () use ($m, $by, $to, $from, $note) {
            $m->chapter_id = $to->id;
            $m->save();
            self::log($m, 'transferred', $from, $to->name, $note, null, (int) $by->id);

            return $m->fresh();
        });
    }

    /** A member's photo (jpeg or png, up to 5 MB) kept as a stored file on the public disk; the old file stays for history. */
    public static function photo(Company $company, SaccoMember $m, User $by, UploadedFile $file): SaccoMember
    {
        $cid = (int) $company->id;
        (new Quotas())->assertCanStore($company, (int) $file->getSize());
        $uuid = (string) Str::uuid();
        $ext = strtolower($file->extension() ?: 'jpg') === 'png' ? 'png' : 'jpg';
        $path = $file->storeAs("files/{$cid}/".now()->format('Y/m'), $uuid.'.'.$ext, 'public');
        $stored = StoredFile::withoutGlobalScopes()->create(['company_id' => $cid, 'uuid' => $uuid, 'purpose' => 'avatar', 'path' => $path,
            'mime' => $file->getMimeType(), 'size_bytes' => (int) $file->getSize(), 'uploaded_by_id' => $by->id]);
        $m->photo_file_id = $stored->id;
        $m->save();

        return $m->fresh();
    }

    /** @return list<array<string, mixed>> newest first */
    public static function history(SaccoMember $m): array
    {
        $rows = UnityMemberEvent::withoutGlobalScopes()->where('company_id', $m->company_id)->where('member_id', $m->id)->where('is_deleted', 0)
            ->orderByDesc('on')->orderByDesc('id')->get();
        $names = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('by_user_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn (UnityMemberEvent $e) => ['id' => (int) $e->id, 'kind' => $e->kind, 'from' => $e->from_value, 'to' => $e->to_value,
            'note' => $e->note, 'on' => $e->on?->toDateString(), 'by' => $e->by_user_id ? ($names[$e->by_user_id] ?? null) : null])->values()->all();
    }

    private static function setStatus(SaccoMember $m, User $by, string $status, string $kind, ?string $note, ?Carbon $on = null): SaccoMember
    {
        $on ??= LocalDate::today((int) $m->company_id);

        return DB::transaction(function () use ($m, $by, $status, $kind, $note, $on) {
            $from = (string) $m->status;
            $m->status = $status;
            $m->setAttribute('status_changed_on', $on->toDateString());
            $m->save();
            self::log($m, $kind, $from, $status, $note, $on, (int) $by->id);

            return $m->fresh();
        });
    }

    /** @param list<string> $statuses */
    private static function expect(SaccoMember $m, array $statuses, string $message): void
    {
        if (! in_array($m->status, $statuses, true)) {
            throw BusinessRuleException::make('validation', $message, ['status' => ["The member is {$m->status}."]]);
        }
    }

    private static function day(SaccoMember $m, ?string $on): Carbon
    {
        $today = LocalDate::today((int) $m->company_id);
        $day = $on === null || $on === '' ? $today : Carbon::parse($on)->startOfDay();
        if ($day->gt($today)) {
            throw BusinessRuleException::make('validation', 'That day has not come yet.', ['on' => ['Not in the future.']]);
        }

        return $day;
    }
}
