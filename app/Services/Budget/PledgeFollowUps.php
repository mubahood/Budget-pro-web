<?php

namespace App\Services\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetProgram;
use App\Models\ContributionRecord;
use App\Models\PledgeFollowUp;
use App\Models\User;
use App\Support\LocalDate;
use Illuminate\Support\Facades\DB;

/**
 * Chasing with memory (the review's PB-19), the kernel side: who was reminded, when, and what they said, shared by
 * the whole committee. The screens and the sync line come with the pledge book's work (Pkg C, PB-32).
 */
class PledgeFollowUps
{
    /**
     * @param  array{kind?: string, channel?: ?string, outcome?: ?string, promised_on?: ?string, note?: ?string, uuid?: ?string, device_id?: ?string, client_created_at?: ?int}  $data
     */
    public function record(User $by, ContributionRecord $pledge, array $data): PledgeFollowUp
    {
        $kind = (string) ($data['kind'] ?? 'reminder');
        $channel = $data['channel'] ?? null;
        $outcome = $data['outcome'] ?? null;
        if (! in_array($kind, PledgeFollowUp::KINDS, true)
            || ($channel !== null && ! in_array($channel, PledgeFollowUp::CHANNELS, true))
            || ($outcome !== null && ! in_array($outcome, PledgeFollowUp::OUTCOMES, true))) {
            throw BusinessRuleException::make('invalid_follow_up', 'Choose a kind, a channel and an outcome from the list.');
        }
        $row = new PledgeFollowUp();
        $row->forceFill(array_filter([
            'uuid' => $data['uuid'] ?? null, 'company_id' => $pledge->company_id, 'budget_program_id' => $pledge->budget_program_id, 'contribution_record_id' => $pledge->id,
            'kind' => $kind, 'channel' => $channel, 'outcome' => $outcome, 'promised_on' => $data['promised_on'] ?? null,
            'note' => isset($data['note']) ? mb_substr(trim((string) $data['note']), 0, 255) : null, 'created_by_id' => $by->id,
            'device_id' => $data['device_id'] ?? null, 'client_created_at' => $data['client_created_at'] ?? null,
        ], fn ($v) => $v !== null));
        $row->save();

        return $row;
    }

    /**
     * Each pledge's latest follow-up in this event, with who did it, for the row and the preview.
     *
     * @return array<int, object> pledge id => row with `by`
     */
    public function latest(BudgetProgram $program): array
    {
        $last = DB::table('pledge_follow_ups')->where('budget_program_id', $program->id)->where('is_deleted', 0)->groupBy('contribution_record_id')->selectRaw('MAX(id) AS id');

        return DB::table('pledge_follow_ups as f')->joinSub($last, 'l', 'l.id', '=', 'f.id')->leftJoin('admin_users as u', 'u.id', '=', 'f.created_by_id')
            ->get(['f.*', 'u.name as by'])->keyBy('contribution_record_id')->all();
    }

    /**
     * Who to remind next, for one person walking the list: everyone who still owes, never reminded first (biggest balance
     * first), then longest ago. Anyone they opened today has dropped off, so a reload after WhatsApp resumes at the right
     * person.
     *
     * @return list<object> rows with id, name, phone, not_paid_amount, last_at
     */
    public function walker(User $by, BudgetProgram $program): array
    {
        $startOfToday = now(LocalDate::timezone((int) $program->company_id))->startOfDay()->setTimezone(config('app.timezone'));
        $last = DB::table('pledge_follow_ups')->where('budget_program_id', $program->id)->where('is_deleted', 0)->whereIn('kind', ['reminder', 'call'])
            ->groupBy('contribution_record_id')->selectRaw('contribution_record_id, MAX(created_at) AS last_at');
        $mineToday = DB::table('pledge_follow_ups')->where('budget_program_id', $program->id)->where('created_by_id', $by->id)->where('created_at', '>=', $startOfToday)->select('contribution_record_id');

        return app(EventBook::class)->pledges((int) $program->company_id, (int) $program->id, ['status' => 'owing'])->reorder()
            ->leftJoinSub($last, 'l', 'l.contribution_record_id', '=', 'r.id')
            ->whereNotIn('r.id', $mineToday)
            ->orderByRaw('l.last_at IS NOT NULL')->orderBy('l.last_at')->orderByDesc('r.not_paid_amount')->orderBy('r.name')
            ->get(['r.id', 'r.name', 'r.phone', 'r.not_paid_amount', 'l.last_at'])->all();
    }

    /** How many said they would pay by a day now passed and still owe (Worth a look). */
    public function promisedAndUnpaid(int $companyId, int $programId, string $today): int
    {
        $last = DB::table('pledge_follow_ups')->where('budget_program_id', $programId)->where('is_deleted', 0)->whereNotNull('promised_on')->groupBy('contribution_record_id')->selectRaw('MAX(id) AS id');

        return DB::table('pledge_follow_ups as f')->joinSub($last, 'l', 'l.id', '=', 'f.id')->join('contribution_records as r', 'r.id', '=', 'f.contribution_record_id')
            ->where('r.company_id', $companyId)->where('r.is_deleted', 0)->where('r.not_paid_amount', '>', 0)->where('f.promised_on', '<', $today)->count();
    }
}
