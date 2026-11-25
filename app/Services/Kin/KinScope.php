<?php

namespace App\Services\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\KinBranch;
use App\Models\SaccoMember;
use App\Models\User;
use App\Services\Team\Permissions;

/**
 * Who may change which people in a family (family-roots docs 02 #11: a branch's coordinator looks after its records).
 * The head and the officers with kin_people change anyone; a branch coordinator changes only the people of the branches
 * they coordinate, and the links and marriages that touch one of them; everyone else only reads. The API and the phone's
 * sync both ask here, so neither door is wider than the other.
 */
final class KinScope
{
    public const COORDINATOR = 'kin_branch_coordinator';

    /** @return 'all'|'branch'|'none' */
    public static function level(?User $user): string
    {
        if ($user === null || ! Permissions::can($user, 'kin_people')) {
            return 'none';
        }

        return Permissions::roleOf($user) === self::COORDINATOR ? 'branch' : 'all';
    }

    /** The branches this person coordinates in this family (empty for anyone else). @return array<int, int> */
    public static function branchIds(User $user, int $companyId): array
    {
        return KinBranch::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)
            ->where('coordinator_user_id', $user->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** What the phone needs to hide what it may not do: ['people' => all|branch|none, 'branch_ids' => [...]]. @return array<string, mixed> */
    public static function describe(?User $user, int $companyId): array
    {
        $level = self::level($user);

        return ['people' => $level, 'branch_ids' => $level === 'branch' && $user ? self::branchIds($user, $companyId) : []];
    }

    /** May this user change these people (at least one of them must be theirs, for a link or a marriage)? */
    public static function allows(?User $user, int $companyId, int ...$personIds): bool
    {
        $level = self::level($user);
        if ($level !== 'branch') {
            return $level === 'all';
        }
        $mine = self::branchIds($user, $companyId);
        $ids = array_values(array_filter($personIds));
        if ($mine === [] || $ids === []) {
            return false;
        }

        return SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $ids)->whereIn('branch_id', $mine)->exists();
    }

    /** @throws BusinessRuleException forbidden */
    public static function guard(?User $user, int $companyId, int ...$personIds): void
    {
        if (! self::allows($user, $companyId, ...$personIds)) {
            throw BusinessRuleException::make('forbidden', 'Only the head, an officer or this branch\'s coordinator may change this.');
        }
    }

    /**
     * The branch a coordinator's new person (or changed person) is put in: one they coordinate. A coordinator of one branch
     * gets it filled in; naming another branch is refused. Anyone else's choice is kept as sent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function placeNew(?User $user, int $companyId, array $data, bool $creating): array
    {
        if (self::level($user) !== 'branch') {
            return $data;
        }
        $mine = self::branchIds($user, $companyId);
        if (array_key_exists('branch_id', $data) && $data['branch_id'] !== null) {
            if (! in_array((int) $data['branch_id'], $mine, true)) {
                throw BusinessRuleException::make('forbidden', 'A branch coordinator adds people to their own branch only.');
            }

            return $data;
        }
        if (! $creating && ! array_key_exists('branch_id', $data)) {
            return $data;
        }
        if (count($mine) !== 1) {
            throw BusinessRuleException::make('branch_required', 'Choose which of your branches this person belongs to.', ['branch_id' => $mine]);
        }

        return ['branch_id' => $mine[0]] + $data;
    }

    /**
     * The phone's sync door, for a branch coordinator only (everyone else is judged by the permission alone): a change to a
     * person of their branch, or a link or marriage touching one, goes through; a new person is refused here because a
     * synced person row carries no branch, so the API (POST kin/people) places them. Null when allowed, else the reason.
     *
     * @param  array<string, mixed>  $data
     */
    public static function refuseSyncOp(?User $user, int $companyId, string $table, string $action, array $data, string $uuid): ?string
    {
        if (! in_array($table, ['sacco_members', 'kin_links', 'kin_spouses'], true) || self::level($user) !== 'branch') {
            return null;
        }
        $id = fn (?string $u) => $u ? (int) SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $u)->value('id') : 0;
        $ids = match ($table) {
            'sacco_members' => [$id($uuid)],
            'kin_links' => [$id($data['child_uuid'] ?? null), $id($data['parent_uuid'] ?? null)],
            default => [$id($data['person_uuid'] ?? null), $id($data['spouse_uuid'] ?? null)],
        };
        if ($table !== 'sacco_members' && $action !== 'insert') {
            $model = $table === 'kin_links' ? \App\Models\KinLink::class : \App\Models\KinSpouse::class;
            $row = $model::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $uuid)->first();
            if ($row) {
                $ids = array_merge($ids, $table === 'kin_links' ? [(int) $row->child_id, (int) $row->parent_id] : [(int) $row->person_id, (int) $row->spouse_id]);
            }
        }
        if ($table === 'sacco_members' && $ids === [0]) {
            return 'A branch coordinator adds new people from the People screen, so they land in the branch.';
        }

        return self::allows($user, $companyId, ...$ids) ? null : 'Only the head, an officer or this branch\'s coordinator may change this.';
    }
}
