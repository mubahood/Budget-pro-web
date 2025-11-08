<?php

namespace App\Admin\Controllers;

use App\Models\StockCategory;
use App\Models\StockSubCategory;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Encore\Admin\Layout\Content;
use Illuminate\Support\Str;

class StockSubCategoryController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Stock Sub-Categories';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new StockSubCategory());
        
        // Advanced search
        $grid->quickSearch('name', 'description')->placeholder('Search by name or description...');

        $u = Admin::user();
        $grid->model()
            ->where('company_id', $u->company_id)
            ->orderBy('created_at', 'desc');

        // ID with badge
        $grid->column('id', __('ID'))
            ->sortable();
        
        // Image with preview
        $grid->column('image', __('Image'))
            ->lightbox(['width' => 100, 'height' => 100]);
        
        // Name with inline edit
        $grid->column('name', __('Name'))
            ->editable()
            ->sortable();

        // Category with inline edit dropdown
        $categories = StockCategory::where('company_id', $u->company_id)
            ->pluck('name', 'id');
        
        $grid->column('stock_category_id', __('Category'))
            ->editable('select', $categories)
            ->sortable();

        // Stock level with unit - simplified for inline editing
        $grid->column('current_quantity', __('Stock Level'))
            ->display(function ($current_quantity) {
                return number_format($current_quantity, 2) . ' ' . $this->measurement_unit;
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return "<strong>Total: " . number_format($amount, 2) . "</strong>";
            });

        // Reorder level - editable without HTML
        $grid->column('reorder_level', __('Reorder Level'))
            ->display(function ($reorder_level) {
                return number_format($reorder_level, 2) . ' ' . $this->measurement_unit;
            })
            ->editable()
            ->sortable();

        // Financial metrics - simplified without HTML icons
        $grid->column('buying_price', __('Investment'))
            ->display(function ($buying_price) {
                return number_format($buying_price, 2);
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return "<strong>Total: " . number_format($amount, 2) . "</strong>";
            });

        $grid->column('selling_price', __('Expected Sales'))
            ->display(function ($selling_price) {
                return number_format($selling_price, 2);
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return "<strong>Total: " . number_format($amount, 2) . "</strong>";
            });

        $grid->column('expected_profit', __('Expected Profit'))
            ->display(function ($expected_profit) {
                return number_format($expected_profit, 2);
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return "<strong>Total: " . number_format($amount, 2) . "</strong>";
            });

        $grid->column('earned_profit', __('Earned Profit'))
            ->display(function ($earned_profit) {
                return number_format($earned_profit, 2);
            })
            ->sortable()
            ->totalRow(function ($amount) {
                return "<strong>Total: " . number_format($amount, 2) . "</strong>";
            });

        // Profit margin calculation - computed column (not sortable)
        $grid->column('profit_margin', __('Profit Margin %'))
            ->display(function () {
                if ($this->selling_price > 0) {
                    $margin = (($this->selling_price - $this->buying_price) / $this->selling_price) * 100;
                    return number_format($margin, 1) . "%";
                }
                return 'N/A';
            });

        // Stock availability - editable
        $grid->column('in_stock', __('Availability'))
            ->editable('select', [
                'Yes' => 'In Stock',
                'No' => 'Out of Stock'
            ])
            ->sortable();

        // Status with inline edit - no display() to avoid conflicts
        $grid->column('status', __('Status'))
            ->editable('select', [
                'Active' => 'Active',
                'Inactive' => 'Inactive'
            ])
            ->sortable();

        $grid->column('description', __('Description'))
            ->display(function ($description) {
                return Str::limit($description, 50);
            })
            ->hide();

        // Timestamps
        $grid->column('created_at', __('Created'))
            ->display(function ($created_at) {
                if (!$created_at) return 'N/A';
                // Ensure it's a Carbon instance
                if (is_string($created_at)) {
                    $created_at = \Carbon\Carbon::parse($created_at);
                }
                return $created_at->diffForHumans();
            })
            ->sortable()
            ->hide();

        // Advanced Filters
        $grid->filter(function ($filter) use ($u) {
            $filter->disableIdFilter();
            
            // Category filter
            $filter->equal('stock_category_id', 'Category')->select(
                StockCategory::where('company_id', $u->company_id)
                    ->pluck('name', 'id')
            );
            
            // Stock status filter
            $filter->equal('in_stock', 'Stock Status')->select([
                'Yes' => 'In Stock',
                'No' => 'Out of Stock'
            ]);
            
            // Active status filter
            $filter->equal('status', 'Active Status')->select([
                'Active' => 'Active',
                'Inactive' => 'Inactive'
            ]);
            
            // Price range filters
            $filter->between('buying_price', 'Investment Range')->decimal();
            $filter->between('selling_price', 'Sales Range')->decimal();
            $filter->between('expected_profit', 'Expected Profit Range')->decimal();
            $filter->between('earned_profit', 'Earned Profit Range')->decimal();
            
            // Quantity filters
            $filter->between('current_quantity', 'Stock Quantity Range')->decimal();
            $filter->where(function ($query) {
                $query->whereColumn('current_quantity', '<=', 'reorder_level');
            }, 'Low Stock Only')->checkbox('1');
            
            // Profit margin filter
            $filter->where(function ($query) {
                $query->whereRaw('((selling_price - buying_price) / NULLIF(selling_price, 0)) * 100 >= ?', [30]);
            }, 'High Margin (≥30%)')->checkbox('1');
            
            // Date filters
            $filter->between('created_at', 'Created Date')->datetime();
            $filter->between('updated_at', 'Updated Date')->datetime();
        });

        // Batch Actions
        $grid->batchActions(function ($batch) {
            // Activate batch
            $batch->add(new \App\Admin\Actions\BatchActivate());
            
            // Deactivate batch
            $batch->add(new \App\Admin\Actions\BatchDeactivate());
            
            // Clone/Duplicate batch action
            $batch->add(new \App\Admin\Actions\BatchClone('Stock Sub-Category'));
        });

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
                        <a href="' . admin_url('stock-sub-categories/' . $actions->row->id) . '">
                            <i class="fa fa-eye text-primary"></i> View Details
                        </a>
                    </li>
                    <li>
                        <a href="' . admin_url('stock-sub-categories/' . $actions->row->id . '/edit') . '">
                            <i class="fa fa-edit text-success"></i> Edit
                        </a>
                    </li>
                    <li>
                        <a href="' . admin_url('stock-sub-categories/create?clone=' . $actions->row->id) . '">
                            <i class="fa fa-copy text-warning"></i> Clone
                        </a>
                    </li>
                    <li class="divider"></li>
                    <li>
                        <a href="' . admin_url('stock-items?stock_sub_category_id=' . $actions->row->id) . '" target="_blank">
                            <i class="fa fa-boxes text-info"></i> View Stock Items
                        </a>
                    </li>
                </ul>
            </div>';
            
            // Add default delete action back (it has proper AJAX handling)
            $actions->append($html);
        });

        // Export
        $grid->export(function ($export) {
            $export->filename('Stock_Sub_Categories_' . date('Y-m-d'));
            $export->except(['image']);
            $export->column('category', function ($model) {
                return $model->stockCategory ? $model->stockCategory->name : 'N/A';
            });
        });

        // Quick create button
        $grid->tools(function ($tools) {
            $tools->append('<a href="' . admin_url('stock-sub-categories/create') . '" class="btn btn-sm btn-success">
                <i class="fa fa-plus"></i> Quick Add
            </a>');
        });

        // Disable create button (we have quick add)
        $grid->disableCreateButton();

        // Row actions styling
        $grid->setActionClass(\Encore\Admin\Grid\Displayers\Actions::class);

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
        $show = new Show(StockSubCategory::findOrFail($id));

        // Basic Information
        $show->divider('Basic Information');
        
        $show->field('id', __('ID'))->badge();
        
        $show->field('image', __('Image'))->image('', 100, 100);
        
        $show->field('name', __('Sub-Category Name'))->as(function ($name) {
            return "<strong>{$name}</strong>";
        });
        
        $show->field('stockCategory.name', __('Parent Category'))->badge('primary');
        
        $show->field('measurement_unit', __('Unit of Measurement'))->badge('info');
        
        $show->field('description', __('Description'))->unescape();
        
        // Stock Information
        $show->divider('Stock Information');
        
        $show->field('current_quantity', __('Current Stock'))
            ->as(function ($current_quantity) {
                return number_format($current_quantity, 2) . ' ' . $this->measurement_unit;
            })->badge('primary');
        
        $show->field('reorder_level', __('Reorder Level'))
            ->as(function ($reorder_level) {
                return number_format($reorder_level, 2) . ' ' . $this->measurement_unit;
            })->badge('warning');
        
        $show->field('in_stock', __('Availability Status'))
            ->using([
                'Yes' => 'In Stock',
                'No' => 'Out of Stock'
            ])
            ->dot([
                'Yes' => 'success',
                'No' => 'danger'
            ]);
        
        // Financial Information
        $show->divider('Financial Information');
        
        $show->field('buying_price', __('Total Investment'))
            ->as(function ($buying_price) {
                return '<strong class="text-info">$' . number_format($buying_price, 2) . '</strong>';
            })->unescape();
        
        $show->field('selling_price', __('Expected Sales Value'))
            ->as(function ($selling_price) {
                return '<strong class="text-success">$' . number_format($selling_price, 2) . '</strong>';
            })->unescape();
        
        $show->field('expected_profit', __('Expected Profit'))
            ->as(function ($expected_profit) {
                $color = $expected_profit >= 0 ? 'success' : 'danger';
                return "<strong class='text-{$color}'>$" . number_format($expected_profit, 2) . '</strong>';
            })->unescape();
        
        $show->field('earned_profit', __('Actual Profit Earned'))
            ->as(function ($earned_profit) {
                $color = $earned_profit >= 0 ? 'success' : 'danger';
                return "<strong class='text-{$color}'>$" . number_format($earned_profit, 2) . '</strong>';
            })->unescape();
        
        // Calculated metrics
        $show->field('profit_margin', __('Profit Margin'))
            ->as(function () {
                if ($this->selling_price > 0) {
                    $margin = (($this->selling_price - $this->buying_price) / $this->selling_price) * 100;
                    $color = $margin >= 30 ? 'success' : ($margin >= 15 ? 'warning' : 'danger');
                    return "<span class='badge badge-{$color}'>" . number_format($margin, 2) . '%</span>';
                }
                return 'N/A';
            })->unescape();
        
        // Timestamps
        $show->divider('Record Information');
        
        $show->field('created_at', __('Created At'))->as(function ($created_at) {
            if (!$created_at) return 'N/A';
            // Ensure it's a Carbon instance
            if (is_string($created_at)) {
                $created_at = \Carbon\Carbon::parse($created_at);
            }
            return $created_at->format('d M Y, h:i A') . ' (' . $created_at->diffForHumans() . ')';
        });
        
        $show->field('updated_at', __('Last Updated'))->as(function ($updated_at) {
            if (!$updated_at) return 'N/A';
            // Ensure it's a Carbon instance
            if (is_string($updated_at)) {
                $updated_at = \Carbon\Carbon::parse($updated_at);
            }
            return $updated_at->format('d M Y, h:i A') . ' (' . $updated_at->diffForHumans() . ')';
        });
        
        // Quick actions
        $show->field('quick_actions', __('Quick Actions'))
            ->as(function () {
                return '
                    <a href="' . admin_url('stock-items?stock_sub_category_id=' . $this->id) . '" class="btn btn-primary btn-sm">
                        <i class="fa fa-boxes"></i> View Stock Items
                    </a>
                    <a href="' . admin_url('stock-sub-categories/' . $this->id . '/edit') . '" class="btn btn-success btn-sm">
                        <i class="fa fa-edit"></i> Edit
                    </a>
                ';
            })->unescape();

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new StockSubCategory());

        $u = Admin::user();
        
        // Check if we're cloning an existing record
        $cloneId = request('clone');
        $cloneData = null;
        if ($cloneId) {
            $cloneData = StockSubCategory::find($cloneId);
        }
        
        // Hidden company ID
        $form->hidden('company_id', __('Company id'))
            ->default($u->company_id);

        // Basic Information Section
        $form->divider('Sub-Category Details');
        
        // Category selection with load
        $categories = StockCategory::where([
            'company_id' => $u->company_id,
            'status' => 'Active'
        ])->orderBy('name', 'asc')->pluck('name', 'id');

        $form->select('stock_category_id', __('Parent Category'))
            ->options($categories)
            ->rules('required')
            ->default($cloneData ? $cloneData->stock_category_id : null)
            ->help('Select the main category this sub-category belongs to');

        // Name with character counter - add (Copy) suffix if cloning
        $form->text('name', __('Sub-Category Name'))
            ->rules('required|max:255')
            ->creationRules(['unique:stock_sub_categories,name,NULL,id,company_id,' . $u->company_id])
            ->updateRules(['unique:stock_sub_categories,name,{{id}},id,company_id,' . $u->company_id])
            ->default($cloneData ? $cloneData->name . ' (Copy)' : null)
            ->help('Enter a unique name for this sub-category')
            ->attribute(['maxlength' => 255]);

        // Description with editor
        $form->textarea('description', __('Description'))
            ->rows(4)
            ->default($cloneData ? $cloneData->description : null)
            ->help('Provide detailed information about this sub-category');

        // Image upload with preview - don't clone image
        $form->image('image', __('Category Image'))
            ->uniqueName()
            ->removable()
            ->help('Upload an image (JPG, PNG, GIF). Recommended size: 500x500px');

        // Inventory Settings Section
        $form->divider('Stock Management');
        
        // Measurement unit - clone from source (radio for better UX)
        $form->radio('measurement_unit', __('Unit of Measurement'))
            ->options([
                'Pieces' => 'Pieces (pcs)',
                'Kilograms' => 'Kilograms (kg)',
                'Grams' => 'Grams (g)',
                'Liters' => 'Liters (L)',
                'Milliliters' => 'Milliliters (ml)',
                'Meters' => 'Meters (m)',
                'Centimeters' => 'Centimeters (cm)',
                'Boxes' => 'Boxes',
                'Cartons' => 'Cartons',
                'Packs' => 'Packs',
                'Sets' => 'Sets',
                'Dozens' => 'Dozens',
                'Units' => 'Units',
            ])
            ->rules('required')
            ->default($cloneData ? $cloneData->measurement_unit : 'Pieces')
            ->help('Select how this item is measured');

        // Reorder level - clone from source
        $form->decimal('reorder_level', __('Reorder Level'))
            ->rules('required|numeric|min:0')
            ->default($cloneData ? $cloneData->reorder_level : 10)
            ->help('System will alert when stock falls below this level')
            ->attribute(['step' => '0.01']);

        // Status - always default to Active for new clones
        $form->radio('status', __('Status'))
            ->options([
                'Active' => 'Active - Available for use',
                'Inactive' => 'Inactive - Hidden from selection'
            ])
            ->default('Active')
            ->rules('required')
            ->help('Only active sub-categories will be visible in dropdowns');

        // Calculated Fields Section (Read-only)
        $form->divider('Calculated Information (Auto-updated)');
        
        $form->display('buying_price', __('Total Investment'))
            ->help('Automatically calculated from stock items');
        $form->display('selling_price', __('Expected Sales'))
            ->help('Automatically calculated from stock items');
        $form->display('expected_profit', __('Expected Profit'))
            ->help('Automatically calculated: Expected Sales - Total Investment');
        $form->display('earned_profit', __('Earned Profit'))
            ->help('Automatically calculated from actual sales');
        $form->display('current_quantity', __('Current Stock'))
            ->help('Automatically calculated from stock items');

        // Saving callback
        $form->saving(function (Form $form) {
            // Any pre-save logic here
        });

        // Saved callback
        $form->saved(function (Form $form) {
            // Update calculations
            $model = $form->model();
            if ($model && method_exists($model, 'update_self')) {
                $model->update_self();
            }
        });

        // Footer tools
        $form->tools(function (Form\Tools $tools) {
            $tools->append('<a href="' . admin_url('stock-sub-categories') . '" class="btn btn-sm btn-default">
                <i class="fa fa-list"></i> Back to List
            </a>');
        });

        // Disable view check button
        $form->disableViewCheck();
        
        // Disable continue editing checkbox
        $form->disableEditingCheck();

        return $form;
    }
}
