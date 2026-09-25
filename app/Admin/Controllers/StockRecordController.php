<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockRecord;
use App\Models\StockSubCategory;
use App\Models\User;
use App\Services\Shop\StockService;
use App\Support\Money;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class StockRecordController extends TenantAdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Stock movements';

    /** Movement types this screen may create. Sales are made on Sales / POS so they get a receipt and payment. */
    public const FORM_TYPES = [
        'Stock In' => 'Stock in (goods came in without a delivery note)',
        'Adjustment In' => 'Count correction + (found more than recorded)',
        'Adjustment Out' => 'Count correction − (found less than recorded)',
        'Damage' => 'Damaged (write-off)',
        'Expired' => 'Expired (disposal)',
        'Lost' => 'Lost / stolen',
        'Internal Use' => 'Used in the business',
        'Return' => 'Customer return without a receipt (stock in)',
        'Other' => 'Other (stock out)',
    ];

    /** Types that record what the stock cost (inbound goods). */
    private const COSTED_TYPES = ['Stock In', 'Adjustment In'];

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    /** Movements are append-only: delete is refused with guidance. */
    public function destroy($id)
    {
        return response()->json(['status' => false, 'message' => 'Stock movements cannot be deleted. Use Reverse instead — a contra movement is recorded and the audit trail is kept.']);
    }

    public function reverse($id)
    {
        $u = Admin::user();
        $record = StockRecord::withoutGlobalScopes()->where('company_id', $u->company_id)->find($id);
        if ($record === null) {
            abort(404);
        }
        $document = StockRecordController::document($record);
        if ($document !== null) {
            admin_error('This movement cannot be undone here', e($document['advice']).' <a href="'.e($document['url']).'">Open the '.e($document['label']).'</a>.');

            return redirect(admin_url('stock-records/'.$record->id));
        }
        try {
            (new StockService())->reverse($record, request('reason', 'Reversed from admin'), (int) $u->id);
            admin_success('Movement reversed', 'A contra movement was recorded and stock was restored.');
        } catch (BusinessRuleException $e) {
            admin_error('Cannot reverse', $e->getMessage());
        }

        return redirect(admin_url('stock-records'));
    }

    protected function grid()
    {
        $grid = new Grid(new StockRecord());
        $u = Admin::user();

        $grid->model()->where('company_id', $u->company_id)
            ->with('reversal')
            ->orderBy('id', 'desc');

        // Filters
        $grid->filter(function ($filter) use ($u) {
            $filter->disableIdFilter();

            $filter->equal('stock_item_id', __('Product'))
                ->select(StockItem::where('company_id', $u->company_id)->where('is_deleted', 0)->orderBy('name')
                    ->pluck('name', 'id'));

            $filter->equal('stock_sub_category_id', __('Sub Category'))
                ->select(StockSubCategory::where('company_id', $u->company_id)
                    ->pluck('name', 'id'));

            $filter->equal('stock_category_id', __('Category'))
                ->select(StockCategory::where('company_id', $u->company_id)
                    ->pluck('name', 'id'));

            $filter->equal('type', __('Type'))
                ->select(array_combine(StockService::types(), StockService::types()));

            $filter->equal('created_by_id', __('Recorded By'))
                ->select(User::where('company_id', $u->company_id)
                    ->pluck('name', 'id'));

            $filter->between('created_at', __('Date Range'))->datetime();
        });

        // Export functionality
        $grid->exporter(function ($export) {
            $export->filename('Stock_Records_'.date('Y-m-d_His'));
            $export->column('id', 'ID');
            $export->column('created_at', 'Date');
            $export->column('type', 'Type');
            $export->column('stock_item_id', 'Item');
            $export->column('quantity', 'Quantity');
            $export->column('measurement_unit', 'Unit');
            $export->column('selling_price', 'Unit Price');
            $export->column('total_sales', 'Total Value');
            $export->column('profit', 'Profit');
            $export->column('created_by_id', 'Recorded By');
            $export->column('description', 'Description');

            // Use original numeric values for export (not formatted)
            $export->originalValue(['quantity', 'selling_price', 'total_sales', 'profit']);
        });

        // `name` is only filled on newer rows, so search the product itself too.
        $grid->quickSearch(function ($model, $query) {
            $model->where(function ($q) use ($query) {
                $q->where('name', 'like', '%'.$query.'%')
                    ->orWhere('description', 'like', '%'.$query.'%')
                    ->orWhereHas('stockItem', fn ($p) => $p->where('name', 'like', '%'.$query.'%')->orWhere('sku', 'like', '%'.$query.'%')->orWhere('barcode', $query));
            });
        })->placeholder('Search product, SKU or note');
        $grid->disableBatchActions();

        // Actions - View and Delete only (records are immutable)
        $grid->actions(function ($actions) {
            $row = $actions->row;
            if (StockRecordController::reversible($row)) {
                $url = admin_url('stock-records/'.$actions->getKey().'/reverse');
                $actions->append('<a href="javascript:void(0)" class="btn btn-xs btn-warning" onclick="if(confirm(\'Reverse this movement? A contra movement will be recorded.\')){var f=document.createElement(\'form\');f.method=\'POST\';f.action=\''.$url.'\';f.innerHTML=\'<input type=hidden name=_token value=\''.csrf_token().'\'>\';document.body.appendChild(f);f.submit();}"><i class="fa fa-undo"></i> Reverse</a>');
            }
            $actions->disableEdit(); // Stock records cannot be edited (audit trail)
            $actions->disableDelete(); // corrections are reversals
        });

        // Fix action column dropdown display
        $grid->setActionClass(\Encore\Admin\Grid\Displayers\Actions::class);

        // ID column
        $grid->column('id', __('ID'))->sortable();

        // Date column - clean format
        $grid->column('created_at', __('Date'))
            ->display(function ($created_at) {
                return date('d M Y, h:i A', strtotime($created_at));
            })
            ->sortable();

        // Stock Item - use relationship, display-only
        $grid->column('stock_item_id', __('Stock Item'))
            ->display(function ($stock_item_id) {
                $item = StockItem::find($stock_item_id);
                if (! $item) {
                    return 'N/A';
                }

                // Show item name with current stock indicator
                $stockStatus = '';
                if ($item->current_quantity <= 0) {
                    $stockStatus = ' <span class="label label-danger">Out of Stock</span>';
                } elseif ((float) $item->current_quantity <= (float) ($item->min_stock ?? config('saas.low_stock_threshold'))) {
                    $stockStatus = ' <span class="label label-warning">Low: '.number_format($item->current_quantity, 2).'</span>';
                }

                return $item->name.$stockStatus;
            })
            ->sortable();

        // Category - hidden by default
        $grid->column('stock_category_id', __('Category'))
            ->display(function ($stock_category_id) {
                $cat = StockCategory::find($stock_category_id);

                return $cat ? $cat->name_text : 'N/A';
            })
            ->sortable()
            ->hide();

        // Sub Category - with relationship
        $grid->column('stock_sub_category_id', __('Sub Category'))
            ->display(function ($stock_sub_category_id) {
                $subcat = StockSubCategory::find($stock_sub_category_id);

                return $subcat ? $subcat->name_text : 'N/A';
            })
            ->sortable();

        // Transaction Type - color coded with dot
        $grid->column('type', __('Type'))
            ->display(function ($type) {
                $style = StockService::isInbound((string) $type) ? 'success' : ($type === 'Sale' ? 'primary' : 'danger');

                return "<span class='label label-{$style}'>".e((string) $type).'</span>'.($this->is_reversal ? ' <span class="label label-default">undo</span>' : '');
            })
            ->sortable();

        // Quantity - clean display with unit, sortable, with totals
        $grid->column('quantity', __('Quantity'))
            ->display(function ($quantity) {
                $unit = $this->measurement_unit ?? 'units';

                return number_format((float) $quantity, 2).' '.$unit;
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return '<strong>'.number_format((float) $amount, 2).' units</strong>';
            });

        // Unit Price - clean format, NOT editable
        $grid->column('selling_price', __('Unit Price'))
            ->display(function ($selling_price) {
                return number_format((float) $selling_price, 2);
            })
            ->sortable();

        // Total Value - clean format with totals, NOT editable
        $grid->column('total_sales', __('Total Value'))
            ->display(function ($total_sales) {
                return number_format((float) $total_sales, 2);
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return '<strong>Total: '.number_format((float) $amount, 2).'</strong>';
            });

        // Profit - show for Sales transactions only
        $grid->column('profit', __('Profit'))
            ->display(function ($profit) {
                if ($this->type !== 'Sale') {
                    return '-';
                }

                $profitValue = (float) $profit;
                $color = $profitValue >= 0 ? 'success' : 'danger';

                return "<span class='label label-{$color}'>".number_format($profitValue, 2).'</span>';
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return '<strong>Total: '.number_format((float) $amount, 2).'</strong>';
            });

        // Recorded By - user relationship
        $grid->column('created_by_id', __('Recorded By'))
            ->display(function ($created_by_id) {
                $user = User::find($created_by_id);

                return $user ? $user->name : 'N/A';
            })
            ->sortable();

        // Description - truncated, hidden by default
        $grid->column('description', __('Description'))
            ->display(function ($description) {
                if (! $description) {
                    return 'N/A';
                }

                return strlen($description) > 50 ? substr($description, 0, 50).'...' : $description;
            })
            ->hide();

        // SKU and Record Name - hidden
        $grid->column('sku', __('SKU'))->hide();
        $grid->column('name', __('Record Name'))->hide();

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
        $record = StockRecord::findOrFail($id);
        $show = new Show($record);
        $reversal = StockRecord::withoutGlobalScopes()->where('reverses_id', $record->id)->first();

        $show->panel()->tools(function ($tools) {
            $tools->disableEdit(); // Movements are append-only; corrections are reversals.
            $tools->disableDelete();
        });

        $show->field('status', __('Status'))->unescape()->as(function () use ($record, $reversal) {
            if ($record->is_reversal) {
                return '<span class="label label-default">Correction</span> This undoes <a href="'.admin_url('stock-records/'.$record->reverses_id).'">movement #'.$record->reverses_id.'</a>.';
            }
            if ($reversal) {
                return '<span class="label label-warning">Reversed</span> Undone by <a href="'.admin_url('stock-records/'.$reversal->id).'">#'.$reversal->id.'</a> on '.$reversal->created_at->format('d M Y H:i').'.';
            }
            $document = StockRecordController::document($record);
            $button = $document !== null
                ? e($document['advice']).' <a href="'.e($document['url']).'">Open the '.e($document['label']).'</a>.'
                : '<form method="post" action="'.admin_url('stock-records/'.$record->id.'/reverse').'" class="form-inline" style="display:inline" onsubmit="return confirm(\'Undo this movement? Stock will be put back.\')">'.csrf_field()
                    .'<input name="reason" class="form-control input-sm" placeholder="Why? (e.g. recorded by mistake)" style="width:240px"> <button class="btn btn-sm btn-warning"><i class="fa fa-undo"></i> Undo this movement</button></form>';

            return '<span class="label label-success">Active</span> '.$button;
        });

        $show->field('type', __('Type'))->unescape()->as(function ($type) {
            $style = StockService::isInbound((string) $type) ? 'success' : ($type === 'Sale' ? 'primary' : 'danger');

            return "<span class='label label-{$style}'>".e((string) $type).'</span>';
        });
        $show->field('reason', __('Reason'))->as(fn ($reason) => $reason ? ucfirst(str_replace('_', ' ', $reason)) : '—');
        $show->field('stock_item_id', __('Product'))->unescape()->as(function ($stockItemId) {
            $item = StockItem::find($stockItemId);

            return $item ? '<a href="'.admin_url('stock-items/'.$item->id).'">'.e($item->name).'</a> — in stock now: <strong>'.number_format((float) $item->current_quantity, 2).'</strong>' : 'Product deleted';
        });
        $show->field('quantity', __('Quantity'))->as(fn ($q) => number_format((float) $q, 2));
        $show->field('quantity_delta', __('Stock change'))->unescape()->as(function ($d) {
            $d = (float) $d;

            return $d == 0.0 ? '<span class="text-muted">No change</span>' : '<strong class="'.($d > 0 ? 'text-success' : 'text-danger').'">'.($d > 0 ? '+' : '').number_format($d, 2).'</strong>';
        });
        $show->field('selling_price', __('Unit price ('.Money::symbol().')'))->as(fn ($v) => number_format((float) $v));
        $show->field('unit_cost', __('Unit cost ('.Money::symbol().')'))->as(fn ($v) => $v === null ? '—' : number_format((float) $v));
        $show->field('total_sales', __('Value ('.Money::symbol().')'))->as(fn ($v) => number_format((float) $v));
        if ($record->type === 'Sale') {
            $show->field('profit', __('Profit ('.Money::symbol().')'))->unescape()->as(fn ($v) => '<span class="'.((float) $v >= 0 ? 'text-success' : 'text-danger').'">'.number_format((float) $v).'</span>');
        }
        $show->field('description', __('Notes'))->as(fn ($d) => $d ?: '—');
        $show->field('image', __('Photo'))->image();
        $show->field('created_by_id', __('Recorded by'))->as(fn ($uid) => User::find($uid)->name ?? '—');
        $show->field('created_at', __('Recorded at'))->as(fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('D d M Y, H:i') : '—');

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new StockRecord());

        $u = Admin::user();

        // IMMUTABILITY: Stock records cannot be edited (audit trail)
        if ($form->isEditing()) {
            $form->html('<div class="alert alert-danger">
                <i class="fa fa-lock"></i>
                <strong>Warning:</strong> Stock records are IMMUTABLE and cannot be edited to maintain audit trail integrity.
                <br>To correct this transaction, use <strong>Reverse</strong> on the list: a contra movement is recorded and both rows are kept.
            </div>');

            $form->tools(function (Form\Tools $tools) {
                $tools->disableDelete();
                $tools->disableView();
                $tools->disableList();
            });

            // Make all fields display-only
            $record = $form->model()->find(request()->route()->parameter('stock_record'));
            if ($record) {
                $form->display('id', __('Record ID'));
                $form->display('type', __('Transaction Type'));
                $form->display('stock_item_id', __('Stock Item'))
                    ->with(function ($value) {
                        $item = StockItem::find($value);

                        return $item ? $item->name : 'N/A';
                    });
                $form->display('quantity', __('Quantity'));
                $form->display('selling_price', __('Unit Price'));
                $form->display('total_sales', __('Total Value'));
                $form->display('description', __('Description'));
                $form->display('created_at', __('Created At'));
            }

            return $form;
        }

        // CREATION MODE
        $form->hidden('company_id')->default($u->company_id);
        $form->hidden('created_by_id')->default($u->id);

        $form->html('<div class="alert alert-info" style="margin-bottom:0">
            <i class="fa fa-info-circle"></i>
            Use this for stock that changes <strong>outside a sale or delivery</strong>: damage, expiry, losses, own use and count corrections.
            <ul style="margin:6px 0 0 0">
                <li>Selling? Use <a href="'.admin_url('sale-records/create').'">Sales / POS</a> so there is a receipt and the money is recorded.</li>
                <li>A delivery from a supplier? Use <a href="'.admin_url('goods-receipts/create').'">Receive stock</a> so the cost and what you owe are recorded.</li>
                <li>A customer brought back goods from a sale? Open the sale and press <strong>Record return</strong>.</li>
            </ul>
        </div>');

        $form->select('stock_item_id', __('Product'))
            ->config('minimumInputLength', 0)
            ->ajax(admin_url('ajax/stock-items'))
            ->default(request('stock_item_id'))
            ->options(function ($id) use ($u) {
                $item = $id ? StockItem::withoutGlobalScopes()->where('company_id', $u->company_id)->find($id) : null;
                if ($item) {
                    $unit = $item->stockSubCategory?->measurement_unit;

                    return [$item->id => $item->name.' (in stock: '.StockItemController::qty($item->current_quantity).($unit ? ' '.$unit : '').')'];
                }

                return [];
            })
            ->rules('required')
            ->required()
            ->help('Type to search by name, SKU or barcode.');

        $type = request('type');
        $form->radio('type', __('What happened?'))
            ->options(self::FORM_TYPES)
            ->default(array_key_exists((string) $type, self::FORM_TYPES) ? $type : null)
            ->rules('required|in:'.implode(',', array_keys(self::FORM_TYPES)))
            ->required()
            ->stacked()
            ->when('in', self::COSTED_TYPES, function (Form $form) {
                $form->decimal('unit_cost', __('Cost per piece ('.Money::symbol().')'))
                    ->rules('nullable|numeric|min:0')
                    ->help('Leave blank to use the product\'s buying price.');
            })
            ->help('Stock in, count correction + and customer return add stock; the others remove it.');

        $form->decimal('quantity', __('Quantity'))
            ->rules('required|numeric|min:0.001')
            ->required()
            ->help('How many pieces (or kg, litres…) in the product\'s unit.');

        $form->select('reason', __('Reason'))->options(array_combine(\App\Http\Controllers\Api\V1\StockRecordController::REASONS, array_map(fn ($r) => ucfirst(str_replace('_', ' ', $r)), \App\Http\Controllers\Api\V1\StockRecordController::REASONS)));
        $form->textarea('description', __('Notes'))
            ->rows(2)
            ->placeholder('e.g. dropped by the delivery boy, batch 12 expired');
        $form->image('image', __('Photo (optional)'))->move('files/adjustments')->uniqueName();

        $form->saving(function (Form $form) use ($u) {
            $type = (string) $form->type;
            $quantity = (float) $form->quantity;

            if (! array_key_exists($type, self::FORM_TYPES)) {
                admin_error('Choose what happened', $type === 'Sale'
                    ? 'Sales are recorded on Sales / POS so there is a receipt and the money is counted.'
                    : 'Pick one of the options, e.g. Damaged or Stock in.');

                return back()->withInput();
            }

            $stock_item = StockItem::withoutGlobalScopes()->where('company_id', $u->company_id)->find((int) $form->stock_item_id);
            if (! $stock_item) {
                admin_error('Error', 'Selected product not found.');

                return back()->withInput();
            }

            if ($quantity <= 0) {
                admin_error('Error', 'Quantity must be greater than 0.');

                return back()->withInput();
            }

            if (! StockService::isInbound($type) && ! $stock_item->allow_negative_stock && (float) $stock_item->current_quantity < $quantity) {
                admin_error('Not enough stock', 'In stock: '.StockItemController::qty($stock_item->current_quantity).', you entered: '.StockItemController::qty($quantity).'.');

                return back()->withInput();
            }

            // Cost only means something for goods coming in; blank stays null (the product's buying price is used).
            if (! in_array($type, self::COSTED_TYPES, true) || $form->unit_cost === null || $form->unit_cost === '') {
                $form->unit_cost = null;
            } elseif ((float) $form->unit_cost < 0) {
                admin_error('Error', 'Cost cannot be negative.');

                return back()->withInput();
            }
        });

        $form->saved(function (Form $form) {
            $record = $form->model();
            $item = StockItem::find($record->stock_item_id);

            admin_success('Saved', e($record->type).': '.e($item->name ?? 'product').' '.((float) $record->quantity_delta > 0 ? '+' : '').StockItemController::qty($record->quantity_delta).'. In stock now: '.StockItemController::qty($item->current_quantity ?? 0).'.');

            return redirect(admin_url('stock-records'));
        });

        return $form;
    }

    /**
     * The document a movement belongs to (sale, delivery, count…), which is where it must be
     * corrected. Null for a stand-alone movement that may be reversed here.
     *
     * @return array{label: string, url: string, advice: string}|null
     */
    public static function document(StockRecord $record): ?array
    {
        $type = (string) $record->reference_type;
        $refId = (int) $record->reference_id;
        if ($record->sale_record_id || $type === 'sale' || $type === 'sale_return') {
            $saleId = (int) ($record->sale_record_id ?: ($type === 'sale' ? $refId : \Illuminate\Support\Facades\DB::table('sale_returns')->where('id', $refId)->value('sale_record_id')));

            return $type === 'sale_return'
                ? ['label' => 'sale', 'url' => admin_url('sale-records/'.$saleId), 'advice' => 'This stock came back with a return on a sale, which also refunded money. It cannot be undone; record a new movement if the goods left again.']
                : ['label' => 'sale', 'url' => admin_url('sale-records/'.$saleId), 'advice' => 'This movement is part of a sale. To undo it, void the sale or record a return on the sale page, so the money is corrected too.'];
        }

        return match ($type) {
            'goods_receipt' => ['label' => 'delivery', 'url' => admin_url('goods-receipts/'.$refId), 'advice' => 'This stock came in with a delivery. To send goods back, record a return to the supplier so what you owe is corrected too.'],
            'purchase_return' => ['label' => 'return to supplier', 'url' => admin_url('purchase-returns/'.$refId), 'advice' => 'This stock went back to a supplier. It cannot be undone here; receive the goods again if they came back.'],
            'stock_transfer' => ['label' => 'transfers page', 'url' => admin_url('stock-transfers'), 'advice' => 'This stock moved between your locations. Make a transfer back instead.'],
            'stock_take' => ['label' => 'stock count', 'url' => admin_url('stock-takes/'.$refId), 'advice' => 'This figure was set by a stock count. Count the product again to correct it.'],
            \App\Console\Commands\ApplyOldWriteoffs::REFERENCE => ['label' => 'original write-off', 'url' => admin_url('stock-records/'.$refId), 'advice' => 'This is an automatic correction that applied an old write-off to the stock figure. Record a new movement if the figure is wrong.'],
            default => null,
        };
    }

    /** Whether the Reverse button applies: a stand-alone movement that is not itself an undo and not undone yet. */
    public static function reversible(StockRecord $record): bool
    {
        if ($record->is_reversal || StockRecordController::document($record) !== null) {
            return false;
        }

        return $record->relationLoaded('reversal')
            ? $record->reversal === null
            : ! StockRecord::withoutGlobalScopes()->where('reverses_id', $record->id)->exists();
    }
}
