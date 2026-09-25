<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\FinancialPeriod;
use App\Models\Payment;
use App\Models\SaleRecord;
use App\Models\User;
use App\Services\Shop\CustomerService;
use App\Services\Shop\PaymentService;
use App\Services\Shop\SaleService;
use App\Services\Team\Permissions;
use App\Support\LocalDate;
use App\Support\Money;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Illuminate\Support\Facades\DB;

class SaleRecordController extends TenantAdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Sales';

    /** Columns that only SaleService / PaymentService / ReturnService may change on a recorded sale. */
    private const DERIVED = ['status', 'payment_status', 'amount_paid', 'balance', 'total_amount', 'subtotal', 'discount_amount',
        'refunded_amount', 'change_given', 'sale_date', 'financial_period_id', 'payment_method', 'receipt_number', 'invoice_number'];

    /** Old/API spellings of each payment method, so filters also find sales recorded before the methods were standardised. */
    private const METHOD_ALIASES = [
        'cash' => ['cash', 'Cash'],
        'mobile_money' => ['mobile_money', 'Mobile Money', 'Mobile money', 'momo', 'MTN', 'Airtel'],
        'card' => ['card', 'Card', 'Credit Card', 'Debit Card'],
        'bank' => ['bank', 'Bank', 'Bank Transfer', 'bank_transfer'],
        'credit' => ['credit', 'Credit', 'on_credit'],
    ];

    /** Sales are never deleted: the grid's delete button voids the sale (stock + ledger reversed). */
    public function destroy($id)
    {
        if (! Permissions::can(self::user(), 'void')) {
            return response(['status' => false, 'message' => 'You are not allowed to void sales.']);
        }
        try {
            $sale = $this->ownedSale($id);
            (new SaleService())->void($sale, 'Voided from admin', (int) self::user()->id);
        } catch (BusinessRuleException $e) {
            return response(['status' => false, 'message' => $e->getMessage()]);
        }

        return response(['status' => true, 'message' => 'Sale voided; stock and ledger reversed.']);
    }

    /** The sale page: a receipt-like summary, then the things you can do with the sale. */
    public function show($id, Content $content)
    {
        $sale = $this->ownedSale($id);

        return $content
            ->title('Sale '.($sale->receipt_number ?: '#'.$sale->id))
            ->description($sale->sale_date->format('d M Y'))
            ->body(view('admin.sale-show', $this->showData($sale)));
    }

    public function sendReceipt($id)
    {
        $sale = $this->ownedSale($id);
        try {
            app(\App\Services\Engage\ReceiptDelivery::class)->send($sale, request('phone') ?: null);
            admin_success('Receipt sent', 'On WhatsApp (or SMS).');
        } catch (BusinessRuleException $e) {
            admin_error('Not sent', $e->getMessage());
        }

        return redirect(admin_url('sale-records/'.$id));
    }

    public function momoRequest($id)
    {
        $sale = $this->ownedSale($id);
        try {
            $r = app(\App\Services\Engage\MomoCollections::class)->request($sale, (string) request('phone'), request('network') ?: null, null, (int) self::user()->id);
            $r->status === 'failed' ? admin_error('Request failed', (string) $r->error) : admin_success('Request sent', 'The customer approves on their phone. Refresh this page to see the payment.');
        } catch (BusinessRuleException $e) {
            admin_error('Not sent', $e->getMessage());
        }

        return redirect(admin_url('sale-records/'.$id));
    }

    /** Customer brought goods back (client report 2026-09-25): good goods go back on the shelf, faulty ones don't. */
    public function returnItems($id)
    {
        $u = self::user();
        $sale = $this->ownedSale($id);
        $lines = [];
        foreach ((array) request('lines', []) as $itemId => $l) {
            if (is_array($l) && (float) ($l['quantity'] ?? 0) > 0) {
                $lines[] = ['sale_item_id' => (int) $itemId, 'quantity' => (float) $l['quantity'], 'restock' => ($l['condition'] ?? 'good') === 'good'];
            }
        }
        try {
            $return = app(\App\Services\Shop\ReturnService::class)->create($sale, $lines, (int) $u->id, request('reason') ?: null, (string) (request('refund_method') ?: 'cash'));
            admin_success('Return recorded', 'Value '.Money::format($return->value).'. Good items are back in stock; faulty items are recorded but not restocked.');
        } catch (BusinessRuleException $e) {
            admin_error('Return not recorded', $e->getMessage());
        }

        return redirect(admin_url('sale-records/'.$id));
    }

    public function void($id)
    {
        $u = self::user();
        $sale = $this->ownedSale($id);
        if (! Permissions::can($u, 'void')) {
            return $this->refuse($id, 'Not allowed', 'You are not allowed to void sales. Ask the owner or a manager.');
        }
        $reason = trim((string) request('reason', '')) ?: 'Voided from admin';
        try {
            (new SaleService())->void($sale, $reason, (int) $u->id);
        } catch (BusinessRuleException $e) {
            return $this->refuse($id, 'Sale not voided', $e->getMessage());
        }

        if (request()->wantsJson()) {
            return response()->json(['status' => true, 'message' => 'Sale voided.']);
        }
        admin_success('Sale voided', 'Stock and money were reversed. Record a new sale if the customer still buys.');

        return redirect(admin_url('sale-records/'.$id));
    }

    /** Money received later on a sale (credit / part payment). More than the balance is change, not income. */
    public function receivePayment($id)
    {
        $u = self::user();
        $sale = $this->ownedSale($id);
        if (! Permissions::can($u, 'sell')) {
            return $this->refuse($id, 'Not allowed', 'You are not allowed to take payments.');
        }
        $methods = self::paymentMethods(false);
        $method = Payment::normalizeMethod((string) request('method', 'cash'));
        if (! array_key_exists($method, $methods)) {
            return $this->refuse($id, 'Payment not recorded', 'Choose how the customer paid.');
        }
        $amount = round(self::number(request('amount')), 2);
        $balance = round((float) $sale->balance, 2);
        if ($sale->voided_at !== null) {
            return $this->refuse($id, 'Payment not recorded', 'This sale was voided; it cannot receive payments.');
        }
        if ($balance <= 0) {
            return $this->refuse($id, 'Payment not recorded', 'This sale is already fully paid.');
        }
        if ($amount <= 0) {
            return $this->refuse($id, 'Payment not recorded', 'Enter the amount received (more than zero).');
        }
        $applied = min($amount, $balance);
        try {
            (new SaleService())->addPayment($sale, [
                'amount' => $applied, 'method' => $method, 'reference' => trim((string) request('reference', '')) ?: null,
            ], (int) $u->id);
        } catch (BusinessRuleException $e) {
            return $this->refuse($id, 'Payment not recorded', $e->getMessage());
        }
        $sale->refresh();
        $msg = Money::format($applied).' received by '.$methods[$method].'. ';
        $msg .= (float) $sale->balance > 0 ? 'Still owed: '.Money::format($sale->balance).'.' : 'The sale is now fully paid.';
        if ($amount > $applied) {
            $msg .= ' Give back change of '.Money::format($amount - $applied).'.';
        }
        admin_success('Payment recorded', $msg);

        return redirect(admin_url('sale-records/'.$id));
    }

    /** Undo a payment recorded by mistake (contra payment + contra ledger row; nothing deleted). */
    public function reversePayment($id, $paymentId)
    {
        $u = self::user();
        $sale = $this->ownedSale($id);
        if (! Permissions::can($u, 'void')) {
            return $this->refuse($id, 'Not allowed', 'You are not allowed to reverse payments. Ask the owner or a manager.');
        }
        $payment = Payment::withoutGlobalScopes()->where('company_id', $sale->company_id)->where('sale_record_id', $sale->id)->find($paymentId);
        if ($payment === null) {
            abort(404);
        }
        if ($sale->voided_at !== null) {
            return $this->refuse($id, 'Not reversed', 'This sale was voided; its payments were already reversed.');
        }
        if ($payment->is_reversal || (float) $payment->amount <= 0) {
            return $this->refuse($id, 'Not reversed', 'Only money received can be reversed.');
        }
        if (Payment::withoutGlobalScopes()->where('reverses_id', $payment->id)->exists()) {
            return $this->refuse($id, 'Not reversed', 'This payment was already reversed.');
        }
        $reason = trim((string) request('reason', '')) ?: 'Reversed from the sale page';
        try {
            (new PaymentService())->reverse($payment, $reason, (int) $u->id);
        } catch (BusinessRuleException $e) {
            return $this->refuse($id, 'Not reversed', $e->getMessage());
        }
        if ($sale->customer_id) {
            (new CustomerService())->recalc((int) $sale->customer_id);
        }
        admin_success('Payment reversed', Money::format($payment->amount).' was taken off this sale. The customer owes it again.');

        return redirect(admin_url('sale-records/'.$id));
    }

    protected function grid()
    {
        $grid = new Grid(new SaleRecord());
        $u = self::user();
        $companyId = (int) $u->company_id;

        $grid->model()->where('company_id', $companyId)
            ->select(['id', 'company_id', 'receipt_number', 'invoice_number', 'sale_date', 'customer_name', 'customer_phone', 'customer_id',
                'total_amount', 'refunded_amount', 'amount_paid', 'balance', 'payment_method', 'payment_status', 'status', 'voided_at', 'created_at', 'created_by_id'])
            ->orderByDesc('id');

        $grid->header(function () use ($companyId) {
            $today = LocalDate::today($companyId)->toDateString();
            $row = DB::table('sale_records')->where('company_id', $companyId)->whereDate('sale_date', $today)->whereNull('voided_at')
                ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_amount - refunded_amount), 0) AS total, COALESCE(SUM(balance), 0) AS owed')
                ->first();

            return '<div style="padding:4px 0"><strong>Today:</strong> '.(int) ($row->n ?? 0).' sale'.((int) ($row->n ?? 0) === 1 ? '' : 's')
                .' · <strong>'.e(Money::format($row->total ?? 0)).'</strong>'
                .((float) ($row->owed ?? 0) > 0 ? ' · <span class="text-danger">'.e(Money::format($row->owed)).' not yet paid</span>' : '')
                .' <a href="'.admin_url('sale-records/create').'" class="btn btn-xs btn-success" style="margin-left:8px"><i class="fa fa-plus"></i> New sale</a></div>';
        });

        $grid->quickSearch(function ($model, $query) {
            $query = trim((string) $query);
            $like = '%'.addcslashes($query, '%_\\').'%';
            $model->where(function ($w) use ($like) {
                $w->where('receipt_number', 'like', $like)->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like)->orWhere('invoice_number', 'like', $like);
            });
        })->placeholder('Receipt number, customer name or phone');

        $grid->filter(function (Grid\Filter $filter) use ($companyId) {
            $filter->disableIdFilter();
            $filter->between('sale_date', __('Sale date'))->date();
            $filter->equal('payment_status', __('Payment'))->select(['Paid' => 'Paid', 'Partial' => 'Part paid', 'Unpaid' => 'Not paid']);
            $filter->equal('status', __('Status'))->select([
                'Completed' => 'Completed', 'Partially Refunded' => 'Partially refunded', 'Refunded' => 'Refunded', 'Voided' => 'Voided',
            ]);
            $filter->where(function ($query) {
                $method = (string) request('payment_method');
                $query->whereIn('payment_method', self::METHOD_ALIASES[$method] ?? [$method]);
            }, __('Paid by'), 'payment_method')->select(self::paymentMethods());
            $filter->equal('created_by_id', __('Sold by'))
                ->select(DB::table('admin_users')->where('company_id', $companyId)->orderBy('name')->pluck('name', 'id'));
        });

        $grid->exporter(function ($export) {
            $export->filename('Sales_'.date('Y-m-d_His'));
            $export->originalValue(['total_amount', 'amount_paid', 'balance']);
        });

        $grid->batchActions(function ($batch) {
            $batch->disableDelete();
        });
        $grid->actions(function ($actions) {
            $actions->disableEdit();
            $actions->disableDelete(); // voiding needs a reason: it is done on the sale page
        });

        $grid->column('sale_date', __('Date'))->display(fn ($d) => $d ? date('d M Y', strtotime((string) $d)) : '')->sortable();
        $grid->column('receipt_number', __('Receipt'))->display(fn ($r) => '<a href="'.admin_url('sale-records/'.self::row($this)->id).'"><strong>'.e((string) $r).'</strong></a>')->sortable();
        $grid->column('customer_name', __('Customer'))->display(function ($name) {
            $phone = (string) self::row($this)->customer_phone;

            return e((string) ($name ?: 'Walk-in')).($phone !== '' ? '<br><small class="text-muted">'.e($phone).'</small>' : '');
        });
        $grid->column('total_amount', __('Total'))->display(fn ($v) => '<strong>'.e(Money::format($v)).'</strong>')->sortable();
        $grid->column('amount_paid', __('Paid'))->display(fn ($v) => e(Money::format($v)));
        $grid->column('balance', __('Balance'))->display(function ($v) {
            if (self::row($this)->voided_at) {
                return '<span class="text-muted">—</span>';
            }

            return (float) $v > 0
                ? '<span class="label label-danger">'.e(Money::format($v)).' owed</span>'
                : '<span class="label label-success">Paid</span>';
        })->sortable();
        $grid->column('status', __('Status'))->display(fn ($s) => self::statusLabel((string) $s));
        $grid->column('payment_method', __('Paid by'))->display(fn ($m) => e(self::methodLabel((string) $m)))->hide();
        $grid->column('receipt_pdf', __('Receipt'))->display(fn () => '<a href="'.url('sale-receipt-pdf?id='.self::row($this)->id).'" target="_blank" class="btn btn-xs btn-default"><i class="fa fa-print"></i> Receipt</a>');

        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new SaleRecord());
        $u = self::user();
        $companyId = (int) $u->company_id;
        $editingId = request()->route()?->parameter('sale_record');

        if ($editingId) {
            $this->editForm($form, (int) $editingId);
        } else {
            $this->createForm($form, $u);
        }

        $form->tools(function (Form\Tools $tools) {
            $tools->disableDelete();
        });
        $form->footer(function ($footer) {
            $footer->disableViewCheck();
            $footer->disableEditingCheck();
            $footer->disableCreatingCheck();
        });

        return $form;
    }

    // ------------------------------------------------------------------ create

    private function createForm(Form $form, User $u): void
    {
        $companyId = (int) $u->company_id;
        $canDiscount = Permissions::can($u, 'discount');

        $form->hidden('company_id');
        $form->hidden('created_by_id');
        $form->hidden('financial_period_id');

        $form->divider(__('Items'));
        $form->hasMany('saleRecordItems', ' ', function (Form\NestedForm $f) use ($companyId, $canDiscount) {
            $f->select('stock_item_id', __('Product'))
                ->options(function ($id) use ($companyId) {
                    $name = $id ? DB::table('stock_items')->where('company_id', $companyId)->where('id', $id)->value('name') : null;

                    return $name ? [$id => $name] : [];
                })
                ->config('minimumInputLength', 0)
                ->ajax(admin_url('ajax/products'))
                ->rules('required')
                ->help('Type the name, code or barcode');
            $f->decimal('quantity', __('Quantity'))->default(1)->rules('required|numeric|min:0.001');
            $price = $f->decimal('unit_price', __('Unit price'))->rules('nullable|numeric|min:0');
            if (! $canDiscount) {
                $price->readonly()->help('The product\'s selling price');
            }
            if ($canDiscount) {
                $f->decimal('discount_amount', __('Discount'))->default(0)->rules('nullable|numeric|min:0')->help('Money off this line');
            }
        });

        $form->divider(__('Payment'));
        $form->select('payment_method', __('Paid by'))->options(self::paymentMethods())->default('cash')->rules('required');
        $form->decimal('amount_paid', __('Amount received'))
            ->rules(['nullable', 'regex:/^\s*[0-9][0-9,\s]*(\.[0-9]+)?\s*$/'], ['regex' => 'Enter the amount received in numbers.'])
            ->help('Filled with the total. Type what the customer gave to see the change. For "Credit (pay later)" it stays 0.');
        $form->html('<div id="sale-summary" class="well well-sm" style="margin:0;font-size:16px">'
            .'Total: <strong id="sale-total">0</strong>'
            .'<span id="sale-change" style="margin-left:18px;display:none">Change: <strong class="text-success"></strong></span>'
            .'<span id="sale-balance" style="margin-left:18px;display:none">Balance owed: <strong class="text-danger"></strong></span>'
            .'<div id="sale-owed-hint" class="text-warning" style="font-size:13px;display:none">Not fully paid: choose the customer or enter their name and phone below, so the debt goes into your debt book.</div>'
            .'</div>', __('Summary'));

        $form->divider(__('Customer'));
        $form->select('customer_id', __('Customer account'))
            ->options(self::customerOptions($companyId))
            ->help('Optional for cash sales. Needed when the customer will pay later.');
        $form->text('customer_name', __('Customer name'))->placeholder('Walk-in')->rules('nullable|max:191');
        $form->text('customer_phone', __('Phone'))->placeholder('e.g. 0772 123456 or +256 772 123456')
            ->rules(['nullable', 'max:30', 'regex:/^\+?[0-9][0-9\s\-().]{5,24}$/'], ['regex' => 'Enter a phone number using digits (a leading + is fine).']);
        $form->textarea('customer_address', __('Address'))->rows(2)->rules('nullable|max:500');

        $form->divider(__('More'));
        $form->date('sale_date', __('Sale date'))->default(LocalDate::today($companyId)->toDateString())->rules('required|date')
            ->help('Today, unless you are recording an older sale.');
        $form->textarea('notes', __('Notes'))->rows(2)->rules('nullable|max:2000');

        Admin::script($this->createScript());

        $form->saving(function (Form $form) use ($u, $companyId, $canDiscount) {
            if ($form->model()->id) {
                return;
            }

            return $this->prepareNewSale($form, $u, $companyId, $canDiscount);
        });

        // Post-processing: stock, totals, numbering, payments and ledger through SaleService.
        $form->saved(function (Form $form) use ($u) {
            /** @var SaleRecord $saleRecord */
            $saleRecord = $form->model();
            $result = $saleRecord->processAndCompute();

            if (! $result['success']) {
                // The header/lines were persisted by the form but never processed (no stock or ledger effect):
                // remove the unprocessed draft so nothing half-done is left behind.
                DB::table('sale_record_items')->where('sale_record_id', $saleRecord->id)->delete();
                DB::table('sale_records')->where('id', $saleRecord->id)->whereNull('processed_at')->delete();
                admin_error('Sale not recorded', e($result['message']));

                return redirect(admin_url('sale-records/create'))->withInput();
            }
            if ($saleRecord->customer_id) {
                (new CustomerService())->recalc((int) $saleRecord->customer_id);
            }

            $data = $result['data'];
            $sale = SaleRecord::withoutGlobalScopes()->find($saleRecord->id);
            $lines = ['Receipt '.e((string) $data['receipt_number']).' — total '.e(Money::format($data['total_amount'])).'.'];
            if ((float) $sale?->change_given > 0) {
                $lines[] = 'Give change: <strong>'.e(Money::format($sale->change_given)).'</strong>.';
            }
            if ((float) $data['balance'] > 0) {
                $lines[] = e(Money::format($data['balance'])).' is owed by '.e((string) $sale?->customer_name).' (in the debt book).';
            }
            if (Permissions::can($u, 'view_profit')) {
                $lines[] = 'Profit: '.e(Money::format($data['total_profit'])).'.';
            }
            admin_success('Sale recorded', implode('<br>', $lines));

            return redirect(admin_url('sale-records/'.$saleRecord->id));
        });
    }

    /**
     * Server-side rules for a new web sale: products and stock, prices/discounts, the payment
     * (empty amount on a non-credit sale = paid in full), the period and the debt-book customer.
     */
    private function prepareNewSale(Form $form, User $u, int $companyId, bool $canDiscount)
    {
        $fail = function (string $title, string $message) {
            admin_error($title, $message);

            return back()->withInput();
        };

        self::put($form, 'company_id', $companyId);
        self::put($form, 'created_by_id', (int) $u->id);

        // Every row carries _remove_ (0 = keep, 1 = removed in the form): test its value, not its presence.
        $rows = $form->input('saleRecordItems') ?? request('saleRecordItems');
        $items = is_array($rows) ? array_filter($rows, fn ($i) => is_array($i) && ! empty($i['stock_item_id']) && empty($i[Form::REMOVE_FLAG_NAME])) : [];
        if (count($items) === 0) {
            return $fail('No items', 'Please add at least one item to the sale.');
        }

        $products = DB::table('stock_items')
            ->select('id', 'name', 'selling_price', 'current_quantity', 'allow_negative_stock', 'track_stock', 'is_active', 'is_deleted')
            ->where('company_id', $companyId)
            ->whereIn('id', array_map(fn ($i) => (int) $i['stock_item_id'], $items))
            ->get()->keyBy('id');

        $errors = [];
        $wanted = [];
        $total = 0.0;
        foreach ($items as $key => $item) {
            $p = $products->get((int) $item['stock_item_id']);
            if ($p === null || (int) $p->is_deleted === 1 || ($p->is_active !== null && (int) $p->is_active === 0)) {
                $errors[] = 'Line '.(count($wanted) + 1).': this product is not available for sale.';

                continue;
            }
            $qty = round(self::number($item['quantity'] ?? 0), 3);
            if ($qty <= 0) {
                $errors[] = e($p->name).': quantity must be more than zero.';

                continue;
            }
            $listPrice = round((float) $p->selling_price, 2);
            $rawPrice = (string) ($item['unit_price'] ?? '');
            $price = trim($rawPrice) === '' ? $listPrice : round(self::number($rawPrice), 2);
            $discount = round(self::number($item['discount_amount'] ?? 0), 2);
            if ($price < 0 || $discount < 0) {
                $errors[] = e($p->name).': price and discount cannot be negative.';

                continue;
            }
            if (! $canDiscount && (abs($price - $listPrice) > 0.001 || $discount > 0)) {
                $errors[] = e($p->name).': you are not allowed to change prices or give discounts.';

                continue;
            }
            $lineSub = round($qty * $price, 2);
            if ($discount > $lineSub) {
                $errors[] = e($p->name).': the discount is more than the line total.';

                continue;
            }
            $total += $lineSub - $discount;
            $wanted[$p->id] = ($wanted[$p->id] ?? 0) + $qty;
            // Normalised values go to the lines (a blank price becomes the product's price, the "5,000" typo becomes 5000).
            $rows[$key]['quantity'] = $qty;
            $rows[$key]['unit_price'] = $price;
            $rows[$key]['discount_amount'] = $discount;
        }
        foreach ($wanted as $pid => $qty) {
            $p = $products->get($pid);
            $tracked = $p->track_stock === null || (int) $p->track_stock === 1;
            if ($tracked && ! (int) $p->allow_negative_stock && $qty > (float) $p->current_quantity + 0.0005) {
                $errors[] = e($p->name).': only '.self::qty((float) $p->current_quantity).' in stock, you are selling '.self::qty($qty).'.';
            }
        }
        if ($errors !== []) {
            return $fail('Please check the items', implode('<br>', $errors));
        }
        self::put($form, 'saleRecordItems', $rows);
        $total = round($total, 2);

        // Payment: empty amount on a non-credit sale means the customer paid in full.
        $method = Payment::normalizeMethod((string) $form->input('payment_method'));
        if (! array_key_exists($method, self::paymentMethods())) {
            return $fail('Payment', 'Choose how the customer paid.');
        }
        $rawPaid = $form->input('amount_paid');
        if ($method === 'credit') {
            $paid = 0.0;
        } elseif ($rawPaid === null || trim((string) $rawPaid) === '') {
            $paid = $total;
        } else {
            $paid = round(self::number($rawPaid), 2);
        }
        self::put($form, 'payment_method', $method);
        self::put($form, 'amount_paid', $paid);
        $owed = max(0, round($total - $paid, 2));

        // Business date in the shop's timezone, and the financial period it falls in.
        try {
            $date = LocalDate::date($companyId, $form->input('sale_date') ?: null);
            $period = FinancialPeriod::resolveFor($companyId, $date);
        } catch (BusinessRuleException $e) {
            return $fail('Sale not recorded', e($e->getMessage()).' <a href="'.admin_url('financial-periods').'">Financial periods</a>');
        } catch (\Throwable $e) {
            return $fail('Sale date', 'Enter a valid sale date.');
        }
        self::put($form, 'sale_date', $date->toDateString());
        self::put($form, 'financial_period_id', $period->id);

        // Debt book: a named buyer with a phone (or a chosen account) is linked; money owed must be owed by someone.
        $name = trim((string) $form->input('customer_name'));
        $phone = self::cleanPhone((string) $form->input('customer_phone'));
        $isWalkIn = $name === '' || in_array(strtolower($name), ['walk-in', 'walk-in customer', 'walkin'], true);
        $customer = null;
        if ($form->input('customer_id')) {
            $customer = Customer::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->find((int) $form->input('customer_id'));
            if ($customer === null) {
                return $fail('Customer', 'That customer account was not found.');
            }
        } elseif (! $isWalkIn && $phone !== '') {
            $customer = Customer::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->where('phone', $phone)->first();
        } elseif ($owed > 0) {
            return $fail('Who owes this money?', Money::format($owed).' is not paid. Choose the customer account, or enter the customer\'s name and phone number, so the debt goes into your debt book.');
        }
        if ($owed > 0 && $customer !== null && $customer->credit_limit !== null) {
            $after = round((new CustomerService())->balance($customer) + $owed, 2);
            if ($after > (float) $customer->credit_limit) {
                return $fail('Credit limit', e($customer->name).' would owe '.Money::format($after).', above their credit limit of '.Money::format($customer->credit_limit).'.');
            }
        }
        if ($customer === null && ! $isWalkIn && $phone !== '') {
            $customer = new Customer();
            $customer->company_id = $companyId;
            $customer->name = $name;
            $customer->phone = $phone;
            $customer->address = trim((string) $form->input('customer_address')) ?: null;
            $customer->created_by_id = (int) $u->id;
            $customer->save();
        }
        if ($customer !== null) {
            self::put($form, 'customer_id', $customer->id);
            self::put($form, 'customer_name', $isWalkIn ? $customer->name : $name);
            self::put($form, 'customer_phone', $phone !== '' ? $phone : $customer->phone);
        } else {
            self::put($form, 'customer_id', null);
            self::put($form, 'customer_name', $isWalkIn ? 'Walk-in Customer' : $name);
            self::put($form, 'customer_phone', $phone !== '' ? $phone : null);
        }

        return null;
    }

    // ------------------------------------------------------------------ edit

    /** A recorded sale is immutable: only who bought it and notes can change. */
    private function editForm(Form $form, int $id): void
    {
        $sale = $this->ownedSale($id);
        $companyId = (int) $sale->company_id;

        $form->display('receipt_number', __('Receipt'));
        $form->display('sale_date', __('Sale date'))->with(fn ($d) => $d ? date('d M Y', strtotime((string) $d)) : '');
        $form->display('total_amount', __('Total'))->with(fn ($v) => Money::format($v));
        $form->display('amount_paid', __('Paid'))->with(fn ($v) => Money::format($v));
        $form->display('balance', __('Balance'))->with(fn ($v) => Money::format($v));
        $form->display('status', __('Status'));
        $form->html('<a href="'.admin_url('sale-records/'.$id).'">Receive a payment, return items or void the sale on the sale page.</a>');

        $form->divider(__('Customer'));
        $form->select('customer_id', __('Customer account'))->options(self::customerOptions($companyId))
            ->help('Link this sale to a customer so what they owe shows in the debt book.');
        $form->text('customer_name', __('Customer name'))->rules('nullable|max:191');
        $form->text('customer_phone', __('Phone'))
            ->rules(['nullable', 'max:30', 'regex:/^\+?[0-9][0-9\s\-().]{5,24}$/'], ['regex' => 'Enter a phone number using digits (a leading + is fine).']);
        $form->textarea('customer_address', __('Address'))->rows(2)->rules('nullable|max:500');
        $form->textarea('notes', __('Notes'))->rows(2)->rules('nullable|max:2000');

        // laravel-admin calls this closure bound to the model (self:: would be SaleRecord).
        $form->html(function () use ($sale) {
            $html = '<table class="table table-condensed"><tr><th>Item</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr>';
            foreach ($sale->saleRecordItems as $item) {
                $html .= '<tr><td>'.e((string) $item->item_name).'</td><td class="text-right">'.SaleRecordController::qty((float) $item->quantity).'</td>'
                    .'<td class="text-right">'.e(Money::format($item->unit_price)).'</td><td class="text-right">'.e(Money::format($item->line_total ?? $item->subtotal)).'</td></tr>';
            }

            return $html.'</table><div class="alert alert-warning" style="margin:0"><i class="fa fa-info-circle"></i> Items and amounts of a recorded sale cannot be changed. '
                .'To correct them, void it and record a new sale.</div>';
        }, __('Items'));

        $previousCustomer = $sale->customer_id;
        $form->saving(function (Form $form) use ($companyId) {
            $posted = array_keys(request()->all());
            if (request()->has('_editable')) {
                $posted[] = (string) request('name');
            }
            $blocked = array_values(array_intersect($posted, self::DERIVED));
            if ($blocked !== []) {
                $message = 'The '.str_replace('_', ' ', $blocked[0]).' of a recorded sale cannot be changed here. Use the sale page to receive a payment, return items or void the sale.';
                if (request()->has('_editable') || request()->ajax()) {
                    return response()->json(['status' => false, 'message' => $message]);
                }
                admin_error('Not changed', $message);

                return back()->withInput();
            }
            if ($form->input('customer_id')) {
                $exists = Customer::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->whereKey((int) $form->input('customer_id'))->exists();
                if (! $exists) {
                    admin_error('Customer', 'That customer account was not found.');

                    return back()->withInput();
                }
            }
            if ($form->input('customer_phone') !== null) {
                self::put($form, 'customer_phone', self::cleanPhone((string) $form->input('customer_phone')) ?: null);
            }

            return null;
        });

        $form->saved(function (Form $form) use ($id, $previousCustomer) {
            $now = $form->model()->customer_id;
            foreach (array_unique(array_filter([(int) $previousCustomer, (int) $now])) as $cid) {
                (new CustomerService())->recalc($cid);
            }
            admin_success('Sale updated', 'Customer details and notes saved.');

            return redirect(admin_url('sale-records/'.$id));
        });
    }

    // ------------------------------------------------------------------ helpers

    /** The signed-in admin user (laravel-admin types it as Authenticatable). */
    private static function user(): User
    {
        /** @var User $u */
        $u = Admin::user();

        return $u;
    }

    /** Set a submitted value in a saving hook. Form::input($key, null) would read instead of clearing, so write the inputs directly. */
    private static function put(Form $form, string $key, mixed $value): void
    {
        (function () use ($key, $value) {
            \Illuminate\Support\Arr::set($this->inputs, $key, $value);
        })->call($form);
    }

    /** The row a grid display callback is bound to (laravel-admin binds `$this` to the row model). */
    private static function row(object $bound): SaleRecord
    {
        return $bound instanceof SaleRecord ? $bound : new SaleRecord();
    }

    private function ownedSale($id): SaleRecord
    {
        $sale = SaleRecord::withoutGlobalScopes()->where('company_id', Admin::user()->company_id)->find((int) $id);
        if ($sale === null) {
            abort(404);
        }

        return $sale;
    }

    private function refuse($id, string $title, string $message)
    {
        if (request()->wantsJson()) {
            return response()->json(['status' => false, 'message' => $message], 422);
        }
        admin_error($title, e($message));

        return redirect(admin_url('sale-records/'.$id));
    }

    /** @return array<string, mixed> */
    private function showData(SaleRecord $sale): array
    {
        $u = self::user();
        $sale->load('saleRecordItems');
        $payments = Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->orderBy('id')->get();
        $reversed = $payments->whereNotNull('reverses_id')->pluck('reverses_id')->map(fn ($v) => (int) $v)->all();
        $userIds = $payments->pluck('received_by_id')->push($sale->created_by_id)->push($sale->voided_by_id)->filter()->unique()->all();

        return [
            'sale' => $sale,
            'items' => $sale->saleRecordItems,
            'payments' => $payments,
            'reversed' => $reversed,
            'users' => DB::table('admin_users')->whereIn('id', $userIds)->pluck('name', 'id'),
            'customer' => $sale->customer_id ? Customer::withoutGlobalScopes()->where('company_id', $sale->company_id)->find($sale->customer_id) : null,
            'methods' => self::paymentMethods(false),
            'canSell' => Permissions::can($u, 'sell'),
            'canVoid' => Permissions::can($u, 'void'),
            'canProfit' => Permissions::can($u, 'view_profit'),
            'profit' => (float) $sale->saleRecordItems->sum('profit'),
        ];
    }

    /** @return array<string, string> */
    public static function paymentMethods(bool $withCredit = true): array
    {
        $methods = (array) config('onboarding.payment_methods', ['cash' => 'Cash']);

        return $withCredit ? $methods : array_diff_key($methods, ['credit' => true]);
    }

    public static function methodLabel(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        $key = Payment::normalizeMethod($raw);

        return self::paymentMethods()[$key] ?? ucfirst(str_replace('_', ' ', $raw));
    }

    public static function statusLabel(string $status): string
    {
        $colors = ['Completed' => 'success', 'Voided' => 'default', 'Refunded' => 'danger', 'Partially Refunded' => 'warning', 'Pending' => 'warning', 'Cancelled' => 'default'];

        return '<span class="label label-'.($colors[$status] ?? 'default').'">'.e($status ?: 'Completed').'</span>';
    }

    /** "5" not "5.000"; "2.5" stays "2.5". */
    public static function qty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, '.', ','), '0'), '.');
    }

    /** "5,000" / " 5000 " -> 5000.0 */
    private static function number(mixed $v): float
    {
        return (float) str_replace([',', ' '], '', (string) $v);
    }

    private static function cleanPhone(string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', trim($phone)) ?? '';
    }

    /** @return array<int, string> */
    private static function customerOptions(int $companyId): array
    {
        return DB::table('customers')->where('company_id', $companyId)->where('is_deleted', 0)
            ->orderBy('name')->limit(3000)->get(['id', 'name', 'phone'])
            ->mapWithKeys(fn ($c) => [(int) $c->id => $c->name.($c->phone ? ' — '.$c->phone : '')])->all();
    }

    /** Live totals, change and balance on the new-sale form. */
    private function createScript(): string
    {
        return <<<'JS'
(function () {
    var ROW = '.has-many-saleRecordItems-form';
    var paidTouched = false;
    function num(v) { var n = parseFloat(String(v == null ? '' : v).replace(/[,\s]/g, '')); return isNaN(n) ? 0 : n; }
    function fmt(n) { return Math.round(n).toLocaleString('en-US'); }
    function rows() {
        return $('.has-many-saleRecordItems-forms ' + ROW).filter(function () { return String($(this).find('.fom-removed').val()) !== '1'; });
    }
    function recalc() {
        var total = 0;
        rows().each(function () {
            var $r = $(this);
            var q = num($r.find('.saleRecordItems.quantity').val());
            var p = num($r.find('.saleRecordItems.unit_price').val());
            var d = num($r.find('.saleRecordItems.discount_amount').val());
            var line = Math.max(0, q * p - d);
            total += line;
            var $lt = $r.find('.sale-line-total');
            if (!$lt.length) {
                $lt = $('<div class="sale-line-total col-sm-offset-2 col-sm-8" style="margin-bottom:10px"></div>');
                $r.find('.form-group').last().after($lt);
            }
            var prod = $r.data('product') || {};
            var warn = '';
            if (prod.track_stock && !prod.allow_negative_stock && q > prod.stock) {
                warn = ' <span class="text-danger">Only ' + prod.stock + ' in stock</span>';
            }
            $lt.html('Line total: <strong>' + fmt(line) + '</strong>' + warn);
        });
        total = Math.round(total * 100) / 100;
        var method = $('select.payment_method').val();
        var $paid = $('input.amount_paid');
        if (method === 'credit') {
            $paid.val(0).prop('readonly', true);
            paidTouched = false;
        } else {
            $paid.prop('readonly', false);
            if (!paidTouched) { $paid.val(total > 0 ? total : ''); }
        }
        var paid = num($paid.val());
        $('#sale-total').text(fmt(total));
        var diff = Math.round((paid - total) * 100) / 100;
        $('#sale-change').toggle(diff > 0).find('strong').text(fmt(diff));
        $('#sale-balance').toggle(diff < 0).find('strong').text(fmt(-diff));
        $('#sale-owed-hint').toggle(diff < 0);
    }
    var $doc = $(document);
    $doc.off('.saleform');
    $doc.on('select2:select.saleform', '.saleRecordItems.stock_item_id', function (e) {
        var d = (e.params && e.params.data) || {};
        var $r = $(this).closest(ROW);
        $r.data('product', d);
        if (d.price !== undefined) { $r.find('.saleRecordItems.unit_price').val(d.price); }
        var $q = $r.find('.saleRecordItems.quantity');
        if (!num($q.val())) { $q.val(1); }
        recalc();
        setTimeout(function () { $q.focus().select(); }, 0);
    });
    $doc.on('input.saleform change.saleform keyup.saleform', '.saleRecordItems.quantity, .saleRecordItems.unit_price, .saleRecordItems.discount_amount', recalc);
    $doc.on('input.saleform keyup.saleform', 'input.amount_paid', function () { paidTouched = true; recalc(); });
    $doc.on('change.saleform', 'select.payment_method', function () { paidTouched = false; recalc(); });
    setTimeout(function () {
        $('#has-many-saleRecordItems').off('.saleform').on('click.saleform', '.add, .remove', function () { setTimeout(recalc, 0); });
        if (!rows().length) { $('#has-many-saleRecordItems .add').first().trigger('click'); }
        paidTouched = String($('input.amount_paid').val() || '') !== '';
        recalc();
    }, 0);
})();
JS;
    }
}
