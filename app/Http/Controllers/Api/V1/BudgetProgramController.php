<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BudgetProgram;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class BudgetProgramController extends BaseCrudController
{
    protected string $modelClass = BudgetProgram::class;
    protected string $resourceName = 'Budget program';

    // Financial totals are rollups computed from children.
    protected array $writable = ['name', 'status', 'deadline', 'rsvp', 'logo', 'title', 'bottom', 'groups', 'is_default', 'is_active'];
    protected array $searchable = ['name', 'title'];
    protected array $sortable = ['id', 'name', 'deadline', 'created_at'];
    protected array $filterable = ['status', 'is_active', 'is_default'];

    protected function rules(Request $request, ?Model $existing): array
    {
        return [
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:191'],
            'status' => ['nullable', 'string', 'max:50'],
            'deadline' => ['nullable', 'date'],
            'rsvp' => ['nullable', 'string', 'max:50'],
            'logo' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:191'],
            'bottom' => ['nullable', 'string', 'max:1000'],
            'groups' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['nullable'],
            'is_active' => ['nullable'],
        ];
    }
}
