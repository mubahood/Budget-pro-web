<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class SetReorderAlert extends RowAction
{
    public $name = '🔔 Reorder Alert';

    public function form()
    {
        $this->text('reorder_level', 'Reorder Level')
            ->rules('required|numeric|min:0')
            ->placeholder('Minimum stock level before alert')
            ->help('You will be notified when stock reaches this level')
            ->attribute(['type' => 'number']);
        
        $this->text('reorder_quantity', 'Reorder Quantity')
            ->rules('required|numeric|min:1')
            ->placeholder('How many units to reorder')
            ->help('Suggested quantity to reorder when stock is low')
            ->attribute(['type' => 'number']);
        
        $this->select('alert_method', 'Alert Method')
            ->options([
                'dashboard' => '📊 Dashboard Widget',
                'email' => '📧 Email Notification',
                'both' => '📊📧 Both',
            ])
            ->default('dashboard');
    }

    public function handle(Model $model, Request $request)
    {
        $reorderLevel = $request->get('reorder_level');
        $reorderQuantity = $request->get('reorder_quantity');
        $alertMethod = $request->get('alert_method');
        
        $model->reorder_level = $reorderLevel;
        $model->reorder_quantity = $reorderQuantity;
        $model->alert_method = $alertMethod;
        $model->save();
        
        // Check if immediate alert needed
        $alertMessage = "✅ Reorder alert set successfully!";
        
        if ($model->current_quantity <= $reorderLevel) {
            $alertMessage .= "<br><br>⚠️ <strong>Warning:</strong> Current stock ({$model->current_quantity}) is at or below reorder level ({$reorderLevel}). Consider reordering {$reorderQuantity} units soon!";
        }
        
        return $this->response()->success($alertMessage)->refresh();
    }
    
    public function html()
    {
        return "<a class='btn btn-sm btn-warning'><i class='fa fa-bell'></i> Reorder Alert</a>";
    }
}
