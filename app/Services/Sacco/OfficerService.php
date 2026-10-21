<?php

namespace App\Services\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoOfficer;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The SACCO's offices (docs/akiba/04-architecture.md §6.2, AK-21; the CommitteeService pattern): appoint a person
 * to a position for a term, end a term, and answer who holds what today. An officer is a member of the SACCO, a
 * login of its team (user_id), or both; a group's key holder may be neither (a name only). There is no checker
 * for an officer change (`officer_change`, §7): every active officer is told and the change is audited.
 */
final class OfficerService
{
    public function __construct(private readonly Notifier $notifier = new Notifier())
    {
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'position' => ['required', Rule::in(SaccoOfficer::POSITIONS)],
            'title' => ['nullable', 'required_if:position,custom', 'string', 'max:60'],
            'user_id' => ['nullable', 'integer'],
            'member_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:150'],
            'approval_limit_minor' => \App\Support\Rules\SaccoRules::minor(false),
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    /**
     * Hooks other modules add to an appointment (members P1.9: the member exists, is 18 or over, and becomes an
     * insider). Each receives the company id and the validated data, and throws to refuse.
     *
     * @var array<string, callable(int, array<string, mixed>): void>
     */
    private static array $checks = [];

    /** @var array<string, callable(int, SaccoOfficer): void> run after an appointment or a term's end */
    private static array $after = [];

    /** @param  callable(int, array<string, mixed>): void  $check */
    public static function check(string $key, callable $check): void
    {
        self::$checks[$key] = $check;
    }

    /** @param  callable(int, SaccoOfficer): void  $after */
    public static function after(string $key, callable $after): void
    {
        self::$after[$key] = $after;
    }

    /** @param  array<string, mixed>  $data */
    public function appoint(int $companyId, array $data, ?int $byUserId = null): SaccoOfficer
    {
        $validator = validator($data, self::rules());
        if ($validator->fails()) {
            throw BusinessRuleException::make('invalid_officer', (string) $validator->errors()->first(), ['errors' => $validator->errors()->toArray()]);
        }
        $data = $validator->validated();
        if (empty($data['user_id']) && empty($data['member_id']) && blank($data['name'] ?? null)) {
            throw BusinessRuleException::make('invalid_officer', 'Choose the person: a team login, a member, or (for a key holder) a name.');
        }
        if (! empty($data['user_id']) && ! self::isTeamMember($companyId, (int) $data['user_id'])) {
            throw BusinessRuleException::make('invalid_officer', 'That login is not on this SACCO\'s team.');
        }
        foreach (self::$checks as $check) {
            $check($companyId, $data);
        }
        $today = LocalDate::today($companyId);
        $starts = Carbon::parse($data['starts_on'] ?? $today)->startOfDay();

        $officer = DB::transaction(function () use ($companyId, $data, $byUserId, $starts) {
            Company::withoutGlobalScopes()->whereKey($companyId)->lockForUpdate()->first();
            $same = SaccoOfficer::withoutGlobalScopes()->where('company_id', $companyId)->where('position', $data['position'])->activeOn($starts)
                ->where(fn ($q) => $q->when(! empty($data['user_id']), fn ($w) => $w->orWhere('user_id', $data['user_id']))
                    ->when(! empty($data['member_id']), fn ($w) => $w->orWhere('member_id', $data['member_id'])))
                ->when(empty($data['user_id']) && empty($data['member_id']), fn ($q) => $q->where('name', $data['name']))
                ->when($data['position'] === 'custom', fn ($q) => $q->where('title', $data['title'] ?? null))
                ->exists();
            if ($same) {
                throw BusinessRuleException::make('already_in_office', 'This person already holds that office.');
            }

            return SaccoOfficer::create(['company_id' => $companyId, 'position' => $data['position'], 'title' => $data['title'] ?? null,
                'user_id' => $data['user_id'] ?? null, 'member_id' => $data['member_id'] ?? null, 'name' => $data['name'] ?? null,
                'approval_limit_minor' => $data['approval_limit_minor'] ?? null, 'starts_on' => $starts->toDateString(),
                'ends_on' => $data['ends_on'] ?? null, 'created_by_id' => $byUserId]);
        });
        foreach (self::$after as $after) {
            $after($companyId, $officer);
        }
        SaccoAudit::record('sacco.officer.appointed', $officer, $companyId, ['position' => $officer->position, 'user_id' => $officer->user_id, 'member_id' => $officer->member_id], $byUserId);
        $this->tell($companyId, 'New officer', $this->who($officer).' is now '.$officer->label().' from '.$officer->starts_on->toFormattedDateString().'.');

        return $officer;
    }

