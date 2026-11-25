<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Models\SaccoMember;
use App\Models\UnitySubscription;
use App\Services\Unity\Memberships;
use App\Services\Unity\UnityMembers;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The association's members: list with filters, one with their subscriptions, add and change. Never deleted; a member sees only themselves (me). */
class MembersController extends UnityController
{
    /** GET unity/members?standing=good|lapsed|life|none&category_id&chapter_id&status&expiring_days&q&per_page&page */
    public function index(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        Memberships::refreshStanding($cid); // the phones' copies follow the day (one update, only when a period has ended)
        $q = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereNull('merged_into_id');
        if (in_array($standing = (string) $request->query('standing'), [Memberships::GOOD, Memberships::LAPSED, Memberships::LIFE, Memberships::NONE], true)) {
            Memberships::whereStanding($q, $standing, LocalDate::today($cid));
        }
        if (in_array($status = $request->query('status'), config('unity.statuses'), true)) {
            $q->where('status', $status);
        }
        foreach (['category_id', 'chapter_id'] as $key) {
            if ($request->filled($key)) {
                $q->where($key, (int) $request->query($key));
            }
        }
        if ($request->filled('expiring_days')) {
            $today = LocalDate::today($cid);
            $q->whereIn('standing', [Memberships::GOOD, Memberships::LAPSED])
                ->whereBetween('membership_ends_on', [$today->toDateString(), $today->copy()->addDays(max(0, min(366, (int) $request->query('expiring_days'))))->toDateString()]);
        }
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $q->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('other_names', 'like', $like)
                ->orWhere('member_no', 'like', $like)->orWhere('phone_e164', 'like', $like)->orWhere('email', 'like', $like));
        }
        $p = $q->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate($this->perPage($request), ['*'], 'page', $this->pageNo($request));

        return $this->page($p, collect($p->items())->map(fn ($m) => UnityMembers::present($m))->all());
    }

    /** GET unity/members/{id}: the member and their subscriptions, newest first. */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $m = UnityMembers::find($this->cid($request), $id);

            return $this->success(self::withSubscriptions($m));
        });
    }

    /** GET unity/me: the signed-in person's own member record (matched by email or phone), or null. Open to everyone on the team. */
    public function me(Request $request): JsonResponse
    {
        $m = UnityMembers::ofUser($this->cid($request), $request->user());

        return $this->success($m === null ? null : self::withSubscriptions($m), $m === null ? 'No member record has your email or phone yet.' : 'Your membership.');
    }

    /** POST unity/members {first_name, last_name?, other_names?, gender?, date_of_birth?, phone?, email?, address?, district?, village?, occupation?, status?, member_since?, category_id?, chapter_id?} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $m = app(UnityMembers::class)->create($this->company($request), $request->user(), $request->validate(UnityMembers::rules($this->cid($request), true)));

            return $this->created(UnityMembers::present($m), 'Member added.');
        });
    }

    /** PUT|PATCH unity/members/{id}: only the fields sent change. */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $m = UnityMembers::find($this->cid($request), $id);
            $m = app(UnityMembers::class)->update($this->company($request), $m, $request->validate(UnityMembers::rules($this->cid($request), false)));

            return $this->success(UnityMembers::present($m), 'Saved.');
        });
    }

    /** @return array<string, mixed> */
    private static function withSubscriptions(SaccoMember $m): array
    {
        $subs = UnitySubscription::withoutGlobalScopes()->where('company_id', $m->company_id)->where('member_id', $m->id)->where('is_deleted', 0)
            ->orderByDesc('starts_on')->orderByDesc('id')->get();

        return UnityMembers::present($m) + ['subscriptions' => $subs->map(fn ($s) => SubscriptionsController::shape($s))->values()->all()];
    }
}
