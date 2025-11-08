<?php

namespace App\Admin\Actions\Batch;

use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class CreateTemplate extends BatchAction
{
    public $name = '📋 Save as Template';

    public function form()
    {
        $this->text('template_name', 'Template Name')
            ->rules('required')
            ->placeholder('e.g., Standard Phone Preset, Beverage Defaults');
        
        $this->textarea('template_description', 'Description')
            ->rows(3)
            ->placeholder('What is this template for?');
        
        $this->checkbox('fields_to_save', 'Fields to Include in Template')
            ->options([
                'category' => 'Category & Subcategory',
                'prices' => 'Buying & Selling Prices',
                'quantities' => 'Stock Quantities',
                'supplier' => 'Supplier Info',
                'tax' => 'Tax Settings',
                'unit' => 'Unit of Measure',
                'warehouse' => 'Warehouse Location',
            ])
            ->default(['category', 'prices', 'unit']);
    }

    public function handle(Collection $collection, Request $request)
    {
        if ($collection->count() !== 1) {
            return $this->response()->error('⚠️ Please select exactly ONE product to create a template from!');
        }
        
        $model = $collection->first();
        $templateName = $request->get('template_name');
        $templateDescription = $request->get('template_description');
        $fieldsToSave = $request->get('fields_to_save', []);
        
        // Build template data
        $templateData = [
            'name' => $model->name,
            'sku' => $model->sku,
        ];
        
        if (in_array('category', $fieldsToSave)) {
            $templateData['stock_category_id'] = $model->stock_category_id;
            $templateData['stock_sub_category_id'] = $model->stock_sub_category_id;
        }
        
        if (in_array('prices', $fieldsToSave)) {
            $templateData['buying_price'] = $model->buying_price;
            $templateData['selling_price'] = $model->selling_price;
        }
        
        if (in_array('quantities', $fieldsToSave)) {
            $templateData['reorder_level'] = $model->reorder_level;
            $templateData['reorder_quantity'] = $model->reorder_quantity;
        }
        
        if (in_array('supplier', $fieldsToSave)) {
            $templateData['supplier_id'] = $model->supplier_id;
        }
        
        if (in_array('tax', $fieldsToSave)) {
            $templateData['tax_method'] = $model->tax_method;
            $templateData['tax_rate'] = $model->tax_rate;
        }
        
        if (in_array('unit', $fieldsToSave)) {
            $templateData['measuring_unit'] = $model->measuring_unit;
        }
        
        if (in_array('warehouse', $fieldsToSave)) {
            $templateData['warehouse_id'] = $model->warehouse_id;
        }
        
        // Save template to database or cache
        $template = new \App\Models\ProductTemplate();
        $template->name = $templateName;
        $template->description = $templateDescription;
        $template->template_data = json_encode($templateData);
        $template->company_id = admin_toastr()->user()->company_id;
        $template->created_by = admin_toastr()->user()->id;
        $template->save();

        return $this->response()->success("✅ Template '{$templateName}' created successfully! Use it when adding new products.")->refresh();
    }
}
