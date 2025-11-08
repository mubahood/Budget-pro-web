<?php

namespace App\Admin\Actions\Batch;

use Encore\Admin\Actions\BatchAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class BulkImageUpload extends BatchAction
{
    public $name = '📸 Upload Images';

    public function handle(Collection $collection, Request $request)
    {
        if (!$request->hasFile('images')) {
            return $this->response()->error('No images selected!')->refresh();
        }

        $images = $request->file('images');
        $successCount = 0;
        $errors = [];

        foreach ($collection as $model) {
            // Try to find matching image by SKU or name
            $matchingImage = null;
            
            foreach ($images as $image) {
                $filename = pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME);
                
                // Check if filename matches SKU or product name
                if (
                    strtolower($filename) === strtolower($model->sku) ||
                    strtolower($filename) === strtolower(str_replace(' ', '_', $model->name))
                ) {
                    $matchingImage = $image;
                    break;
                }
            }

            if ($matchingImage) {
                try {
                    // Store image
                    $path = $matchingImage->store('public/images/products');
                    $model->image = str_replace('public/', '', $path);
                    $model->save();
                    $successCount++;
                } catch (\Exception $e) {
                    $errors[] = "Failed to upload image for {$model->name}: {$e->getMessage()}";
                }
            } else {
                $errors[] = "No matching image found for {$model->name} (SKU: {$model->sku})";
            }
        }

        if ($successCount > 0) {
            $message = "Successfully uploaded {$successCount} image(s)!";
            if (count($errors) > 0) {
                $message .= " " . count($errors) . " failed.";
            }
            return $this->response()->success($message)->refresh();
        } else {
            return $this->response()->error('No images were uploaded. ' . implode(', ', $errors))->refresh();
        }
    }

    public function form()
    {
        $this->file('images[]', 'Select Images')
             ->attribute(['multiple' => true, 'accept' => 'image/*'])
             ->help('Tip: Name files as SKU.jpg or Product_Name.jpg for auto-matching');
        
        $this->html('<div class="alert alert-info">
            <strong><i class="fa fa-info-circle"></i> How it works:</strong>
            <ul style="margin: 10px 0 0 20px;">
                <li>Select multiple product images</li>
                <li>Name images by SKU (e.g., <code>PROD-001.jpg</code>) or Product Name (e.g., <code>iPhone_15.jpg</code>)</li>
                <li>Images will automatically match to selected products</li>
                <li>Supported formats: JPG, PNG, GIF, WebP</li>
            </ul>
        </div>');
    }

    public function html()
    {
        return <<<HTML
        <a class="btn btn-sm btn-info bulk-image-upload">
            <i class="fa fa-camera"></i> Upload Images
        </a>
HTML;
    }
}
