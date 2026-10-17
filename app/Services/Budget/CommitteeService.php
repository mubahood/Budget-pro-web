<?php

namespace App\Services\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetProgram;
use App\Models\BudgetProgramMember;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Services\Team\TeamService;
use Illuminate\Support\Facades\DB;

/**
 * An event's committee (roadmap 02 §6). A committee member is always a team member of the account first:
 * add a teammate directly, or invite someone by phone through the team invite (TeamService), who joins
 * the account as a viewer and lands on the committee when they accept (claimInvite).
 */
class CommitteeService
{
    /** Add a teammate, or invite by phone/email. Inviting a new person to the account needs manage_team. */
    public function add(User $by, BudgetProgram $program, array $data): BudgetProgramMember
    {
        $role = strtolower(trim((string) ($data['role'] ?? 'member')));
        if (! in_array($role, BudgetAccess::ROLES, true)) {
            throw BusinessRuleException::make('invalid_role', 'Choose chair, treasurer, secretary, member or viewer.');
        }
        $companyId = (int) $program->company_id;

        if (! empty($data['user_id'])) {
            return DB::transaction(function () use ($by, $program, $companyId, $role, $data) {
                $userId = (int) $data['user_id'];
                $this->lockProgram($program);
                $active = BudgetProgramMember::withoutGlobalScopes()->where('budget_program_id', $program->id)->where('user_id', $userId)->where('is_deleted', 0)->first();
                if ($active !== null) {
                    throw BusinessRuleException::make('already_on_committee', 'This person is already on the committee. Change their role instead.');
                }
                $member = new BudgetProgramMember();
                $member->forceFill(['uuid' => $data['uuid'] ?? null, 'company_id' => $companyId, 'budget_program_id' => $program->id, 'user_id' => $userId,
                    'role' => $role, 'status' => 'active', 'added_by_id' => $by->id]);
                $member->save();

                return $member;
            });
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        if ($phone === '' && $email === '') {
            throw BusinessRuleException::make('contact_required', 'Choose a team member, or enter a phone number to invite someone.');
        }
        if (! Permissions::can($by, 'manage_team')) {
            throw BusinessRuleException::make('forbidden', 'Only the owner or a manager can invite new people to the account. Ask them to invite this person, then add them here.', ['permission' => 'manage_team']);
        }
        $name = trim((string) ($data['name'] ?? '')) ?: null;

        // The person may already be on the team (matched by phone or email): add them straight away.
        $country = (string) (DB::table('companies')->where('id', $companyId)->value('country') ?: 'UG');
        $e164 = $phone !== '' ? \App\Support\Phone::e164($phone, $country) : null;
        $teammate = User::withoutGlobalScopes()->where('company_id', $companyId)
            ->where(fn ($q) => $q->when($e164, fn ($w) => $w->orWhere('phone_e164', $e164))->when($email !== '', fn ($w) => $w->orWhereRaw('LOWER(email) = ?', [strtolower($email)])))
            ->first();
        if ($teammate !== null && ($e164 !== null || $email !== '')) {
            return $this->add($by, $program, ['user_id' => $teammate->id, 'role' => $role, 'uuid' => $data['uuid'] ?? null]);
        }

        // Someone new: the team invite (viewer on the account; their committee role gives them this event).
        $invite = (new TeamService())->invite($by, 'viewer', $phone !== '' ? $phone : null, $email !== '' ? $email : null, $name)['invite'];
        $member = new BudgetProgramMember();
        $member->forceFill(['uuid' => $data['uuid'] ?? null, 'company_id' => $companyId, 'budget_program_id' => $program->id, 'invite_id' => $invite->id,
            'role' => $role, 'status' => 'invited', 'name' => $name, 'phone' => $invite->phone_e164 ?? null, 'added_by_id' => $by->id]);
        $member->save();

        return $member;
    }

    public function setRole(BudgetProgramMember $member, string $role): BudgetProgramMember
    {
        $role = strtolower(trim($role));
        if (! in_array($role, BudgetAccess::ROLES, true)) {
            throw BusinessRuleException::make('invalid_role', 'Choose chair, treasurer, secretary, member or viewer.');
        }
        if ($member->role !== $role) {
            $member->role = $role;
            $member->save();
        }

        return $member;
    }

    /** Off the committee (a tombstone, so phones remove it too). They stay on the team. */
    public function remove(BudgetProgramMember $member): void
    {
        $member->delete();
    }

    /**
     * An invite accepted (TeamService::accept / acceptExisting): the person's committee places wait for them.
     * A place on an event they are already on (added meanwhile) is closed instead.
     */
    public static function claimInvite(int $inviteId, int $userId): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('budget_program_members')) {
            return;
        }
        $waiting = BudgetProgramMember::withoutGlobalScopes()->where('invite_id', $inviteId)->whereNull('user_id')->where('is_deleted', 0)->get();
        foreach ($waiting as $member) {
            $already = BudgetProgramMember::withoutGlobalScopes()->where('budget_program_id', $member->budget_program_id)->where('user_id', $userId)->where('is_deleted', 0)->exists();
            if ($already) {
                $member->delete();

                continue;
            }
            $member->user_id = $userId;
            $member->status = 'active';
            $member->save();
        }
    }

    /** The committee with names, for the API answer. */
    public function list(BudgetProgram $program): array
    {
        $rows = BudgetProgramMember::withoutGlobalScopes()->where('budget_program_id', $program->id)->where('company_id', $program->company_id)
            ->where('is_deleted', 0)->orderByRaw("FIELD(role, 'chair', 'treasurer', 'secretary', 'member', 'viewer')")->orderBy('id')->get();
        $users = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('user_id')->filter()->all())->get(['id', 'name', 'phone_e164', 'email'])->keyBy('id');

        return $rows->map(fn (BudgetProgramMember $m) => self::present($m, $users[$m->user_id] ?? null))->all();
    }

    public static function present(BudgetProgramMember $m, ?User $user = null): array
    {
        $user ??= $m->user_id ? User::withoutGlobalScopes()->find($m->user_id, ['id', 'name', 'phone_e164', 'email']) : null;

        return [
            'id' => $m->id, 'uuid' => $m->uuid, 'budget_program_id' => (int) $m->budget_program_id, 'user_id' => $m->user_id ? (int) $m->user_id : null,
            'name' => $user->name ?? $m->name, 'phone' => $user->phone_e164 ?? $m->phone, 'role' => $m->role, 'status' => $m->status,
            'abilities' => array_keys(array_filter(BudgetAccess::ABILITIES, fn ($roles) => in_array($m->role, $roles, true))),
            'server_seq' => (int) $m->server_seq, 'version' => (int) $m->version,
        ];
    }

    private function lockProgram(BudgetProgram $program): void
    {
        DB::table('budget_programs')->where('id', $program->id)->lockForUpdate()->value('id');
    }
}
