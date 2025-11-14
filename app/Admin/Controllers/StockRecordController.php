<?php

namespace App\Admin\Controllers;

use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockRecord;
use App\Models\StockSubCategory;
use App\Models\User;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class StockRecordController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Stock Out Records';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new StockRecord());
        $u = Admin::user();
        
        $grid->model()->where('company_id', $u->company_id)
            ->orderBy('id', 'desc');

        // Filters
        $grid->filter(function ($filter) use ($u) {
            $filter->disableIdFilter();
            
            $filter->like('name', __('Record Name'));
            
            $filter->equal('stock_item_id', __('Stock Item'))
                ->select(StockItem::where('company_id', $u->company_id)
                    ->pluck('name', 'id'));

            $filter->equal('stock_sub_category_id', __('Sub Category'))
                ->select(StockSubCategory::where('company_id', $u->company_id)
                    ->pluck('name', 'id'));

            $filter->equal('stock_category_id', __('Category'))
                ->select(StockCategory::where('company_id', $u->company_id)
                    ->pluck('name', 'id'));
                    
            $filter->equal('type', __('Transaction Type'))
                ->select([
                    'Sale' => 'Sale',
                    'Damage' => 'Damage',
                    'Expired' => 'Expired',
                    'Lost' => 'Lost',
                    'Internal Use' => 'Internal Use',
                    'Other' => 'Other'
                ]);

            $filter->equal('created_by_id', __('Recorded By'))
                ->select(User::where('company_id', $u->company_id)
                    ->pluck('name', 'id'));
                    
            $filter->between('created_at', __('Date Range'))->datetime();
        });

        // Export functionality
        $grid->exporter(function ($export) {
            $export->filename('Stock_Records_' . date('Y-m-d_His'));
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

        $grid->quickSearch('name')->placeholder('Search record name');
        $grid->disableBatchActions();
        
        // Actions - View and Delete only (records are immutable)
        $grid->actions(function ($actions) {
            $actions->disableEdit(); // Stock records cannot be edited (audit trail)
            $actions->disableView(); // We'll use Show page instead
            // Keep delete enabled (restores stock quantity)
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
                if (!$item) return 'N/A';
                
                // Show item name with current stock indicator
                $stockStatus = '';
                if ($item->current_quantity <= 0) {
                    $stockStatus = ' <span class="label label-danger">Out of Stock</span>';
                } elseif ($item->current_quantity < 10) {
                    $stockStatus = ' <span class="label label-warning">Low: ' . number_format($item->current_quantity, 2) . '</span>';
                }
                
                return $item->name . $stockStatus;
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
            ->using([
                'Sale' => 'Sale',
                'Damage' => 'Damage',
                'Expired' => 'Expired',
                'Lost' => 'Lost',
                'Internal Use' => 'Internal Use',
                'Other' => 'Other'
            ])
            ->dot([
                'Sale' => 'success',
                'Damage' => 'danger',
                'Expired' => 'warning',
                'Lost' => 'info',
                'Internal Use' => 'primary',
                'Other' => 'default'
            ])
            ->sortable();
            
        // Quantity - clean display with unit, sortable, with totals
        $grid->column('quantity', __('Quantity'))
            ->display(function ($quantity) {
                $unit = $this->measurement_unit ?? 'units';
                return number_format((float)$quantity, 2) . ' ' . $unit;
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return "<strong>" . number_format((float)$amount, 2) . " units</strong>";
            });
            
        // Unit Price - clean format, NOT editable
        $grid->column('selling_price', __('Unit Price'))
            ->display(function ($selling_price) {
                return number_format((float)$selling_price, 2);
            })
            ->sortable();
            
        // Total Value - clean format with totals, NOT editable
        $grid->column('total_sales', __('Total Value'))
            ->display(function ($total_sales) {
                return number_format((float)$total_sales, 2);
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return "<strong>Total: " . number_format((float)$amount, 2) . "</strong>";
            });
        
        // Profit - show for Sales transactions only
        $grid->column('profit', __('Profit'))
            ->display(function ($profit) {
                if ($this->type !== 'Sale') return '-';
                
                $profitValue = (float)$profit;
                $color = $profitValue >= 0 ? 'success' : 'danger';
                return "<span class='label label-{$color}'>" . number_format($profitValue, 2) . "</span>";
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return "<strong>Total: " . number_format((float)$amount, 2) . "</strong>";
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
                if (!$description) return 'N/A';
                return strlen($description) > 50 ? substr($description, 0, 50) . '...' : $description;
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
     * @param mixed $id
     * @return Show
     */
    protected function detail($id)
    {
        $show = new Show(StockRecord::findOrFail($id));
        
        $show->panel()->tools(function ($tools) {
            $tools->disableEdit(); // Stock records are immutable
        });

        // Transaction Information
        $show->divider('Transaction Information');
        
        $show->field('id', __('Record ID'));
        
        $show->field('type', __('Transaction Type'))
            ->using([
                'Sale' => 'Sale (Revenue)',
                'Damage' => 'Damage (Write-off)',
                'Expired' => 'Expired (Disposal)',
                'Lost' => 'Lost (Missing)',
                'Internal Use' => 'Internal Use (Consumption)',
                'Other' => 'Other'
            ])
            ->label([
                'Sale' => 'success',
                'Damage' => 'danger',
                'Expired' => 'warning',
                'Lost' => 'info',
                'Internal Use' => 'primary',
                'Other' => 'default'
            ]);
        
        $show->field('created_at', __('Transaction Date'))
            ->as(function ($created_at) {
                return date('l, d F Y \a\t h:i A', strtotime($created_at));
            });

        // Stock Item Information
        $show->divider('Stock Item Information');
        
        $show->field('stock_item_id', __('Stock Item'))
            ->as(function ($stock_item_id) {
                $item = StockItem::find($stock_item_id);
                if (!$item) return 'N/A';
                
                return $item->name . ' (Current Stock: ' . number_format($item->current_quantity, 2) . ' ' . $item->stockSubCategory->measurement_unit . ')';
            });
        
        $show->field('sku', __('Item SKU'));
        
        $show->field('stock_sub_category_id', __('Sub Category'))
            ->as(function ($stock_sub_category_id) {
                $subcat = StockSubCategory::find($stock_sub_category_id);
                return $subcat ? $subcat->name_text : 'N/A';
            });

        $show->field('stock_category_id', __('Category'))
            ->as(function ($stock_category_id) {
                $cat = StockCategory::find($stock_category_id);
                return $cat ? $cat->name_text : 'N/A';
            });

        // Quantity & Pricing Details
        $show->divider('Quantity & Pricing Details');
        
        $show->field('quantity', __('Quantity'))
            ->as(function ($quantity) {
                return number_format((float)$quantity, 2) . ' ' . ($this->measurement_unit ?? 'units');
            });
        
        $show->field('measurement_unit', __('Unit of Measurement'));
        
        $show->field('selling_price', __('Unit Selling Price (UGX)'))
            ->as(function ($selling_price) {
                return 'UGX ' . number_format((float)$selling_price, 2);
            });
        
        $show->field('total_sales', __('Total Transaction Value (UGX)'))
            ->as(function ($total_sales) {
                return 'UGX ' . number_format((float)$total_sales, 2);
            });
        
        $show->field('profit', __('Profit/Loss (UGX)'))
            ->as(function ($profit) {
                if ($this->type !== 'Sale') return 'N/A (Not a sale)';
                
                $profitValue = (float)$profit;
                $indicator = $profitValue >= 0 ? '✓ Profit' : '✗ Loss';
                return $indicator . ': UGX ' . number_format(abs($profitValue), 2);
            });

        // Additional Information
        $show->divider('Additional Information');
        
        $show->field('description', __('Description / Remarks'))
            ->as(function ($description) {
                return $description ?: 'No description provided';
            });

        // Audit Trail
        $show->divider('Audit Trail');
        
        $show->field('created_by_id', __('Recorded By'))
            ->as(function ($created_by_id) {
                $user = User::find($created_by_id);
                return $user ? $user->name : 'Unknown User';
            });
        
        $show->field('updated_at', __('Last Modified'))
            ->as(function ($updated_at) {
                return date('d M Y, h:i A', strtotime($updated_at));
            });
        
        // Warning about immutability
        $show->field('_warning', __('Important Notice'))
            ->as(function () {
                return '<div class="alert alert-warning">
                    <i class="fa fa-lock"></i>
                    <strong>Immutable Record:</strong> Stock records cannot be edited to maintain audit trail integrity. 
                    To correct this transaction, delete it (stock will be restored) and create a new record.
                </div>';
            })
            ->unescape();

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
                <br>To correct this transaction, please delete it (stock will be restored automatically) and create a new record.
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

        // CREATION MODE - Full form
        $form->hidden('company_id')->default($u->company_id);
        $form->hidden('created_by_id')->default($u->id);

        $form->html('<div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <strong>Important:</strong> Once created, stock records cannot be edited (audit trail protection). 
            Ensure all details are correct before saving.
        </div>');

        $form->divider('Stock Item Selection');

        $sub_items_ajax_url = url('api/stock-items') . '?company_id=' . $u->company_id;
        $form->select('stock_item_id', __('Stock Item'))
            ->ajax($sub_items_ajax_url)
            ->options(function ($id) {
                $item = StockItem::find($id);
                if ($item) {
                    $stockInfo = ' (Stock: ' . number_format($item->current_quantity, 2) . ' ' . $item->stockSubCategory->measurement_unit . ')';
                    return [$item->id => $item->name . $stockInfo];
                }
                return [];
            })
            ->rules('required')
            ->required()
            ->help('Select the stock item for this transaction. Current stock levels are shown in parentheses.');

        $form->divider('Transaction Details');

        $form->radio('type', __('Transaction Type'))
            ->options([
                'Sale' => 'Sale (Revenue)',
                'Damage' => 'Damage (Write-off)',
                'Expired' => 'Expired (Disposal)',
                'Lost' => 'Lost (Missing)',
                'Internal Use' => 'Internal Use (Consumption)',
                'Other' => 'Other'
            ])
            ->rules('required')
            ->required()
            ->default('Sale')
            ->help('Select the type of stock transaction');

        $form->decimal('quantity', __('Quantity'))
            ->rules('required|numeric|min:0.01')
            ->required()
            ->default(1)
            ->help('Enter the quantity being recorded (must be greater than 0)');

        $form->divider('Pricing Information (For Sales Only)');

        $form->currency('selling_price', __('Unit Selling Price (UGX)'))
            ->symbol('UGX')
            ->rules('nullable|numeric|min:0')
            ->help('Enter the selling price per unit (defaults to item selling price if left empty)');

        $form->html('<div class="alert alert-info">
            <i class="fa fa-calculator"></i>
            <strong>Automatic Calculations:</strong>
            <ul>
                <li>Total Value = Quantity × Unit Price</li>
                <li>Profit = Total Value - (Cost Price × Quantity)</li>
                <li>Stock will be automatically reduced by the quantity entered</li>
            </ul>
        </div>');

        $form->divider('Additional Information');

        $form->textarea('description', __('Description / Remarks'))
            ->rows(3)
            ->help('Add any additional notes or remarks about this transaction')
            ->placeholder('e.g., Customer name, reason for damage, batch number, etc.');

        // Comprehensive validation before saving
        $form->saving(function (Form $form) {
            // Get form data
            $stock_item_id = (int)$form->stock_item_id;
            $quantity = (float)$form->quantity;
            $type = $form->type;
            
            // Validate stock item exists
            $stock_item = StockItem::find($stock_item_id);
            if (!$stock_item) {
                admin_error('Error', 'Selected stock item not found.');
                return back()->withInput();
            }
            
            // Validate quantity
            if ($quantity <= 0) {
                admin_error('Error', 'Quantity must be greater than 0.');
                return back()->withInput();
            }
            
            // Validate sufficient stock
            if ($stock_item->current_quantity < $quantity) {
                $available = number_format($stock_item->current_quantity, 2);
                $requested = number_format($quantity, 2);
                admin_error(
                    'Insufficient Stock', 
                    "Cannot process transaction. Available: {$available}, Requested: {$requested}"
                );
                return back()->withInput();
            }
            
            // Validate transaction type
            $validTypes = ['Sale', 'Damage', 'Expired', 'Lost', 'Internal Use', 'Other'];
            if (!in_array($type, $validTypes)) {
                admin_error('Error', 'Invalid transaction type selected.');
                return back()->withInput();
            }
            
            // Validate selling price if provided
            if ($form->selling_price !== null) {
                $selling_price = (float)$form->selling_price;
                if ($selling_price < 0) {
                    admin_error('Error', 'Selling price cannot be negative.');
                    return back()->withInput();
                }
            }
        });

        $form->saved(function (Form $form) {
            $record = $form->model();
            $type = $record->type;
            $quantity = number_format($record->quantity, 2);
            $item = StockItem::find($record->stock_item_id);
            $itemName = $item ? $item->name : 'Unknown';
            
            admin_success(
                'Success', 
                "Stock record created successfully!<br>Transaction: {$type}<br>Item: {$itemName}<br>Quantity: {$quantity}<br>Stock has been updated automatically."
            );
            
            return redirect(admin_url('stock-records'));
        });

        return $form;
    }
}