    /** End a term (from today, or a given day); the row stays in the register. */
    public function endTerm(int $companyId, int $officerId, ?int $byUserId = null, ?string $reason = null, ?string $endsOn = null): SaccoOfficer
    {
        $officer = DB::transaction(function () use ($companyId, $officerId, $byUserId, $reason, $endsOn) {
            $officer = SaccoOfficer::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->find($officerId)
                ?? throw BusinessRuleException::make('officer_not_found', 'That officer is not in this SACCO\'s register.');
            $end = Carbon::parse($endsOn ?? LocalDate::today($companyId))->startOfDay();
            if ($officer->ends_on !== null && $officer->ends_on->lt(LocalDate::today($companyId))) {
                throw BusinessRuleException::make('term_ended', 'That term has already ended.');
            }
            if ($end->lt($officer->starts_on)) {
                throw BusinessRuleException::make('invalid_officer', 'A term cannot end before it starts.');
            }
            $officer->update(['ends_on' => $end->toDateString(), 'end_reason' => $reason === null ? null : mb_substr(trim($reason), 0, 255), 'ended_by_id' => $byUserId]);

            return $officer;
        });
        foreach (self::$after as $after) {
            $after($companyId, $officer);
        }
        SaccoAudit::record('sacco.officer.ended', $officer, $companyId, ['position' => $officer->position, 'reason' => $reason], $byUserId);
        $this->tell($companyId, 'Officer term ended', $this->who($officer).' is no longer '.$officer->label().'.');

        return $officer;
    }

    /** The positions a login holds today. @return list<string> */
    public static function positionsOf(int $companyId, int $userId): array
    {
        return SaccoOfficer::withoutGlobalScopes()->where('company_id', $companyId)->where('user_id', $userId)->activeOn(LocalDate::today($companyId))
            ->pluck('position')->unique()->values()->all();
    }

    /** @param  list<string>  $positions */
    public static function holds(int $companyId, int $userId, array $positions): bool
    {
        return array_intersect(self::positionsOf($companyId, $userId), $positions) !== [];
    }

    /** Every term running today, in the register's order. @return Collection<int, SaccoOfficer> */
    public static function active(int $companyId): Collection
    {
        return SaccoOfficer::withoutGlobalScopes()->where('company_id', $companyId)->activeOn(LocalDate::today($companyId))->orderBy('position')->orderBy('id')->get();
    }

    /** The loans committee today (majority votes, P2). @return Collection<int, SaccoOfficer> */
    public static function committee(int $companyId): Collection
    {
        return self::active($companyId)->where('position', 'loans_committee')->values();
    }

    /** Whether a member holds any office today (an insider, for loan routing and guarantor rules). */
    public static function memberHoldsOffice(int $companyId, int $memberId): bool
    {
        return SaccoOfficer::withoutGlobalScopes()->where('company_id', $companyId)->where('member_id', $memberId)->activeOn(LocalDate::today($companyId))->exists();
    }

    private static function isTeamMember(int $companyId, int $userId): bool
    {
        return (int) Company::withoutGlobalScopes()->whereKey($companyId)->value('owner_id') === $userId
            || DB::table('company_members')->where('company_id', $companyId)->where('user_id', $userId)->where('status', 'active')->exists();
    }

    private function who(SaccoOfficer $o): string
    {
        return (string) ($o->user_id ? User::withoutGlobalScopes()->whereKey($o->user_id)->value('name') : null)
            ?: ($o->member_id ? (string) DB::table('sacco_members')->where('id', $o->member_id)->selectRaw("TRIM(CONCAT(first_name, ' ', COALESCE(last_name, ''))) AS n")->value('n') : null)
            ?: (string) $o->name;
    }

    /** Every active officer with a login, and the owner, hears about an officer change. */
    private function tell(int $companyId, string $title, string $body): void
    {
        $ids = self::active($companyId)->pluck('user_id')->filter()->push(Company::withoutGlobalScopes()->whereKey($companyId)->value('owner_id'))->filter()->unique()->all();
        $users = User::withoutGlobalScopes()->whereIn('id', $ids)->get()->all();
        $this->notifier->notify($companyId, 'sacco_officers', $title, $body, [], $users);
    }
}
