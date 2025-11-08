<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;

class ToggleFavorite extends RowAction
{
    public $name = '⭐ Favorite';

    public function handle(Model $model)
    {
        // Toggle favorite status (assuming you add 'is_favorite' column to stock_items table)
        $model->is_favorite = !($model->is_favorite ?? false);
        $model->save();
        
        $status = $model->is_favorite ? '⭐ Added to' : '❌ Removed from';
        
        return $this->response()->success("{$status} favorites!")->refresh();
    }
    
    public function display($value)
    {
        // Check if product is favorited
        $isFavorite = $this->row->is_favorite ?? false;
        
        if ($isFavorite) {
            return '⭐ Unfavorite';
        }
        
        return '☆ Favorite';
    }
}
