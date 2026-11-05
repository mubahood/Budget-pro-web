<?php

namespace App\Console\Commands;

use App\Models\BudgetProgram;
use App\Models\BudgetProgramMember;
use App\Models\Company;
use App\Models\User;
use App\Services\Budget\BudgetAccess;
use App\Services\Budget\EventBook;
use App\Services\Team\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The first deploy of "a seat decides who opens an event" (the review's PB-09, decision D3). Until now every team member
 * could read every event, so that nobody loses sight of one or gains a right, each active team member without full
 * access gets a viewer seat on the open events where they have none. Run once: a dry run first, then --apply. A company
 * that has had this done keeps its rows marked and is skipped from then on, so someone who joins later waits for the chair.
 * Someone taken off a committee stays off.
 */
class SeatEventTeams extends Command
{
    private const MARK = 'events:seat-team';

    protected $signature = 'events:seat-team {--apply : Write the seats. Without it nothing is written.} {--company= : One account only}';

    protected $description = 'Give every team member without full access a viewer seat on the open events, once';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->line($apply ? 'Writing seats.' : 'Dry run: nothing is written. Add --apply to write.');
        $companies = Company::withoutGlobalScopes()->whereIn('id', DB::table('budget_programs')->where('is_deleted', 0)->select('company_id'))
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', (int) $id))->get();
        $total = 0;
        foreach ($companies as $company) {
            if (DB::table('budget_program_members')->where('company_id', $company->id)->where('device_id', self::MARK)->exists()) {
                $this->line("{$company->name}: Already done.");

                continue;
            }
            $seated = $this->seat($company, $apply);
            $total += $seated;
            if ($seated > 0) {
                $this->line("{$company->name}: {$seated} ".($seated === 1 ? 'seat' : 'seats'));
            }
        }
        $this->line($total.' '.($total === 1 ? 'seat' : 'seats').($apply ? ' written.' : ' would be written.'));

        return self::SUCCESS;
    }

    private function seat(Company $company, bool $apply): int
    {
        $events = BudgetProgram::withoutGlobalScopes()->without('company')->where('company_id', $company->id)->where('is_deleted', 0)->get(['id', 'status'])
            ->filter(fn (BudgetProgram $p) => EventBook::isOpen($p->status));
        $team = User::withoutGlobalScopes()->where('company_id', $company->id)->get()
            ->filter(fn (User $u) => Permissions::roleOf($u) !== 'none' && ! BudgetAccess::fullAccess($u));
        $n = 0;
        foreach ($events as $event) {
            $has = DB::table('budget_program_members')->where('budget_program_id', $event->id)->whereNotNull('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->flip();
            foreach ($team as $user) {
                if ($has->has((int) $user->id)) {
                    continue; // a seat, or a seat taken away
                }
                $n++;
                if ($apply) {
                    $seat = new BudgetProgramMember();
                    $seat->forceFill(['company_id' => $company->id, 'budget_program_id' => $event->id, 'user_id' => $user->id, 'role' => 'viewer', 'status' => 'active',
                        'added_by_id' => $company->owner_id, 'device_id' => self::MARK]);
                    $seat->save();
                }
            }
        }

        return $n;
    }
}
