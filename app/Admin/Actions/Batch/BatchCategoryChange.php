<?php

namespace App\Admin\Actions\Batch;

use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class BatchCategoryChange extends BatchAction
{
    public $name = '🏷️ Change Category';

    public function form()
    {
        $this->select('category_id', 'Select New Category')
            ->options(function () {
                return \App\Models\StockCategory::where('company_id', admin_toastr()->user()->company_id)
                    ->pluck('name', 'id')
                    ->toArray();
            })
            ->rules('required');
        
        $this->select('subcategory_id', 'Select Subcategory (Optional)')
            ->options(function () {
                return \App\Models\StockSubCategory::where('company_id', admin_toastr()->user()->company_id)
                    ->pluck('name', 'id')
                    ->toArray();
            });
        
        $this->textarea('reason', 'Reason for Change')->rows(3);
    }

    public function handle(Collection $collection, Request $request)
    {
        $categoryId = $request->get('category_id');
        $subcategoryId = $request->get('subcategory_id');
        $reason = $request->get('reason');
        
        $category = \App\Models\StockCategory::find($categoryId);
        if (!$category) {
            return $this->response()->error('Category not found!');
        }
        
        $subcategory = $subcategoryId ? \App\Models\StockSubCategory::find($subcategoryId) : null;
        
        $updated = 0;
        foreach ($collection as $model) {
            $oldCategory = $model->stock_category->name ?? 'None';
            $oldSubcategory = $model->stock_sub_category->name ?? 'None';
            
            $model->stock_category_id = $categoryId;
            
            if ($subcategoryId) {
                $model->stock_sub_category_id = $subcategoryId;
            }
            
            $model->save();
            
            // Create audit log
            $stockRecord = new \App\Models\StockRecord();
            $stockRecord->stock_item_id = $model->id;
            $stockRecord->quantity = 0; // No quantity change
            $stockRecord->type = 'Category Change';
            $stockRecord->description = "Category changed from '{$oldCategory}' to '{$category->name}'" . 
                                       ($subcategory ? ", Subcategory: '{$subcategory->name}'" : '') .
                                       ($reason ? ". Reason: {$reason}" : '');
            $stockRecord->created_by = admin_toastr()->user()->id;
            $stockRecord->company_id = admin_toastr()->user()->company_id;
            $stockRecord->save();
            
            $updated++;
        }

        return $this->response()->success("✅ Successfully updated {$updated} product(s) category to '{$category->name}'")->refresh();
    }
}
