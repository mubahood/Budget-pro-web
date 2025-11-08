<?php

namespace App\Admin\Controllers;

use App\Models\FinancialPeriod;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\User;
use App\Models\Utils;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class StockItemController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Stock Items';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new StockItem());

        $u = Admin::user();
        
        $grid->model()->where('company_id', $u->company_id)
            ->orderBy('created_at', 'desc');

        $grid->filter(function ($filter) use ($u) {
            $filter->disableIdFilter();
            
            $filter->like('name', __('Product Name'));
            $filter->like('sku', __('SKU/Batch Number'));
            $filter->like('barcode', __('Barcode'));

            $filter->equal('stock_category_id', __('Category'))
                ->select(StockCategory::where('company_id', $u->company_id)
                    ->pluck('name', 'id'));

            $filter->equal('stock_sub_category_id', __('Sub Category'))
                ->select(StockSubCategory::where('company_id', $u->company_id)
                    ->pluck('name', 'id'));

            $filter->equal('financial_period_id', __('Financial Period'))
                ->select(FinancialPeriod::where('company_id', $u->company_id)
                    ->pluck('name', 'id'));

            $filter->between('buying_price', __('Buying Price Range'))
                ->decimal();
            $filter->between('selling_price', __('Selling Price Range'))
                ->decimal();
            $filter->between('current_quantity', __('Stock Level Range'))
                ->decimal();
                
            $filter->equal('created_by_id', __('Created By'))
                ->select(User::where('company_id', $u->company_id)
                    ->pluck('name', 'id'));
                    
            $filter->between('created_at', __('Date Range'))
                ->date();
        });
        
        $grid->quickSearch('name', 'sku', 'barcode')->placeholder('Search name, SKU or barcode');
        
        // Enable export with custom filename
        $grid->export(function ($export) {
            $export->filename('Stock_Items_' . date('Y-m-d_H-i-s'));
            $export->except(['image', 'description', 'gallery']);
            $export->originalValue(['buying_price', 'selling_price', 'current_quantity', 'original_quantity']);
        });
        
        // Grid columns
        $grid->column('id', __('ID'))->sortable();

        $grid->column('image', __('Photo'))
            ->lightbox(['width' => 50, 'height' => 50])
            ->width(60);
            
        // Product name - editable, clean display
        $grid->column('name', __('Product Name'))
            ->editable()
            ->sortable();

        // Category - NOT editable (immutable after creation)
        $grid->column('stock_sub_category_id', __('Category'))
            ->display(function ($stock_sub_category_id) {
                $subcat = StockSubCategory::find($stock_sub_category_id);
                return $subcat ? $subcat->name : 'N/A';
            })
            ->sortable();
            
        // SKU - clean display, sortable
        $grid->column('sku', __('SKU/Batch'))
            ->sortable();
            
        // Barcode - clean display, hidden by default
        $grid->column('barcode', __('Barcode'))
            ->sortable()
            ->hide();

        // Buying price - with totals (NOT editable - use Edit form)
        $grid->column('buying_price', __('Cost Price'))
            ->display(function ($buying_price) {
                return number_format((float)$buying_price, 2);
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return "<strong>Total: " . number_format((float)$amount, 2) . "</strong>";
            });

        // Selling price - with totals (NOT editable - use Edit form)
        $grid->column('selling_price', __('Selling Price'))
            ->display(function ($selling_price) {
                return number_format((float)$selling_price, 2);
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return "<strong>Total: " . number_format((float)$amount, 2) . "</strong>";
            });
            
        // Profit margin - computed field, not sortable
        $grid->column('profit_margin', __('Margin %'))
            ->display(function () {
                $buying = (float)$this->buying_price;
                $selling = (float)$this->selling_price;
                if ($buying > 0) {
                    $margin = (($selling - $buying) / $buying) * 100;
                    return number_format($margin, 1) . '%';
                }
                return '0%';
            });
            
        // Original quantity - clean display, hidden by default
        $grid->column('original_quantity', __('Initial Stock'))
            ->display(function ($original_quantity) {
                return number_format($original_quantity, 2);
            })
            ->sortable()
            ->hide();
            
        // Current quantity - NOT editable (only changes via StockRecords)
        $grid->column('current_quantity', __('Quantity'))
            ->display(function ($current_quantity) {
                $quantity = number_format((float)$current_quantity, 2);
                // Add visual indicator for low/out of stock
                if ($current_quantity <= 0) {
                    return "<span class='label label-danger'>{$quantity} (Out of Stock)</span>";
                } elseif ($current_quantity < 10) {
                    return "<span class='label label-warning'>{$quantity} (Low Stock)</span>";
                }
                return $quantity;
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return "<strong>Total: " . number_format((float)$amount, 2) . "</strong>";
            });
            
        // Stock value - computed field (quantity * buying_price), not sortable, no totalRow
        $grid->column('stock_value', __('Stock Value'))
            ->display(function () {
                $quantity = (float)$this->current_quantity;
                $price = (float)$this->buying_price;
                $value = $quantity * $price;
                return number_format($value, 2);
            });

        // Main category - clean display, hidden by default
        $grid->column('stock_category_id', __('Main Category'))
            ->display(function ($stock_category_id) {
                $cat = StockCategory::find($stock_category_id);
                return $cat ? $cat->name : 'N/A';
            })
            ->sortable()
            ->hide();
            
        // Financial period - clean display, hidden by default
        $grid->column('financial_period_id', __('Financial Period'))
            ->display(function ($financial_period_id) {
                $period = FinancialPeriod::find($financial_period_id);
                return $period ? $period->name : 'N/A';
            })
            ->sortable()
            ->hide();

        // Created by - clean display, hidden by default
        $grid->column('created_by_id', __('Created By'))
            ->display(function ($created_by_id) {
                $user = User::find($created_by_id);
                return $user ? $user->name : 'N/A';
            })
            ->sortable()
            ->hide();

        // Created date - formatted, hidden by default
        $grid->column('created_at', __('Date Added'))
            ->display(function ($created_at) {
                return date('d M Y, h:i A', strtotime($created_at));
            })
            ->sortable()
            ->hide();
            
        // Description - hidden by default
        $grid->column('description', __('Description'))
            ->hide();

        // Row Actions - Dropdown menu for cleaner interface
        $grid->actions(function ($actions) {
            // Disable all default actions
            $actions->disableView();
            $actions->disableEdit();
            $actions->disableDelete();
            
            // Create dropdown menu with all actions
            $html = '
            <div class="btn-group">
                <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
                    <i class="fa fa-bars"></i> Actions <span class="caret"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-right" role="menu">
                    <li>
                        <a href="' . admin_url('stock-items/' . $actions->row->id) . '">
                            <i class="fa fa-eye text-primary"></i> View Details
                        </a>
                    </li>
                    <li>
                        <a href="' . admin_url('stock-items/' . $actions->row->id . '/edit') . '">
                            <i class="fa fa-edit text-success"></i> Edit
                        </a>
                    </li>
                    <li>
                        <a href="' . admin_url('stock-items/create?clone=' . $actions->row->id) . '">
                            <i class="fa fa-copy text-warning"></i> Clone
                        </a>
                    </li>
                    <li class="divider"></li>
                    <li>
                        <a href="' . admin_url('stock-records?stock_item_id=' . $actions->row->id) . '" target="_blank">
                            <i class="fa fa-history text-info"></i> Stock Records
                        </a>
                    </li>
                </ul>
            </div>';
            
            // Add default delete action back (it has proper AJAX handling)
            $actions->append($html);
        });

        // Row actions styling
        $grid->setActionClass(\Encore\Admin\Grid\Displayers\Actions::class);

        // Disable batch delete (prevent accidental bulk deletion)
        $grid->disableBatchActions();
        
        // Add helpful tools
        $grid->tools(function ($tools) {
            $tools->append('<div class="btn-group pull-right" style="margin-right: 10px">
                <a href="' . admin_url('stock-records') . '" class="btn btn-sm btn-info" target="_blank">
                    <i class="fa fa-history"></i> View All Stock Records
                </a>
            </div>');
        });

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
        $show = new Show(StockItem::findOrFail($id));

        // Product Information Panel
        $show->panel()
            ->title('Product Information')
            ->style('primary');

        $show->field('id', __('ID'));
        $show->field('name', __('Product Name'));
        $show->field('description', __('Description'));
        $show->field('image', __('Product Image'))->image();
        $show->field('gallery', __('Product Gallery'))->gallery();
        
        // Category Information
        $show->divider();
        $show->field('stock_sub_category_id', __('Sub Category'))
            ->as(function ($stock_sub_category_id) {
                $subcat = StockSubCategory::find($stock_sub_category_id);
                return $subcat ? $subcat->name . ' (' . $subcat->measurement_unit . ')' : 'N/A';
            });
            
        $show->field('stock_category_id', __('Main Category'))
            ->as(function ($stock_category_id) {
                $cat = StockCategory::find($stock_category_id);
                return $cat ? $cat->name : 'N/A';
            });

        // SKU & Barcode
        $show->divider();
        $show->field('sku', __('SKU / Batch Number'));
        $show->field('barcode', __('Barcode'));

        // Pricing Information Panel
        $show->panel()
            ->title('Pricing & Financial Information')
            ->style('success');

        $show->field('buying_price', __('Cost Price (UGX)'))
            ->as(function ($buying_price) {
                return number_format((float)$buying_price, 2);
            });
            
        $show->field('selling_price', __('Selling Price (UGX)'))
            ->as(function ($selling_price) {
                return number_format((float)$selling_price, 2);
            });
            
        $show->field('profit_margin', __('Profit Margin'))
            ->as(function () {
                $buying = (float)$this->buying_price;
                $selling = (float)$this->selling_price;
                if ($buying > 0) {
                    $margin = (($selling - $buying) / $buying) * 100;
                    return number_format($margin, 2) . '%';
                }
                return '0%';
            });

        // Stock Information Panel
        $show->panel()
            ->title('Stock Information')
            ->style('info');

        $show->field('original_quantity', __('Initial Quantity'))
            ->as(function ($original_quantity) {
                return number_format((float)$original_quantity, 2);
            });
            
        $show->field('current_quantity', __('Current Quantity'))
            ->as(function ($current_quantity) {
                return number_format((float)$current_quantity, 2);
            });
            
        $show->field('stock_value', __('Stock Value (UGX)'))
            ->as(function () {
                $quantity = (float)$this->current_quantity;
                $price = (float)$this->buying_price;
                $value = $quantity * $price;
                return number_format($value, 2);
            });

        // System Information Panel
        $show->panel()
            ->title('System Information')
            ->style('default');

        $show->field('financial_period_id', __('Financial Period'))
            ->as(function ($financial_period_id) {
                $period = FinancialPeriod::find($financial_period_id);
                return $period ? $period->name : 'N/A';
            });
            
        $show->field('created_by_id', __('Created By'))
            ->as(function ($created_by_id) {
                $user = User::find($created_by_id);
                return $user ? $user->name : 'N/A';
            });
            
        $show->field('created_at', __('Created At'));
        $show->field('updated_at', __('Updated At'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $u = Admin::user();
        $fiancial_period = Utils::getActiveFinancialPeriod($u->company_id);
        
        if ($fiancial_period == null) {
            return admin_error('Error', 'Please create a financial period first.');
        }

        // Handle clone/duplicate functionality
        $cloneId = request('clone');
        $cloneData = null;
        if ($cloneId) {
            $cloneData = StockItem::find($cloneId);
            if ($cloneData) {
                admin_info('Cloning Product', 'You are creating a copy of "' . $cloneData->name . '". Please review and modify the details as needed.');
            }
        }

        $form = new Form(new StockItem());
        
        $form->hidden('company_id')->default($u->company_id);
        $form->hidden('created_by_id')->default($u->id);
        
        $form->divider('Product Category');
        
        // Category selection - immutable after creation
        if ($form->isEditing()) {
            // When editing, show as disabled field
            $form->display('stock_sub_category_id', __('Stock Category (Cannot be changed)'))
                ->with(function ($value) {
                    $sub_cat = StockSubCategory::find($value);
                    return $sub_cat ? $sub_cat->name_text . " (" . $sub_cat->measurement_unit . ")" : 'N/A';
                });
            $form->hidden('stock_sub_category_id');
            
            $form->html('<div class="alert alert-info">
                <i class="fa fa-info-circle"></i>
                <strong>Note:</strong> Stock category cannot be changed after creation. If you need to change the category, please create a new stock item.
            </div>');
        } else {
            // When creating, allow selection
            $sub_cat_ajax_url = url('api/stock-sub-categories') . '?company_id=' . $u->company_id;
            $form->select('stock_sub_category_id', __('Stock Category'))
                ->ajax($sub_cat_ajax_url)
                ->options(function ($id) use ($cloneData) {
                    // When cloning, prioritize the cloned data
                    if ($cloneData && $cloneData->stock_sub_category_id) {
                        $sub_cat = StockSubCategory::find($cloneData->stock_sub_category_id);
                        if ($sub_cat) {
                            return [$sub_cat->id => $sub_cat->name_text . " (" . $sub_cat->measurement_unit . ")"];
                        }
                    }
                    // Otherwise use the provided ID
                    if ($id) {
                        $sub_cat = StockSubCategory::find($id);
                        if ($sub_cat) {
                            return [$sub_cat->id => $sub_cat->name_text . " (" . $sub_cat->measurement_unit . ")"];
                        }
                    }
                    return [];
                })
                ->default($cloneData ? $cloneData->stock_sub_category_id : null)
                ->rules('required')
                ->required()
                ->help('Select the category and measurement unit for this product. This cannot be changed later.');
        }

        $form->divider('Product Information');

        $form->text('name', __('Product Name'))
            ->default($cloneData ? $cloneData->name . ' (Copy)' : null)
            ->rules('required|max:255')
            ->required()
            ->help('Enter a clear and descriptive product name')
            ->placeholder('e.g., Premium Rice 25kg');

        $form->image('image', __('Product Image'))
            ->uniqueName()
            ->rules('nullable|image|max:2048')
            ->help('Upload product image (max 2MB)');

        $form->multipleImage('gallery', __('Product Gallery'))
            ->removable()
            ->uniqueName()
            ->downloadable()
            ->help('Upload multiple product images');

        $form->textarea('description', __('Product Description'))
            ->default($cloneData ? $cloneData->description : null)
            ->rows(3)
            ->help('Add detailed product description')
            ->placeholder('Enter product details, specifications, etc.');

        $form->divider('SKU & Barcode Management');

        if ($form->isEditing()) {
            $form->radio('update_sku', __('Update SKU/Batch Number'))
                ->options([
                    'Yes' => 'Yes, Update SKU',
                    'No' => 'No, Keep Existing'
                ])
                ->when('Yes', function (Form $form) {
                    $form->text('sku', __('Enter New SKU/Batch Number'))
                        ->rules('required|max:255')
                        ->help('Enter a unique SKU or batch number');
                })
                ->rules('required')
                ->default('No');
        } else {
            $form->hidden('update_sku')->default('No');
            
            $form->radio('generate_sku', __('SKU/Batch Number Generation'))
                ->options([
                    'Auto' => 'Auto-generate SKU',
                    'Manual' => 'Enter Manually'
                ])
                ->when('Manual', function (Form $form) {
                    $form->text('sku', __('Enter SKU/Batch Number'))
                        ->rules('required|max:255')
                         ->help('Enter a unique SKU or batch number');
                })
                ->rules('required')
                ->default('Auto')
                ->help('Choose how to assign SKU/batch number');
        }

        $form->divider('Pricing Information');

        $form->currency('buying_price', __('Cost/Buying Price (UGX)'))
            ->symbol('UGX')
            ->default($cloneData ? $cloneData->buying_price : 0.00)
            ->rules('required|numeric|min:0')
            ->required()
            ->help('Enter the price you paid for this product');

        $form->currency('selling_price', __('Selling Price (UGX)'))
            ->symbol('UGX')
            ->default($cloneData ? $cloneData->selling_price : 0.00)
            ->rules('required|numeric|min:0')
            ->required()
            ->help('Enter the price you will sell this product for');

        $form->html('<div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <strong>Profit Margin:</strong> Will be calculated automatically as: 
            <code>(Selling Price - Buying Price) / Buying Price × 100%</code>
        </div>');

        $form->divider('Stock Quantity');

        if ($form->isEditing()) {
            // When editing, show original quantity as read-only
            $form->display('original_quantity', __('Initial Stock Quantity (Cannot be changed)'))
                ->with(function ($value) {
                    return number_format((float)$value, 2);
                });
            $form->hidden('original_quantity');
            
            // Show current quantity as read-only (managed by StockRecords)
            $form->display('current_quantity', __('Current Stock Quantity'))
                ->with(function ($value) use ($form) {
                    $model = $form->model();
                    $subcat = StockSubCategory::find($model->stock_sub_category_id);
                    $unit = $subcat ? $subcat->measurement_unit : '';
                    $formatted = number_format((float)$value, 2);
                    
                    if ($value <= 0) {
                        return "<span class='label label-danger'>{$formatted} {$unit} (Out of Stock)</span>";
                    } elseif ($value < 10) {
                        return "<span class='label label-warning'>{$formatted} {$unit} (Low Stock)</span>";
                    }
                    return "<span class='label label-success'>{$formatted} {$unit}</span>";
                });
            
            $form->html('<div class="alert alert-info">
                <i class="fa fa-info-circle"></i>
                <strong>Note:</strong> Stock quantities cannot be changed directly. Please use <a href="' . admin_url('stock-records') . '" target="_blank">Stock Records</a> to manage inventory adjustments (sales, purchases, damages, etc.)
            </div>');
        } else {
            // When creating, allow setting initial quantity
            $form->decimal('original_quantity', __('Initial Stock Quantity'))
                ->default($cloneData ? null : 0.00)
                ->rules('required|numeric|min:0')
                ->required()
                ->help($cloneData 
                    ? 'Enter the initial quantity for this NEW product (cloned quantities are NOT copied)' 
                    : 'Enter the initial quantity received (in units from category)')
                ->placeholder('e.g., 100');

            $form->html('<div class="alert alert-' . ($cloneData ? 'info' : 'warning') . '">
                <i class="fa fa-' . ($cloneData ? 'info-circle' : 'warning') . '"></i>
                <strong>Note:</strong> ' . ($cloneData 
                    ? 'Stock quantities are NOT cloned. This is a new product with its own inventory tracking.' 
                    : 'Current quantity will be set to the initial quantity. Use Stock Records for future adjustments.'
                ) . '
            </div>');
        }

        // Form saving hooks
        $form->saving(function (Form $form) {
            // Additional validation before saving
            $buying_price = (float)$form->buying_price;
            $selling_price = (float)$form->selling_price;
            
            if ($buying_price < 0) {
                admin_error('Validation Error', 'Buying price cannot be negative');
                return back()->withInput();
            }
            
            if ($selling_price < 0) {
                admin_error('Validation Error', 'Selling price cannot be negative');
                return back()->withInput();
            }
            
            // Validate SKU during manual entry
            if (!$form->isEditing() && $form->generate_sku == 'Manual') {
                $sku = $form->sku;
                if (empty($sku)) {
                    admin_error('Validation Error', 'SKU is required when manual generation is selected');
                    return back()->withInput();
                }
                
                // Check uniqueness
                $u = Admin::user();
                $exists = StockItem::where('sku', $sku)
                    ->where('company_id', $u->company_id)
                    ->exists();
                    
                if ($exists) {
                    admin_error('Validation Error', "SKU '{$sku}' already exists. Please use a different SKU.");
                    return back()->withInput();
                }
            }
        });

        $form->saved(function (Form $form) {
            $model = $form->model();
            
            if ($form->isCreating()) {
                admin_success('Success', "Stock item '{$model->name}' created successfully! SKU: {$model->sku}");
            } else {
                admin_success('Success', "Stock item '{$model->name}' updated successfully!");
            }
            
            // Redirect to list or detail page
            return redirect(admin_url('stock-items'));
        });

        return $form;
    }
}
