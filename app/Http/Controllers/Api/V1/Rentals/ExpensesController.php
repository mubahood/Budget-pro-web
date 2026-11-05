<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalExpense;
use App\Models\RentalProperty;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Portal\LandlordApprovals;
use App\Services\Rentals\RentalAccess;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Money out (rentals_expenses): expenses for a landlord's property or the agency's own, the cash book, moving money between accounts. */
class ExpensesController extends RentalsController
{
    /**
     * GET rentals/expenses?property_id&landlord_id&charged_to&category&from&to&q&status=live|reversed|all: `status`, as the web's: paid, or reversed; with none, every one.
     * `q` also finds the property's name. `meta.totals`: {count, amount_minor (not reversed)} of the whole list.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_expenses');
            $q = RentalExpense::withoutGlobalScopes()->where('company_id', $this->cid($request));
            if (RentalAccess::propertyIds($this->user($request)) !== null) {
                $this->scoped($q, $request);
            }
            foreach (['property_id', 'landlord_id', 'unit_id', 'maintenance_id'] as $f) {
                if ($request->filled($f)) {
                    $q->where($f, (int) $request->query($f));
                }
            }
            if (in_array($request->query('charged_to'), ['landlord', 'agency'], true)) {
                $q->where('charged_to', $request->query('charged_to'));
            }
            if ($request->filled('category')) {
                $q->where('category', (string) $request->query('category'));
            }
            if (($from = $this->day($request, 'from')) !== null) {
                $q->where('paid_on', '>=', $from);
            }
            if (($to = $this->day($request, 'to')) !== null) {
                $q->where('paid_on', '<=', $to);
            }
            match ($request->query('status')) {
                'live' => $q->whereNull('reversed_at'),
                'reversed' => $q->whereNotNull('reversed_at'),
                default => null,
            };
            if (($term = trim((string) $request->query('q', ''))) !== '') {
                $like = self::like($term);
                $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('description', 'like', $like)->orWhere('vendor', 'like', $like)->orWhere('category', 'like', $like)
                    ->orWhereIn('property_id', RentalProperty::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('name', 'like', $like)->select('id')));
            }
            $amount = (int) (clone $q)->toBase()->whereNull('reversed_at')->sum('amount_minor');
            $p = $this->paginate($q->orderByDesc('paid_on')->orderByDesc('id'), $request);
            $present = $this->present($request)->prime($p->items())->primeFiles(collect($p->items())->pluck('receipt_uuid'));

            return $this->page($p, collect($p->items())->map(fn ($e) => $present->expense($e))->all(), ['categories' => Expenses::categories(), 'totals' => ['count' => $p->total(), 'amount_minor' => $amount]]);
        });
    }

    /**
     * GET rentals/expenses/landlord-effect?property_id&amount_minor&maintenance_id: what a new expense charged to this property's landlord would do, as the web's form warns: above the landlord's limit it needs a reason,
     * and at the office's desk, what their balance would fall to. Nothing is written. {needs_reason, landlord, limit_minor, below_zero_minor}; the two figures are null for a login tied to some properties.
     */
    public function landlordEffect(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_expenses');
            $cid = $this->cid($request);
            $property = $this->scoped(RentalProperty::withoutGlobalScopes()->where('company_id', $cid), $request, 'id')->find((int) $request->query('property_id')) ?? throw $this->notFoundRecord('property');
            $state = LandlordApprovals::check($cid, (int) $property->id, $this->minor($request, 'amount_minor'), $request->filled('maintenance_id') ? (int) $request->query('maintenance_id') : null);
            $office = RentalAccess::propertyIds($this->user($request)) === null;
            $below = $office && $state['landlord_id'] !== null && $state['balance_after_minor'] < 0 ? (int) $state['balance_after_minor'] : null;

            return $this->success(['needs_reason' => (bool) $state['needs_reason'], 'landlord' => $state['landlord'] === '' ? null : (string) $state['landlord'], 'limit_minor' => $office ? $state['limit_minor'] : null, 'below_zero_minor' => $below]);
        });
    }

    /** POST rentals/expenses {property_id, unit_id, category, description, vendor, amount_minor, method, paid_on, charged_to, receipt_uuid, maintenance_id, over_limit_reason} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            if (! $request->filled('property_id')) {
                $this->wholeAccount($request); // the agency's own cost, on no building
            }
            $data = $request->only(['property_id', 'unit_id', 'category', 'description', 'vendor', 'method', 'charged_to', 'receipt_uuid', 'maintenance_id', 'over_limit_reason'])
                + ['amount_minor' => $this->minor($request, 'amount_minor'), 'paid_on' => $this->day($request, 'paid_on')];

            return $this->created($this->present($request)->expense(app(Expenses::class)->record($this->user($request), $data)), 'Expense recorded.');
        });
    }

    /** POST rentals/expenses/{id}/reverse {reason} */
    public function reverse(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $q = RentalExpense::withoutGlobalScopes()->where('company_id', $this->cid($request));
            if (RentalAccess::propertyIds($this->user($request)) !== null) {
                $this->scoped($q, $request);
            }
            $expense = $q->find($id) ?? throw $this->notFoundRecord('expense');

            return $this->success($this->present($request)->expense(app(Expenses::class)->reverse($this->user($request), $expense, (string) $request->input('reason', ''))), 'Expense reversed.');
        });
    }

    /** GET rentals/cash?from&to&method&q: the balance per account and the cash book for the period (the month so far by default), with the running balance. `q` narrows the rows by what they say, as the web's search; the period's figures stay the period's. */
    public function cash(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_expenses');
            $this->wholeAccount($request);
            $cid = $this->cid($request);
            $today = LocalDate::today($cid);
            $from = $this->day($request, 'from') ?? $today->copy()->startOfMonth()->toDateString();
            $to = $this->day($request, 'to') ?? $today->toDateString();
            if ($from > $to) {
                throw BusinessRuleException::make('date_invalid', 'The start of the period is after its end.');
            }
            $method = in_array($request->query('method'), CashBook::METHODS, true) ? (string) $request->query('method') : null;

            $book = CashBook::statement($cid, $from, $to, $method);
            if (($term = mb_strtolower(trim((string) $request->query('q', '')))) !== '') {
                $book['rows'] = array_values(array_filter($book['rows'], fn ($r) => str_contains(mb_strtolower($r['description']), $term)));
            }

            return $this->success(['currency' => $this->present($request)->currency(), 'balances' => CashBook::balances($cid), 'from' => $from, 'to' => $to, 'method' => $method] + $book);
        });
    }

    /** POST rentals/cash/opening {method, amount_minor, on, replace}: what an account already held when the books began (cash +, equity +); once per account, `replace` corrects it. */
    public function opening(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $this->wholeAccount($request);
            $cid = $this->cid($request);
            $on = $this->day($request, 'on');
            app(CashBook::class)->opening($this->user($request), (string) $request->input('method', ''), $this->minor($request, 'amount_minor'),
                $on !== null ? Carbon::parse($on) : LocalDate::today($cid), $request->boolean('replace'));

            return $this->created(['currency' => $this->present($request)->currency(), 'balances' => CashBook::balances($cid)], 'Opening balance saved.');
        });
    }

    /** POST rentals/cash/transfers {from, to, amount_minor, on, note}: banking cash, withdrawing mobile money. */
    public function transfer(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $this->wholeAccount($request);
            app(CashBook::class)->transfer($this->user($request), (string) $request->input('from', ''), (string) $request->input('to', ''), $this->minor($request, 'amount_minor'),
                $this->day($request, 'on'), $request->filled('note') ? (string) $request->input('note') : null);

            return $this->created(['currency' => $this->present($request)->currency(), 'balances' => CashBook::balances($this->cid($request))], 'Money moved.');
        });
    }
}
