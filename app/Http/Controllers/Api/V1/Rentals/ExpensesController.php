<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalExpense;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\RentalAccess;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Money out (rentals_expenses): expenses for a landlord's property or the agency's own, the cash book, moving money between accounts. */
class ExpensesController extends RentalsController
{
    /** GET rentals/expenses?property_id&landlord_id&charged_to&category&from&to&q */
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
            self::search($q, $request->query('q'), ['number', 'description', 'vendor', 'category']);
            $p = $this->paginate($q->orderByDesc('paid_on')->orderByDesc('id'), $request);
            $present = $this->present($request)->prime($p->items())->primeFiles(collect($p->items())->pluck('receipt_uuid'));

            return $this->page($p, collect($p->items())->map(fn ($e) => $present->expense($e))->all(), ['categories' => Expenses::categories()]);
        });
    }

    /** POST rentals/expenses {property_id, unit_id, category, description, vendor, amount_minor, method, paid_on, charged_to, receipt_uuid, maintenance_id, over_limit_reason} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
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

    /** GET rentals/cash?from&to&method: the balance per account and the cash book for the period (the month so far by default), with the running balance. */
    public function cash(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_expenses');
            $cid = $this->cid($request);
            $today = LocalDate::today($cid);
            $from = $this->day($request, 'from') ?? $today->copy()->startOfMonth()->toDateString();
            $to = $this->day($request, 'to') ?? $today->toDateString();
            if ($from > $to) {
                throw BusinessRuleException::make('date_invalid', 'The start of the period is after its end.');
            }
            $method = in_array($request->query('method'), CashBook::METHODS, true) ? (string) $request->query('method') : null;

            return $this->success(['currency' => $this->present($request)->currency(), 'balances' => CashBook::balances($cid), 'from' => $from, 'to' => $to, 'method' => $method]
                + CashBook::statement($cid, $from, $to, $method));
        });
    }

    /** POST rentals/cash/opening {method, amount_minor, on, replace}: what an account already held when the books began (cash +, equity +); once per account, `replace` corrects it. */
    public function opening(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
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
            app(CashBook::class)->transfer($this->user($request), (string) $request->input('from', ''), (string) $request->input('to', ''), $this->minor($request, 'amount_minor'),
                $this->day($request, 'on'), $request->filled('note') ? (string) $request->input('note') : null);

            return $this->created(['currency' => $this->present($request)->currency(), 'balances' => CashBook::balances($this->cid($request))], 'Money moved.');
        });
    }
}
