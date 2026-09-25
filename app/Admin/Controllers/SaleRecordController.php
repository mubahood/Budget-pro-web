<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\SaleRecord;
use App\Models\User;
use App\Services\Shop\SaleService;
use App\Support\Money;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Illuminate\Support\Facades\DB;

class SaleRecordController extends TenantAdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Sale Records';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    /** Sales are never deleted: the grid's delete button voids the sale (stock + ledger reversed). */
    public function destroy($id)
    {
        try {
            $this->void($id);
        } catch (BusinessRuleException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

        return response()->json(['status' => true, 'message' => 'Sale voided; stock and ledger reversed.']);
    }

    public function sendReceipt($id)
    {
        $sale = SaleRecord::findOrFail($id);
        try {
            app(\App\Services\Engage\ReceiptDelivery::class)->send($sale, request('phone') ?: null);
            admin_success('Receipt sent', 'On WhatsApp (or SMS).');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            admin_error('Not sent', $e->getMessage());
        }

        return redirect(admin_url('sale-records/'.$id));
    }

    public function momoRequest($id)
    {
        $sale = SaleRecord::findOrFail($id);
        try {
            $r = app(\App\Services\Engage\MomoCollections::class)->request($sale, (string) request('phone'), request('network') ?: null, null, (int) \Encore\Admin\Facades\Admin::user()->id);
            $r->status === 'failed' ? admin_error('Request failed', (string) $r->error) : admin_success('Request sent', 'The customer approves on their phone. Refresh this page to see the payment.');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            admin_error('Not sent', $e->getMessage());
        }

        return redirect(admin_url('sale-records/'.$id));
    }

    public function void($id)
    {
        $u = Admin::user();
        $sale = SaleRecord::withoutGlobalScopes()->where('company_id', $u->company_id)->find($id);
        if ($sale === null) {
            abort(404);
        }
        (new SaleService())->void($sale, request('reason', 'Voided from admin'), (int) $u->id);

        if (request()->wantsJson()) {
            return response()->json(['status' => true, 'message' => 'Sale voided.']);
        }
        admin_success('Sale voided', 'Stock and ledger entries were reversed.');

        return redirect(admin_url('sale-records'));
    }

    protected function grid()
    {
        $grid = new Grid(new SaleRecord());
        $u = Admin::user();

        // Eager load relationships to prevent N+1 queries
        $grid->model()->where('company_id', $u->company_id)
            ->with(['saleRecordItems:id,sale_record_id,quantity', 'createdBy:id,name'])
            ->select([
                'id',
                'company_id', // Required for relationships
                'receipt_number',
                'invoice_number',
                'sale_date',
                'customer_name',
                'customer_phone',
                'total_amount',
                'amount_paid',
                'balance',
                'payment_method',
                'payment_status',
                'status',
                'created_at',
                'created_by_id',
            ])
            ->orderBy('created_at', 'desc');

        // Filters
        $grid->filter(function ($filter) use ($u) {
            $filter->disableIdFilter();

            $filter->like('customer_name', __('Customer Name'));
            $filter->like('customer_phone', __('Customer Phone'));
            $filter->like('receipt_number', __('Receipt Number'));
            $filter->like('invoice_number', __('Invoice Number'));

            $filter->equal('payment_method', __('Payment Method'))
                ->select([
                    'Cash' => 'Cash',
                    'Credit Card' => 'Credit Card',
                    'Bank Transfer' => 'Bank Transfer',
                    'Mobile Money' => 'Mobile Money',
                ]);

            $filter->equal('payment_status', __('Payment Status'))
                ->select([
                    'Paid' => 'Paid',
                    'Unpaid' => 'Unpaid',
                    'Partial' => 'Partial',
                ]);

            $filter->equal('status', __('Status'))
                ->select([
                    'Completed' => 'Completed',
                    'Pending' => 'Pending',
                    'Cancelled' => 'Cancelled',
                ]);

            // Optimized query using DB for better performance
            $filter->equal('created_by_id', __('Created By'))
                ->select(DB::table('admin_users')
                    ->where('company_id', $u->company_id)
                    ->orderBy('name')
                    ->pluck('name', 'id'));

            $filter->between('sale_date', __('Sale Date'))->date();
            $filter->between('created_at', __('Created Date'))->datetime();
        });

        // Export functionality
        $grid->exporter(function ($export) {
            $export->filename('Sale_Records_'.date('Y-m-d_His'));
            $export->column('id', 'ID');
            $export->column('receipt_number', 'Receipt #');
            $export->column('invoice_number', 'Invoice #');
            $export->column('sale_date', 'Sale Date');
            $export->column('customer_name', 'Customer');
            $export->column('customer_phone', 'Phone');
            $export->column('total_amount', 'Total');
            $export->column('amount_paid', 'Paid');
            $export->column('balance', 'Balance');
            $export->column('payment_method', 'Payment Method');
            $export->column('payment_status', 'Payment Status');
            $export->column('status', 'Status');

            // Use original numeric values for export
            $export->originalValue(['total_amount', 'amount_paid', 'balance']);
        });

        $grid->quickSearch('customer_name', 'customer_phone', 'receipt_number')
            ->placeholder('Search by customer, phone, or receipt number');

        // Batch actions
        $grid->batchActions(function ($batch) {
            $batch->disableDelete(); // Prevent accidental batch deletion
        });

        // Actions
        $grid->actions(function ($actions) {
            // Keep all default actions
        });

        // Columns
        $grid->column('id', __('ID'))->sortable();

        $grid->column('receipt_number', __('Receipt #'))
            ->display(function ($receipt_number) {
                return '<strong>'.$receipt_number.'</strong>';
            })
            ->sortable();

        $grid->column('sale_date', __('Sale Date'))
            ->display(function ($sale_date) {
                return date('d M Y', strtotime($sale_date));
            })
            ->sortable();

        $grid->column('customer_name', __('Customer'))
            ->editable()
            ->display(function ($customer_name) {
                $phone = $this->customer_phone ? '<br><small>'.$this->customer_phone.'</small>' : '';

                return $customer_name.$phone;
            })
            ->sortable();

        $grid->column('customer_phone', __('Phone'))
            ->editable()
            ->hide();

        $grid->column('items_count', __('Items'))
            ->display(function () {
                $count = $this->saleRecordItems ? $this->saleRecordItems->count() : 0;

                return $count.' item'.($count != 1 ? 's' : '');
            });

        $grid->column('total_amount', __('Total Amount'))
            ->display(function ($total_amount) {
                return '<strong>'.Money::symbol().' '.number_format((float) $total_amount, 0).'</strong>';
            })
            ->sortable();

        $grid->column('amount_paid', __('Paid'))
            ->display(function ($amount_paid) {
                // Ensure we're working with the actual value
                $value = $amount_paid ?? 0;

                return Money::symbol().' '.number_format((float) $value, 0);
            })
            ->sortable();

        $grid->column('balance', __('Balance'))
            ->display(function ($balance) {
                $color = $balance > 0 ? 'danger' : 'success';

                return '<span class="label label-'.$color.'">'.Money::symbol().' '.number_format((float) $balance, 0).'</span>';
            })
            ->sortable();

        $grid->column('payment_status', __('Payment'))
            ->editable('select', [
                'Paid' => 'Paid',
                'Unpaid' => 'Unpaid',
                'Partial' => 'Partial',
            ])
            ->display(function ($payment_status) {
                $color = [
                    'Paid' => 'success',
                    'Unpaid' => 'danger',
                    'Partial' => 'warning',
                ];

                return '<span class="label label-'.($color[$payment_status] ?? 'default').'">'.$payment_status.'</span>';
            })
            ->help('Setting to "Paid" auto-fills amount paid')
            ->sortable();

        $grid->column('status', __('Status'))
            ->editable('select', [
                'Completed' => 'Completed',
                'Pending' => 'Pending',
                'Cancelled' => 'Cancelled',
            ])
            ->display(function ($status) {
                $color = [
                    'Completed' => 'success',
                    'Pending' => 'warning',
                    'Cancelled' => 'danger',
                ];

                return '<span class="label label-'.($color[$status] ?? 'default').'">'.$status.'</span>';
            })
            ->sortable();

        $grid->column('payment_method', __('Payment'))
            ->editable('select', [
                'Cash' => 'Cash',
                'Credit Card' => 'Credit Card',
                'Bank Transfer' => 'Bank Transfer',
                'Mobile Money' => 'Mobile Money',
            ])
            ->hide();

        $grid->column('notes', __('Notes'))
            ->editable('textarea')
            ->display(function ($notes) {
                return $notes ? substr($notes, 0, 30).(strlen($notes) > 30 ? '...' : '') : '-';
            })
            ->hide();

        $grid->column('customer_address', __('Address'))
            ->editable('textarea')
            ->hide();

        $grid->column('created_at', __('Created'))
            ->display(function ($created_at) {
                return date('d M Y, h:i A', strtotime($created_at));
            })
            ->sortable()
            ->hide();

        $grid->column('actions', __('Documents'))
            ->display(function () {
                $receiptLink = url('sale-receipt-pdf?id='.$this->id);
                $invoiceLink = url('sale-invoice-pdf?id='.$this->id);

                return "
                    <a href='{$receiptLink}' target='_blank' class='btn btn-xs btn-success' style='margin-right: 5px;'>
                        <i class='fa fa-file-text-o'></i> Receipt
                    </a>
                    <a href='{$invoiceLink}' target='_blank' class='btn btn-xs btn-primary'>
                        <i class='fa fa-file-text'></i> Invoice
                    </a>
                ";
            });

        return $grid;
    }

    /**
     * Make a show builder.
     *
     * @param  mixed  $id
     * @return Show
     */
    protected function detail($id)
    {
        $sale = SaleRecord::findOrFail($id);
        $show = new Show($sale);
        // Receipt on WhatsApp and mobile-money request (plan Part E1/E3).
        $show->field('engage', __('Send / collect'))->unescape()->as(fn () => view('admin.sale-engage', ['sale' => $sale])->render());

        $show->field('id', __('ID'));
        $show->field('receipt_number', __('Receipt Number'));
        $show->field('invoice_number', __('Invoice Number'));
        $show->field('sale_date', __('Sale Date'))->as(function ($sale_date) {
            return date('d M Y', strtotime($sale_date));
        });

        $show->divider();
        $show->field('customer_name', __('Customer Name'));
        $show->field('customer_phone', __('Customer Phone'));
        $show->field('customer_address', __('Customer Address'));

        $show->divider();
        $show->field('total_amount', __('Total Amount'))->as(function ($total_amount) {
            return Money::symbol().' '.number_format((float) $total_amount, 2);
        });
        $show->field('amount_paid', __('Amount Paid'))->as(function ($amount_paid) {
            return Money::symbol().' '.number_format((float) $amount_paid, 2);
        });
        $show->field('balance', __('Balance'))->as(function ($balance) {
            return Money::symbol().' '.number_format((float) $balance, 2);
        });
        $show->field('payment_method', __('Payment Method'));
        $show->field('payment_status', __('Payment Status'));

        $show->divider();
        $show->field('status', __('Status'));
        $show->field('notes', __('Notes'));

        $show->divider();
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Updated At'));

        // Show sale items in a table
        $show->saleRecordItems('Sale Items', function ($items) {
            $items->disableCreateButton();
            $items->disableFilter();
            $items->disableExport();
            $items->disableActions();

            $items->column('item_name', __('Item'));
            $items->column('item_sku', __('SKU'));
            $items->column('quantity', __('Quantity'));
            $items->column('unit_price', __('Unit Price'))->display(function ($unit_price) {
                return Money::symbol().' '.number_format((float) $unit_price, 2);
            });
            $items->column('subtotal', __('Subtotal'))->display(function ($subtotal) {
                return Money::symbol().' '.number_format((float) $subtotal, 2);
            });
        });

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new SaleRecord());
        $form->hidden('payment_status')->default('Unpaid');

        // Eager load relationships for edit mode
        if ($id = request()->route()->parameter('sale_record')) {
            $model = SaleRecord::with('saleRecordItems')->find($id);
            $form->model($model);
        }

        $u = Admin::user();

        // Hidden fields
        $form->hidden('company_id')->default($u->company_id);
        $form->hidden('created_by_id')->default($u->id);

        // Financial Period - Optimized query
        $activePeriods = DB::table('financial_periods')
            ->select('id', 'name')
            ->where('company_id', $u->company_id)
            ->where('status', 'Active')
            ->orderBy('name', 'desc')
            ->get();

        $form->select('financial_period_id', __('Financial Period'))
            ->options($activePeriods->pluck('name', 'id'))
            ->rules('required')
            ->default($activePeriods->first()->id ?? null);

        // Sale Date
        $form->date('sale_date', __('Sale Date'))
            ->default(date('Y-m-d'))
            ->rules('required');

        $form->divider('Customer Information');

        $form->text('customer_name', __('Customer Name'))
            ->rules('required|max:255');

        $form->mobile('customer_phone', __('Customer Phone'))
            ->options(['mask' => '999 999 9999'])
            ->rules('nullable|max:255');

        $form->textarea('customer_address', __('Customer Address'))
            ->rows(2)
            ->rules('nullable');

        $form->divider('Sale Items');

        // Check if we're editing an existing record
        $isEditing = request()->route()->parameter('sale_record');

        if ($isEditing) {
            // In EDIT mode: Show existing items as read-only table
            // Load the sale record with items
            $saleRecord = SaleRecord::with('saleRecordItems')->find($isEditing);

            $form->html(function () use ($saleRecord) {
                // Ensure model exists and has ID
                if (! $saleRecord || ! $saleRecord->id) {
                    return '<div class="alert alert-info">No items in this sale yet.</div>';
                }

                if ($saleRecord->saleRecordItems && $saleRecord->saleRecordItems->count() > 0) {
                    $html = '<div class="box box-solid box-info">';
                    $html .= '<div class="box-header with-border"><h3 class="box-title">Current Sale Items (Cannot be modified)</h3></div>';
                    $html .= '<div class="box-body table-responsive no-padding">';
                    $html .= '<table class="table table-striped table-hover">';
                    $html .= '<thead><tr>';
                    $html .= '<th>Item Name</th>';
                    $html .= '<th>SKU</th>';
                    $html .= '<th class="text-right">Quantity</th>';
                    $html .= '<th class="text-right">Unit Price</th>';
                    $html .= '<th class="text-right">Subtotal</th>';
                    $html .= '</tr></thead><tbody>';

                    foreach ($saleRecord->saleRecordItems as $item) {
                        $html .= '<tr>';
                        $html .= '<td>'.htmlspecialchars($item->item_name).'</td>';
                        $html .= '<td>'.htmlspecialchars($item->item_sku).'</td>';
                        $html .= '<td class="text-right">'.number_format($item->quantity, 2).'</td>';
                        $html .= '<td class="text-right">'.Money::symbol().' '.number_format($item->unit_price, 0).'</td>';
                        $html .= '<td class="text-right"><strong>'.Money::symbol().' '.number_format($item->subtotal, 0).'</strong></td>';
                        $html .= '</tr>';
                    }

                    $html .= '</tbody></table></div></div>';
                    $html .= '<div class="alert alert-warning"><i class="fa fa-warning"></i> <strong>Note:</strong> Existing items cannot be modified. To change items, please delete this sale and create a new one.</div>';

                    return $html;
                }

                return '<div class="alert alert-info">No items found in this sale.</div>';
            }, 'Existing Items');

            // Prevent editing existing items by not showing the hasMany field
            // User can only view existing items above

        } else {
            // In CREATE mode: Allow adding items normally
            $form->hasMany('saleRecordItems', 'Items', function (Form\NestedForm $form) use ($u) {
                // Optimized query using DB facade for better performance
                $stockItems = \DB::table('stock_items as si')
                    ->leftJoin('stock_categories as sc', 'si.stock_category_id', '=', 'sc.id')
                    ->select(
                        'si.id',
                        'si.name',
                        'si.sku',
                        'si.current_quantity',
                        'si.selling_price',
                        'sc.name as category_name'
                    )
                    ->where('si.company_id', $u->company_id)
                    ->where('si.current_quantity', '>', 0)
                    ->orderBy('sc.name', 'asc')
                    ->orderBy('si.name', 'asc')
                    ->get()
                    ->mapWithKeys(function ($item) {
                        $category = $item->category_name ? '['.$item->category_name.'] ' : '';
                        $sku = $item->sku ? ' ('.$item->sku.')' : '';
                        $stock = ' | Stock: '.number_format($item->current_quantity, 2);
                        $price = ' | Price: '.Money::symbol().' '.number_format($item->selling_price, 0);

                        return [$item->id => $category.$item->name.$sku.$stock.$price];
                    });

                $form->select('stock_item_id', __('Stock Item'))
                    ->options($stockItems)
                    ->rules('required')
                    ->creationRules('required')
                    ->help('Select an item with available stock');

                $form->decimal('quantity', __('Quantity'))
                    ->default(1)
                    ->rules('required|numeric|min:0.01')
                    ->help('Enter quantity to sell');

                $form->decimal('unit_price', __('Unit Price'))
                    ->rules('nullable|numeric|min:0')
                    ->help('Leave empty to use default selling price');
            });
        }

        $form->divider('Payment Information');

        $form->select('payment_method', __('Payment Method'))
            ->options([
                'Cash' => 'Cash',
                'Credit Card' => 'Credit Card',
                'Bank Transfer' => 'Bank Transfer',
                'Mobile Money' => 'Mobile Money',
            ])
            ->default('Cash')
            ->rules('required');

        $form->decimal('amount_paid', __('Amount Paid'))
            ->default(0)
            ->rules('required|numeric|min:0')
            ->help('Amount received from customer');

        $form->select('status', __('Status'))
            ->options([
                'Completed' => 'Completed',
                'Pending' => 'Pending',
                'Cancelled' => 'Cancelled',
            ])
            ->default('Completed')
            ->rules('required');

        $form->textarea('notes', __('Notes'))
            ->rows(3)
            ->rules('nullable');

        // Pre-validation before saving (create mode only: items are immutable after checkout).
        $form->saving(function (Form $form) use ($u) {
            if ($form->model()->id) {
                // Edit mode: customer/notes edits; an increased amount_paid records a payment via the model hook.
                return;
            }

            $items = is_array($form->saleRecordItems) ? array_filter($form->saleRecordItems, fn ($i) => ! empty($i['stock_item_id']) && ! isset($i[Form::REMOVE_FLAG_NAME])) : [];
            if (count($items) === 0) {
                admin_error('No items', 'Please add at least one item to the sale.');

                return back()->withInput();
            }

            $stockItems = DB::table('stock_items')
                ->select('id', 'name', 'current_quantity', 'allow_negative_stock')
                ->where('company_id', $u->company_id)
                ->whereIn('id', array_column($items, 'stock_item_id'))
                ->get()->keyBy('id');

            $errors = [];
            foreach ($items as $index => $item) {
                $stockItem = $stockItems->get($item['stock_item_id']);
                if (! $stockItem) {
                    $errors[] = 'Item #'.($index + 1).': Invalid stock item selected.';

                    continue;
                }
                $quantity = (float) ($item['quantity'] ?? 0);
                if ($quantity <= 0) {
                    $errors[] = "{$stockItem->name}: Quantity must be greater than zero.";
                }
                if (! $stockItem->allow_negative_stock && (float) $stockItem->current_quantity < $quantity) {
                    $errors[] = "{$stockItem->name}: Insufficient stock. Available: ".number_format((float) $stockItem->current_quantity, 2).', Requested: '.number_format($quantity, 2);
                }
            }
            if (! empty($errors)) {
                admin_error('Stock validation failed', implode('<br>', $errors));

                return back()->withInput();
            }
        });

        // Post-processing: stock, totals, numbering, payments and ledger through SaleService.
        $form->saved(function (Form $form) {
            $saleRecord = $form->model();

            if (! $saleRecord->wasRecentlyCreated) {
                admin_success('Sale Updated', 'Sale record updated successfully (items unchanged).');

                return;
            }

            $result = $saleRecord->processAndCompute();

            if (! $result['success']) {
                // The header/lines were persisted by the form but never processed (no stock or ledger effect):
                // remove the unprocessed draft so nothing half-done is left behind.
                DB::table('sale_record_items')->where('sale_record_id', $saleRecord->id)->delete();
                DB::table('sale_records')->where('id', $saleRecord->id)->whereNull('processed_at')->delete();
                admin_error('Sale not recorded', $result['message']);

                return redirect(admin_url('sale-records/create'))->withInput();
            }

            $data = $result['data'];
            $cur = Money::symbol();
            admin_success(
                'Sale Completed Successfully',
                "Receipt: {$data['receipt_number']}<br>".
                "Total: {$cur} ".number_format($data['total_amount'], 2).'<br>'.
                "Paid: {$cur} ".number_format($data['amount_paid'], 2).'<br>'.
                "Balance: {$cur} ".number_format($data['balance'], 2).'<br>'.
                "Items: {$data['items_processed']}<br>".
                "Profit: {$cur} ".number_format($data['total_profit'], 2)
            );
        });

        // Disable editing and viewing after save
        $form->tools(function (Form\Tools $tools) {
            // Keep all tools
        });

        return $form;
    }
}
