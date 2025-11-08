# Encore Admin Actions Reference

**Date:** November 7, 2025  
**Issue Fixed:** `Call to undefined method Encore\Admin\Grid\Displayers\Actions::add()`

## Understanding Encore Admin Actions

### 1. Batch Actions (Multiple Row Selection)
**Location:** `$grid->batchActions()`  
**Method:** `add()`  
**Extends:** `Encore\Admin\Actions\BatchAction`

```php
$grid->batchActions(function ($batch) {
    // ✅ CORRECT - Use add() for batch actions
    $batch->add(new \App\Admin\Actions\BatchActivate());
    $batch->add(new \App\Admin\Actions\BatchDeactivate());
    $batch->add(new \App\Admin\Actions\BatchClone('Entity Name'));
});
```

**Batch Action Class Structure:**
```php
class BatchActivate extends BatchAction
{
    public $name = 'Activate Selected';
    
    public function handle(Collection $collection)
    {
        foreach ($collection as $model) {
            $model->status = 'Active';
            $model->save();
        }
        return $this->response()->success('Activated!')->refresh();
    }
}
```

---

### 2. Row Actions (Single Row Operations)
**Location:** `$grid->actions()`  
**Methods:** `prepend()`, `append()` (NOT `add()`)  
**Extends:** `Encore\Admin\Actions\RowAction`

```php
$grid->actions(function ($actions) {
    // ✅ CORRECT - Use prepend() or append() for row actions
    $actions->prepend(new \App\Admin\Actions\CloneAction());
    
    // ❌ WRONG - This will cause error!
    // $actions->add(new \App\Admin\Actions\CloneAction());
    
    // Custom HTML links also use append()
    $actions->append('<a href="..." class="btn btn-xs btn-info">
        <i class="fa fa-link"></i> Link Text
    </a>');
});
```

**Row Action Class Structure:**
```php
class CloneAction extends RowAction
{
    public $name = 'Clone';
    
    public function handle(Model $model)
    {
        // Clone the model
        $newModel = $model->replicate();
        $newModel->name .= ' (Copy)';
        $newModel->save();
        
        return $this->response()->success('Cloned!')->refresh();
    }
    
    public function dialog()
    {
        $this->confirm('Are you sure you want to clone this item?');
    }
}
```

---

## Key Differences

| Feature | Batch Actions | Row Actions |
|---------|--------------|-------------|
| **Extends** | `BatchAction` | `RowAction` |
| **Add Method** | `$batch->add()` ✅ | `$actions->prepend()` ✅ |
| **Handle Parameter** | `Collection $collection` | `Model $model` |
| **Location** | `$grid->batchActions()` | `$grid->actions()` |
| **Icon** | Checkbox selection | Button on each row |

---

## Common Methods

### Available for Both Types:

**Response Methods:**
```php
return $this->response()
    ->success('Success message')
    ->refresh();  // Refresh the grid

return $this->response()
    ->error('Error message');

return $this->response()
    ->redirect(admin_url('path'));

return $this->response()
    ->download('path/to/file.pdf');
```

**Dialog/Confirmation:**
```php
public function dialog()
{
    $this->confirm('Confirmation message');
}
```

**Custom HTML (RowAction only):**
```php
public function html()
{
    return '<a class="btn btn-sm btn-warning"><i class="fa fa-copy"></i> Clone</a>';
}
```

---

## Position Control for Row Actions

```php
$grid->actions(function ($actions) {
    // Remove default actions
    $actions->disableDelete();
    $actions->disableEdit();
    $actions->disableView();
    
    // Add custom actions in specific positions
    $actions->prepend(new FirstAction());    // Shows BEFORE default actions
    $actions->append(new LastAction());      // Shows AFTER default actions
    
    // Multiple custom actions
    $actions->prepend(new Action1());
    $actions->prepend(new Action2());  // Action2 will be leftmost
    $actions->append(new Action3());
    $actions->append(new Action4());   // Action4 will be rightmost
    
    // Final order: Action2, Action1, [View], [Edit], [Delete], Action3, Action4
});
```

---

## Error We Fixed

**Original Code (WRONG):**
```php
$grid->actions(function ($actions) {
    $actions->add(new \App\Admin\Actions\CloneAction());  // ❌ ERROR!
});
```

**Error Message:**
```
Call to undefined method Encore\Admin\Grid\Displayers\Actions::add()
```

**Fixed Code (CORRECT):**
```php
$grid->actions(function ($actions) {
    $actions->prepend(new \App\Admin\Actions\CloneAction());  // ✅ WORKS!
});
```

---

## Best Practices

### 1. Use Descriptive Names
```php
public $name = 'Clone Item';  // Good
public $name = 'Clone';       // Acceptable
public $name = 'C';           // Bad
```

### 2. Always Handle Exceptions
```php
public function handle(Model $model)
{
    try {
        DB::beginTransaction();
        // Your logic
        DB::commit();
        return $this->response()->success('Success!')->refresh();
    } catch (\Exception $e) {
        DB::rollBack();
        return $this->response()->error('Error: ' . $e->getMessage());
    }
}
```

### 3. Use Transactions for Data Modification
```php
DB::beginTransaction();
try {
    // Multiple database operations
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    throw $e;
}
```

### 4. Provide User Feedback
```php
// Always return a response
return $this->response()
    ->success('Operation completed successfully')
    ->refresh();  // Refresh to show changes
```

### 5. Confirm Destructive Actions
```php
public function dialog()
{
    $this->confirm(
        'Are you sure?',
        'This action cannot be undone!'
    );
}
```

---

## Complete Working Example

```php
// In Controller
protected function grid()
{
    $grid = new Grid(new YourModel());
    
    // Batch Actions (multi-select)
    $grid->batchActions(function ($batch) {
        $batch->add(new \App\Admin\Actions\BatchActivate());
        $batch->add(new \App\Admin\Actions\BatchDelete());
    });
    
    // Row Actions (per-row buttons)
    $grid->actions(function ($actions) {
        // Custom actions
        $actions->prepend(new \App\Admin\Actions\CloneAction());
        $actions->append(new \App\Admin\Actions\ExportAction());
        
        // Custom HTML buttons
        $actions->append('<a href="' . admin_url('related/' . $actions->row->id) . '" 
            class="btn btn-xs btn-primary">
            <i class="fa fa-link"></i> View Related
        </a>');
        
        // Optionally disable default actions
        // $actions->disableDelete();
    });
    
    return $grid;
}
```

---

## Quick Reference

| Need | Use This |
|------|----------|
| Action on multiple selected rows | `BatchAction` with `$batch->add()` |
| Action on single row | `RowAction` with `$actions->prepend()` |
| Custom button HTML | `$actions->append('<a>...</a>')` |
| Position before default buttons | `prepend()` |
| Position after default buttons | `append()` |
| Hide default buttons | `disableEdit()`, `disableDelete()`, `disableView()` |

---

**Status:** ✅ Fixed - Changed `add()` to `prepend()` for row actions in StockSubCategoryController
