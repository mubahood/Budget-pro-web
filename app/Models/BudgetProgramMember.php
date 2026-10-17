<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Services\Budget\BudgetAccess;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A person on an event's committee and their role there (roadmap 02 §6; App\Services\Budget\BudgetAccess
 * says what each role may do). Written by CommitteeService (API) or by a phone through sync; either way
 * the checks below hold: a known role, a team member of the same account, once per event.
 */
class BudgetProgramMember extends Model
{
    use Syncable;

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::saving(function (self $m) {
            $m->role = strtolower(trim((string) $m->role)) ?: 'member';
            if (! in_array($m->role, BudgetAccess::ROLES, true)) {
                throw BusinessRuleException::make('invalid_role', 'Choose chair, treasurer, secretary, member or viewer.');
            }
            if (empty($m->company_id) || empty($m->budget_program_id)) {
                throw BusinessRuleException::make('validation', 'The event is required.');
            }
            $program = DB::table('budget_programs')->where('id', $m->budget_program_id)->where('company_id', $m->company_id)->where('is_deleted', 0)->exists();
            if (! $program) {
                throw BusinessRuleException::make('validation', 'This event was not found.');
            }
            if ($m->user_id === null && $m->invite_id === null) {
                throw BusinessRuleException::make('validation', 'Choose a team member.');
            }
            if ($m->user_id !== null) {
                $m->status = 'active';
                $teammate = DB::table('admin_users')->where('id', $m->user_id)->where('company_id', $m->company_id)->exists();
                if (! $teammate) {
                    throw BusinessRuleException::make('not_team_member', 'This person is not on your team. Invite them first.');
                }
                if (! $m->is_deleted && ($m->isDirty('user_id') || $m->isDirty('is_deleted') || ! $m->exists)) {
                    $twin = self::withoutGlobalScopes()->where('budget_program_id', $m->budget_program_id)->where('user_id', $m->user_id)
                        ->where('is_deleted', 0)->when($m->exists, fn ($q) => $q->where('id', '!=', $m->id))->first();
                    if ($twin !== null && (int) $twin->added_by_id === (int) $twin->user_id && ! $m->exists) {
                        // The self-seat rule: a seat a person holds by their own hand (above all the chair seat an event's
                        // creator gets, CommitteeService::seatCreator) gives way to a new committee row for them (a phone
                        // that created the event offline and pushes its own seat too), so that push is never refused.
                        // CommitteeService::add still refuses a second seat with "Change their role instead".
                        $twin->delete();
                    } elseif ($twin !== null) {
                        throw BusinessRuleException::make('already_on_committee', 'This person is already on the committee. Change their role instead.');
                    }
                }
            }
        });
    }

    public function budgetProgram()
    {
        return $this->belongsTo(BudgetProgram::class, 'budget_program_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
