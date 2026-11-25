<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\UnityCategory;
use App\Models\UnitySubscription;
use App\Services\Unity\Memberships;
use App\Services\Unity\UnityMembers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Subscriptions: a period of a member's membership and what was paid for it (minor units). A new one starts the day after the
 * member's current period if it is still in force, else today (Memberships::renew). Never deleted: a mistake is cancelled.
 */
class SubscriptionsController extends UnityController
{
    /** GET unity/subscriptions?member_id&status&from&to (paid_on)&per_page&page, newest first */
    public function index(Request $request): JsonResponse
    {
        $q = UnitySubscription::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0);
        if ($request->filled('member_id')) {
            $q->where('member_id', (int) $request->query('member_id'));
        }
        if (in_array($status = $request->query('status'), config('unity.subscription_statuses'), true)) {
            $q->where('status', $status);
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if (is_string($v = $request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $q->where('paid_on', $op, $v);
            }
        }
        $p = $q->orderByDesc('starts_on')->orderByDesc('id')->paginate($this->perPage($request), ['*'], 'page', $this->pageNo($request));

        return $this->page($p, collect($p->items())->map(fn ($s) => self::shape($s))->all());
    }

    /**
     * POST unity/subscriptions {member_id, category_id? (the member's own if left out), amount_minor? (the category's fee), paid_minor?,
     * paid_on?, method?, reference?, status? (pending|paid|waived), starts_on?, notes?}
     */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['member_id' => ['required', 'integer'], 'category_id' => ['sometimes', 'nullable', 'integer'],
            'amount_minor' => ['sometimes', 'integer', 'min:0', 'max:'.\App\Support\Rules\SaccoRules::MAX_MINOR], 'paid_minor' => ['sometimes', 'integer', 'min:0', 'max:'.\App\Support\Rules\SaccoRules::MAX_MINOR],
            'paid_on' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'], 'method' => ['sometimes', 'nullable', Rule::in(config('unity.payment_methods'))],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'], 'status' => ['sometimes', Rule::in(['pending', 'paid', 'waived'])],
            'starts_on' => ['sometimes', 'date'], 'notes' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($request, $d) {
            $cid = $this->cid($request);
            $member = UnityMembers::find($cid, (int) $d['member_id']);
            $categoryId = $d['category_id'] ?? $member->category_id;
            if (! $categoryId) {
                throw BusinessRuleException::make('validation', 'Choose the membership category.', ['category_id' => ['Choose the membership category.']]);
            }
            $category = UnityCategory::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->find($categoryId)
                ?? throw BusinessRuleException::make('validation', 'That category is not this association\'s.', ['category_id' => ['Unknown category.']]);
            $s = Memberships::renew($member, $category, array_diff_key($d, array_flip(['member_id', 'category_id'])), (int) $request->user()->id);

            return $this->created(self::shape($s->fresh()), 'Subscription recorded.');
        });
    }

    /** PUT|PATCH unity/subscriptions/{id} {paid_minor?, paid_on?, method?, reference?, status? (pending|paid|waived), amount_minor? (while pending), notes?} */
    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['paid_minor' => ['sometimes', 'integer', 'min:0', 'max:'.\App\Support\Rules\SaccoRules::MAX_MINOR],
            'amount_minor' => ['sometimes', 'integer', 'min:0', 'max:'.\App\Support\Rules\SaccoRules::MAX_MINOR],
            'paid_on' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'], 'method' => ['sometimes', 'nullable', Rule::in(config('unity.payment_methods'))],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'], 'status' => ['sometimes', Rule::in(['pending', 'paid', 'waived'])], 'notes' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $s = $this->find($request, $id);
            if ($s->status === 'cancelled') {
                throw BusinessRuleException::make('cancelled_final', 'A cancelled subscription stays cancelled. Record a new one.');
            }
            if (array_key_exists('amount_minor', $d) && $s->status !== 'pending') {
                throw BusinessRuleException::make('validation', 'The amount changes only while the subscription waits for money.', ['amount_minor' => ['Already settled.']]);
            }
            $s->fill($d)->save();

            return $this->success(self::shape($s->fresh()), 'Saved.');
        });
    }

    /** POST unity/subscriptions/{id}/cancel {reason?}: the period no longer counts; the record stays. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:300']]);

        return $this->attempt(function () use ($request, $id, $d) {
            $s = $this->find($request, $id);
            if ($s->status !== 'cancelled') {
                $s->status = 'cancelled';
                if (filled($d['reason'] ?? null)) {
                    $s->notes = trim(($s->notes ? $s->notes.' | ' : '').'Cancelled: '.$d['reason']);
                }
                $s->save();
            }

            return $this->success(self::shape($s->fresh()), 'Subscription cancelled.');
        });
    }

    private function find(Request $request, int $id): UnitySubscription
    {
        return UnitySubscription::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    public static function shape(UnitySubscription $s): array
    {
        return ['id' => (int) $s->id, 'uuid' => $s->uuid, 'member_id' => (int) $s->member_id, 'category_id' => (int) $s->category_id,
            'starts_on' => $s->starts_on?->toDateString(), 'ends_on' => $s->ends_on?->toDateString(), 'amount_minor' => (int) $s->amount_minor, 'paid_minor' => (int) $s->paid_minor,
            'balance_minor' => max(0, (int) $s->amount_minor - (int) $s->paid_minor), 'paid_on' => $s->paid_on?->toDateString(), 'method' => $s->method, 'reference' => $s->reference,
            'receipt_no' => $s->receipt_no, 'status' => $s->status, 'notes' => $s->notes, 'version' => (int) $s->version];
    }
}
