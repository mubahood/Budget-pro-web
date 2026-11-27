<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Models\SaccoMember;
use App\Models\UnityDrive;
use App\Models\UnityPledge;
use App\Services\Unity\Drives;
use App\Services\Unity\Dues;
use App\Services\Unity\Memberships;
use App\Services\Unity\UnityMembers;
use App\Services\Unity\Welfare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dues & Contributions: renewals for one to five periods, the year's collection, arrears, and levy and harambee drives.
 * Reads unity_view (money fields null without unity_finance); writes unity_finance (ApiPermissionMap).
 */
class DuesController extends UnityController
{
    /** POST unity/subscriptions/renew {member_id, periods?, paid_minor?, method?, paid_on?, reference?} */
    public function renew(Request $request): JsonResponse
    {
        $d = $request->validate(['member_id' => ['required', 'integer'], 'periods' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'paid_minor' => ['sometimes', 'nullable', 'integer', 'min:0'], 'method' => ['sometimes', 'nullable', 'string', 'max:30'],
            'paid_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'reference' => ['sometimes', 'nullable', 'string', 'max:60']]);

        return $this->attempt(function () use ($request, $d) {
            $m = UnityMembers::find($this->cid($request), (int) $d['member_id']);
            $subs = Dues::renew($m, $d, (int) $request->user()->id);

            return $this->created(['member' => UnityMembers::present($m->fresh()),
                'subscriptions' => array_map(fn ($s) => SubscriptionsController::shape($s->fresh()), $subs)], count($subs) === 1 ? 'Renewed.' : 'Renewed for '.count($subs).' periods.');
        });
    }

    /** GET unity/dues/summary */
    public function summary(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        Memberships::refreshStanding($cid);
        $s = Dues::summary($cid);
        if (! $this->can($request, 'unity_finance')) {
            foreach (['collected_ytd_minor', 'expected_ytd_minor', 'collection_pct', 'arrears_minor', 'by_month'] as $k) {
                $s[$k] = null;
            }
        }

        return $this->success($s, 'Dues.');
    }

    /** GET unity/dues/arrears?page&per_page: [{member, owed_minor, since, standing}], the most owed first. */
    public function arrears(Request $request): JsonResponse
    {
        $cid = $this->cid($request);
        $rows = Dues::arrears($cid);
        $per = $this->perPage($request);
        $page = $this->pageNo($request);
        $slice = array_slice($rows, ($page - 1) * $per, $per);
        $members = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', array_column($slice, 'member_id') ?: [0])->get();
        $shaped = collect(UnityMembers::presentMany($members))->keyBy('id');
        $money = $this->can($request, 'unity_finance');
        $p = new \Illuminate\Pagination\LengthAwarePaginator($slice, count($rows), $per, $page);

        return $this->page($p, array_map(fn ($r) => ['member' => $shaped[$r['member_id']] ?? null, 'owed_minor' => $money ? $r['owed_minor'] : null,
            'since' => $r['since'], 'standing' => $r['standing']], $slice));
    }

    /** GET unity/drives?status=open|closed */
    public function drives(Request $request): JsonResponse
    {
        $money = $this->can($request, 'unity_finance');
        $q = UnityDrive::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)
            ->when(in_array($request->query('status'), ['open', 'closed'], true), fn ($w) => $w->where('status', $request->query('status')))
            ->orderByRaw("status = 'closed'")->orderByDesc('id');

        return $this->pageOf($q, $request, fn ($rows) => $rows->map(fn ($d) => Drives::shape($d, $money) + ['totals' => $this->totals($d, $money)])->values()->all());
    }

    /** GET unity/drives/{id}: the drive, its totals and a page of pledges. */
    public function drive(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $cid = $this->cid($request);
            $d = $this->findDrive($request, $id);
            $money = $this->can($request, 'unity_finance');
            $p = UnityPledge::withoutGlobalScopes()->where('company_id', $cid)->where('drive_id', $d->id)->where('is_deleted', 0)
                ->orderByRaw("status = 'paid'")->orderByDesc('pledged_minor')->orderBy('id')->paginate($this->perPage($request), ['*'], 'page', $this->pageNo($request));
            $names = Welfare::names($cid, collect($p->items())->pluck('member_id'));
            $today = \App\Support\LocalDate::today($cid)->toDateString();
            $pledges = collect($p->items())->map(fn ($x) => ['id' => (int) $x->id, 'member_id' => (int) $x->member_id, 'member_name' => $names[(int) $x->member_id] ?? null,
                'pledged_minor' => $money ? (int) $x->pledged_minor : null, 'paid_minor' => $money ? (int) $x->paid_minor : null,
                'due_on' => $x->due_on?->toDateString(), 'status' => $x->status,
                'overdue' => $x->status === 'open' && (int) $x->paid_minor < (int) $x->pledged_minor && ($x->due_on?->toDateString() ?? '9999-12-31') < $today])->values()->all();

            return $this->success(Drives::shape($d, $money) + ['totals' => $this->totals($d, $money), 'pledges' => $pledges], 'Drive.', 200,
                ['pagination' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage(), 'has_more' => $p->hasMorePages()]]);
        });
    }

    /** POST unity/drives {kind, title, description?, target_minor?, per_member_minor?, due_on?, chapter_id?} */
    public function storeDrive(Request $request): JsonResponse
    {
        $d = $request->validate(self::driveRules(true));

        return $this->attempt(function () use ($request, $d) {
            $this->checkChapter($request, $d['chapter_id'] ?? null);
            if ($d['kind'] === 'levy' && empty($d['per_member_minor'])) {
                throw \App\Exceptions\BusinessRuleException::make('validation', 'Set what each member pays.', ['per_member_minor' => ['Required for a levy.']]);
            }
            $drive = UnityDrive::create($d + ['company_id' => $this->cid($request), 'status' => 'open', 'created_by_id' => $request->user()->id]);

            return $this->created(Drives::shape($drive->fresh()) + ['totals' => Drives::totals($drive)], 'Drive started.');
        });
    }

    /** PATCH unity/drives/{id} */
    public function updateDrive(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(self::driveRules(false));

        return $this->attempt(function () use ($request, $id, $d) {
            $drive = $this->findDrive($request, $id);
            if (array_key_exists('chapter_id', $d)) {
                $this->checkChapter($request, $d['chapter_id']);
            }
            unset($d['kind']); // a levy stays a levy
            $drive->fill($d)->save();

            return $this->success(Drives::shape($drive->fresh()) + ['totals' => Drives::totals($drive)], 'Saved.');
        });
    }

    /** POST unity/drives/{id}/close */
    public function closeDrive(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $drive = $this->findDrive($request, $id);
            $drive->status = 'closed';
            $drive->save();

            return $this->success(Drives::shape($drive->fresh()) + ['totals' => Drives::totals($drive)], 'Drive closed.');
        });
    }

    /** POST unity/drives/{id}/apply (levy only): returns {added}. */
    public function apply(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $drive = $this->findDrive($request, $id);
            $added = Drives::apply($drive);

            return $this->success(['added' => $added, 'totals' => Drives::totals($drive)], $added === 0 ? 'Every member already has this levy.' : "Levy given to {$added} members.");
        });
    }

    /** POST unity/drives/{id}/pledges {member_id, pledged_minor, due_on?} */
    public function pledge(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['member_id' => ['required', 'integer'], 'pledged_minor' => ['required', 'integer', 'min:1'], 'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $drive = $this->findDrive($request, $id);
            $p = Drives::pledge($drive, UnityMembers::find($this->cid($request), (int) $d['member_id']), (int) $d['pledged_minor'], $d['due_on'] ?? null);

            return $this->created(['id' => (int) $p->id, 'member_id' => (int) $p->member_id, 'pledged_minor' => (int) $p->pledged_minor, 'paid_minor' => (int) $p->paid_minor,
                'due_on' => $p->due_on?->toDateString(), 'status' => $p->status, 'totals' => Drives::totals($drive)], 'Pledge saved.');
        });
    }

    /** POST unity/drives/{id}/payments {member_id, amount_minor, method, paid_on?, reference?}: returns the receipt number. */
    public function pay(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['member_id' => ['required', 'integer'], 'amount_minor' => ['required', 'integer', 'min:1'], 'method' => ['required', 'string', 'max:30'],
            'paid_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'reference' => ['sometimes', 'nullable', 'string', 'max:60']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $drive = $this->findDrive($request, $id);
            $pay = Drives::pay($drive, UnityMembers::find($this->cid($request), (int) $d['member_id']), $d, (int) $request->user()->id);

            return $this->created(['id' => (int) $pay->id, 'receipt_no' => $pay->receipt_no, 'member_id' => (int) $pay->member_id, 'amount_minor' => (int) $pay->amount_minor,
                'paid_on' => substr((string) $pay->getRawOriginal('paid_on'), 0, 10), 'method' => $pay->method, 'reference' => $pay->reference, 'totals' => Drives::totals($drive)], 'Payment recorded.');
        });
    }

    private function findDrive(Request $request, int $id): UnityDrive
    {
        return UnityDrive::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function totals(UnityDrive $d, bool $money): array
    {
        $t = Drives::totals($d);

        return $money ? $t : ['pledged_minor' => null, 'paid_minor' => null] + $t;
    }

    private function checkChapter(Request $request, mixed $chapterId): void
    {
        if ($chapterId !== null && ! DB::table('unity_chapters')->where('company_id', $this->cid($request))->where('id', (int) $chapterId)->where('kind', 'chapter')->where('is_deleted', 0)->exists()) {
            throw \App\Exceptions\BusinessRuleException::make('validation', 'That chapter was not found.', ['chapter_id' => ['Unknown chapter.']]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    private static function driveRules(bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return ['kind' => [$r, 'in:'.implode(',', Drives::KINDS)], 'title' => [$r, 'string', 'max:150'], 'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'target_minor' => ['sometimes', 'nullable', 'integer', 'min:0'], 'per_member_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'chapter_id' => ['sometimes', 'nullable', 'integer']];
    }
}
