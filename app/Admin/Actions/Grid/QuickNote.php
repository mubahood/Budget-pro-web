<?php

namespace App\Admin\Actions\Grid;

use Encore\Admin\Actions\RowAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class QuickNote extends RowAction
{
    public $name = '📝 Quick Note';

    public function form()
    {
        $this->textarea('note_content', 'Note')
            ->rows(4)
            ->rules('required')
            ->placeholder('Add a quick note about this product...');
        
        $this->select('note_type', 'Type')
            ->options([
                'general' => '📌 General Note',
                'quality' => '⭐ Quality Issue',
                'supplier' => '🚚 Supplier Info',
                'customer' => '👤 Customer Feedback',
                'pricing' => '💰 Pricing Note',
                'urgent' => '🚨 Urgent',
            ])
            ->default('general');
        
        $this->checkbox('pin_note', 'Pin this note')->default(0);
    }

    public function handle(Model $model, Request $request)
    {
        $noteContent = $request->get('note_content');
        $noteType = $request->get('note_type');
        $pinNote = $request->get('pin_note');
        
        // Save note to notes field (JSON array) or separate notes table
        $notes = json_decode($model->notes ?? '[]', true);
        
        $newNote = [
            'id' => uniqid(),
            'content' => $noteContent,
            'type' => $noteType,
            'pinned' => (bool) $pinNote,
            'created_by' => admin_toastr()->user()->name ?? 'System',
            'created_at' => now()->toDateTimeString(),
        ];
        
        // Add to beginning if pinned, otherwise to end
        if ($pinNote) {
            array_unshift($notes, $newNote);
        } else {
            $notes[] = $newNote;
        }
        
        $model->notes = json_encode($notes);
        $model->save();
        
        // Show updated notes
        return $this->viewNotes($model);
    }
    
    private function viewNotes(Model $model)
    {
        $notes = json_decode($model->notes ?? '[]', true);
        
        $html = '<div style="max-height: 450px; overflow-y: auto;">';
        
        if (empty($notes)) {
            $html .= '<p style="text-align: center; color: #999; padding: 40px;">No notes yet. Add your first note!</p>';
        } else {
            foreach ($notes as $index => $note) {
                $isPinned = $note['pinned'] ?? false;
                $typeIcons = [
                    'general' => '📌',
                    'quality' => '⭐',
                    'supplier' => '🚚',
                    'customer' => '👤',
                    'pricing' => '💰',
                    'urgent' => '🚨',
                ];
                
                $icon = $typeIcons[$note['type']] ?? '📌';
                $bgColor = $isPinned ? '#fff3cd' : '#f8f9fa';
                $borderColor = $isPinned ? '#ffc107' : '#dee2e6';
                
                $html .= '<div style="margin-bottom: 12px; padding: 12px; background: ' . $bgColor . '; border-left: 4px solid ' . $borderColor . '; border-radius: 4px;">';
                
                $html .= '<div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">';
                $html .= '<div style="font-weight: 600; color: #333;">';
                $html .= $icon . ' ' . ucfirst($note['type']);
                if ($isPinned) {
                    $html .= ' <span style="background: #ffc107; color: #000; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 5px;">PINNED</span>';
                }
                $html .= '</div>';
                $html .= '<div style="font-size: 11px; color: #666;">';
                $html .= date('M d, Y H:i', strtotime($note['created_at']));
                $html .= '</div>';
                $html .= '</div>';
                
                $html .= '<div style="color: #555; line-height: 1.6; margin-bottom: 8px;">';
                $html .= nl2br(e($note['content']));
                $html .= '</div>';
                
                $html .= '<div style="font-size: 12px; color: #888;">';
                $html .= '👤 ' . e($note['created_by']);
                $html .= '</div>';
                
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
        
        $html .= '<div style="margin-top: 15px; padding: 12px; background: #e3f2fd; border-radius: 4px; text-align: center;">';
        $html .= '<strong>Total Notes: ' . count($notes) . '</strong>';
        $html .= '</div>';

        return $this->response()->html($html)->modal([
            'title' => '📝 Notes: ' . $model->name,
            'width' => '700px'
        ]);
    }

    public function dialog()
    {
        $this->confirm('Add a quick note to this product?');
    }
}
