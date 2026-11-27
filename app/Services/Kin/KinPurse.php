<?php

namespace App\Services\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\User;
use App\Services\Sacco\SaccoSetupService;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Team\Permissions;
use Illuminate\Support\Facades\DB;

/**
 * A family's purse: the SACCO books beside the tree (['kin', 'sacco'], while kin.purse_enabled). Switching it on adds the
 * sacco module, sets the books up in group mode with the family's head as chairperson (SaccoSetupService), and makes the
 * people the head chooses members of the purse (in_purse, with a group savings account). The account still opens only in
 * Family Roots (AppFamily::opens, doc 11 T1); the purse's own screens use the /sacco API.
 */
class KinPurse
{
    public static function enabled(Company $company): bool
    {
        return TreeService::hasPurse($company) && SaccoSetupService::isSetUp((int) $company->id);
    }

    public static function isHead(User $user, Company $company): bool
    {
        return (int) $company->owner_id === (int) $user->id || Permissions::roleOf($user) === 'owner';
    }

    /** @return array{enabled: bool, can_enable: bool, members: int, savings_minor: int, loans_out_minor: int} */
    public static function status(Company $company, ?User $user = null): array
    {
        $cid = (int) $company->id;
        $on = self::enabled($company);

        return [
            'enabled' => $on,
            'can_enable' => ! $on && (bool) config('kin.purse_enabled', false) && $user !== null && self::isHead($user, $company),
            'members' => (int) DB::table('sacco_members')->where('company_id', $cid)->where('is_deleted', 0)->where('in_purse', 1)->count(),
            'savings_minor' => $on ? (int) DB::table('sacco_savings_accounts')->where('company_id', $cid)->where('is_deleted', 0)->where('status', '!=', 'closed')->sum('balance_minor') : 0,
            'loans_out_minor' => $on ? (int) DB::table('sacco_loans')->where('company_id', $cid)->where('is_deleted', 0)->whereNotNull('disbursement_entry_id')
                ->whereNotIn('status', ['closed', 'written_off'])->sum('principal_outstanding_minor') : 0,
        ];
    }

    /**
     * Switch the purse on (again is fine: it only adds the people named).
     *
     * @param list<int> $memberIds people to make members of the purse
     */
    public function enable(Company $company, User $by, array $memberIds = []): Company
    {
        if (! (bool) config('kin.purse_enabled', false)) {
            throw BusinessRuleException::make('purse_unavailable', 'The family purse is not available yet.');
        }
        if (! self::isHead($by, $company)) {
            throw BusinessRuleException::make('forbidden', 'Only the head of the family switches the purse on.');
        }
        $cid = (int) $company->id;
        $people = $memberIds === [] ? collect() : SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereNull('merged_into_id')
            ->whereIn('id', $memberIds)->get();
        if ($people->count() !== count(array_unique($memberIds))) {
            throw BusinessRuleException::make('not_found', 'One of those people is not in this family.');
        }
        foreach ($people as $p) {
            if (! in_array($p->status, SavingsAccounts::MEMBER_STATUSES, true)) {
                throw BusinessRuleException::make('not_living', "{$p->full_name} cannot join the purse (they are {$p->status}).", ['member_ids' => ["{$p->full_name} cannot join."]]);
            }
        }

        if (! in_array('sacco', $company->modules(), true)) {
            $company->enabled_modules = array_values(array_unique([...$company->modules(), 'sacco']));
            $company->save();
            $company = $company->fresh();
            Permissions::flush();
        }
        if (! SaccoSetupService::isSetUp($cid)) {
            app(SaccoSetupService::class)->setup($company, ['mode' => 'group', 'legal_name' => mb_substr((string) $company->name, 0, 190)], $by);
        }
        $this->join($company, $people->all(), $by);

        return $company->fresh();
    }

    /** Make people members of the purse, each with a group savings account (the group product, else the first one). @param list<SaccoMember> $people */
    public function join(Company $company, array $people, User $by): void
    {
        $cid = (int) $company->id;
        $product = SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $cid)->orderByRaw("code = 'GRP' DESC")->orderBy('id')->value('id');
        foreach ($people as $p) {
            if (! $p->in_purse) {
                $p->in_purse = true;
                $p->save();
            }
            if ($product && ! SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $cid)->where('member_id', $p->id)->where('product_id', $product)->where('status', '!=', 'closed')->exists()) {
                app(SavingsAccounts::class)->open($company, (int) $p->id, (int) $product, $by);
            }
        }
    }
}
