<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Secure, tenant-scoped generic CRUD controller.
 *
 * Every resource controller extends this and declares:
 *   - $modelClass    the Eloquent model
 *   - $writable      fields a client may set (explicit allow-list — no mass assignment)
 *   - $searchable    columns matched by the ?q= live-search parameter
 *   - $sortable      columns the client may sort by (?sort=-col,col2)
 *   - $filterable    columns the client may filter by (?filter[col]=v, filter[col][gte]=v ...)
 *   - $listWith / $showWith   eager-loaded relations
 *   - $optionLabel   column (or override optionLabel()) used for dropdown {id,text}
 *
 * Guarantees:
 *   - All reads/writes are scoped to the authenticated user's company_id.
 *   - show/update/destroy 404 for records outside the tenant (no existence leak).
 *   - Writes force company_id and created_by_id; only $writable fields are accepted.
 *   - Model boot hooks run on save() so business logic (rollups, stock, audit) is applied.
 */
abstract class BaseCrudController extends Controller
{
    use ApiResponse;

    /** @var class-string<Model> */
    protected string $modelClass;

    /** Fields a client is allowed to set on create/update. */
    protected array $writable = [];

    /** Columns searched by ?q= */
    protected array $searchable = [];

    /** Columns the client may sort by. */
    protected array $sortable = ['id', 'created_at', 'updated_at'];

    /** Columns the client may filter by (exact match or gte/lte/like operators). */
    protected array $filterable = [];

    /** Relations eager-loaded on list/show. */
    protected array $listWith = [];
    protected array $showWith = [];

    /** Column used as the label in options()/search() dropdown responses. */
    protected string $optionLabel = 'name';

    /** Default sort applied when the client supplies none. */
    protected string $defaultSort = '-id';

    /** Whether destroy() soft-deletes (model must use SoftDeletes) or hard-deletes. */
    protected bool $usesSoftDeletes = false;

    /** Human name for messages, e.g. "Stock item". */
    protected string $resourceName = 'Record';

    /** Per-table column cache to avoid repeated INFORMATION_SCHEMA lookups. */
    protected static array $columnCache = [];

    // ─────────────────────────────────────────────────────────────
    // Overridable hooks
    // ─────────────────────────────────────────────────────────────

    /**
     * Validation rules. $existing is null on create.
     */
    protected function rules(Request $request, ?Model $existing): array
    {
        return [];
    }

    /**
     * Transform a model for output. Override to shape the payload / use a Resource.
     */
    protected function transform(Model $model)
    {
        return $model;
    }

    /**
     * The {id, text} label for an option row. Override for composite labels.
     */
    protected function optionLabel(Model $model): string
    {
        return (string) ($model->{$this->optionLabel} ?? $model->getKey());
    }

    /**
     * Hook called after a successful create/update (e.g. cascade recalculations).
     */
    protected function afterSave(Request $request, Model $model, bool $wasCreated): void
    {
        //
    }

    // ─────────────────────────────────────────────────────────────
    // CRUD actions
    // ─────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $query = $this->scopedQuery($request);

        if (! empty($this->listWith)) {
            $query->with($this->listWith);
        }

        $this->applySearch($request, $query);
        $this->applyFilters($request, $query);
        $this->applySorting($request, $query);

        $perPage = $this->perPage($request);
        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(fn ($m) => $this->transform($m))->all();

        return $this->paginated($paginator, $data, $this->resourceName.'s listed successfully.');
    }

    public function show(Request $request, $id)
    {
        $model = $this->findOwned($request, $id, $this->showWith);

        if ($model === null) {
            return $this->notFound($this->resourceName.' not found.');
        }

        return $this->success($this->transform($model), $this->resourceName.' loaded.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules($request, null));

        /** @var Model $model */
        $model = new $this->modelClass();
        $this->fill($model, $validated, $request);

        $model->save();

        $fresh = $this->findOwned($request, $model->getKey(), $this->showWith) ?? $model;
        $this->afterSave($request, $fresh, true);

        return $this->created($this->transform($fresh), $this->resourceName.' created successfully.');
    }

    public function update(Request $request, $id)
    {
        $model = $this->findOwned($request, $id);

        if ($model === null) {
            return $this->notFound($this->resourceName.' not found.');
        }

        $validated = $request->validate($this->rules($request, $model));
        $this->fill($model, $validated, $request);

        $model->save();

        $fresh = $this->findOwned($request, $model->getKey(), $this->showWith) ?? $model;
        $this->afterSave($request, $fresh, false);

        return $this->success($this->transform($fresh), $this->resourceName.' updated successfully.');
    }

    public function destroy(Request $request, $id)
    {
        $model = $this->findOwned($request, $id);

        if ($model === null) {
            return $this->notFound($this->resourceName.' not found.');
        }

        $model->delete();

        return $this->success(null, $this->resourceName.' deleted successfully.');
    }

    /**
     * Dropdown / select options: [{id, text}], company-scoped, optional ?q= and ?limit=.
     */
    public function options(Request $request)
    {
        $query = $this->scopedQuery($request);

        $q = trim((string) $request->input('q', ''));
        if ($q !== '' && ! empty($this->searchable)) {
            $query->where(function ($sub) use ($q) {
                foreach ($this->searchable as $col) {
                    $sub->orWhere($col, 'like', "%{$q}%");
                }
            });
        }

        $limit = min((int) $request->input('limit', config('saas.options_limit', 50)), 100);
        $rows = $query->orderBy($this->optionLabel)->limit($limit)->get();

        $data = $rows->map(fn (Model $m) => [
            'id' => $m->getKey(),
            'text' => $this->optionLabel($m),
        ])->all();

        return $this->success($data, 'Options loaded.');
    }

    /**
     * Live search / typeahead: richer than options(), returns transformed rows.
     */
    public function search(Request $request)
    {
        $query = $this->scopedQuery($request);

        if (! empty($this->listWith)) {
            $query->with($this->listWith);
        }

        $q = trim((string) $request->input('q', ''));
        if ($q !== '' && ! empty($this->searchable)) {
            $query->where(function ($sub) use ($q) {
                foreach ($this->searchable as $col) {
                    $sub->orWhere($col, 'like', "%{$q}%");
                }
            });
        }

        $limit = min((int) $request->input('limit', config('saas.search_limit', 20)), 50);
        $rows = $query->orderBy($this->optionLabel)->limit($limit)->get();

        $data = $rows->map(fn ($m) => $this->transform($m))->all();

        return $this->success($data, 'Search results.');
    }

    // ─────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────

    protected function companyId(Request $request): int
    {
        return (int) $request->user()->company_id;
    }

    /**
     * Base query, always scoped to the tenant, with global scopes removed so behaviour
     * is deterministic regardless of the auth guard state.
     */
    protected function scopedQuery(Request $request)
    {
        return $this->modelClass::query()
            ->withoutGlobalScopes()
            ->where($this->table().'.company_id', $this->companyId($request));
    }

    protected function findOwned(Request $request, $id, array $with = []): ?Model
    {
        $query = $this->scopedQuery($request);
        if (! empty($with)) {
            $query->with($with);
        }

        return $query->where($this->table().'.id', $id)->first();
    }

    /**
     * Assign only allow-listed fields, then force tenant + creator columns.
     */
    protected function fill(Model $model, array $validated, Request $request): void
    {
        foreach ($this->writable as $field) {
            if (array_key_exists($field, $validated)) {
                $model->{$field} = $validated[$field];
            }
        }

        $columns = $this->columns();

        if (in_array('company_id', $columns, true)) {
            $model->company_id = $this->companyId($request);
        }

        // Only stamp the creator on create, never overwrite it on update.
        if (! $model->exists && in_array('created_by_id', $columns, true) && empty($model->created_by_id)) {
            $model->created_by_id = (int) $request->user()->id;
        }
    }

    protected function applySearch(Request $request, $query): void
    {
        $q = trim((string) $request->input('q', ''));
        if ($q === '' || empty($this->searchable)) {
            return;
        }

        $query->where(function ($sub) use ($q) {
            foreach ($this->searchable as $col) {
                $sub->orWhere($col, 'like', "%{$q}%");
            }
        });
    }

    protected function applyFilters(Request $request, $query): void
    {
        $filters = $request->input('filter', []);
        if (! is_array($filters)) {
            return;
        }

        foreach ($filters as $col => $value) {
            if (! in_array($col, $this->filterable, true)) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $op => $operand) {
                    match ($op) {
                        'gte' => $query->where($col, '>=', $operand),
                        'lte' => $query->where($col, '<=', $operand),
                        'gt' => $query->where($col, '>', $operand),
                        'lt' => $query->where($col, '<', $operand),
                        'ne' => $query->where($col, '!=', $operand),
                        'like' => $query->where($col, 'like', "%{$operand}%"),
                        'in' => $query->whereIn($col, array_filter(explode(',', (string) $operand), fn ($v) => $v !== '')),
                        default => null,
                    };
                }
            } else {
                $query->where($col, $value);
            }
        }
    }

    protected function applySorting(Request $request, $query): void
    {
        $sort = (string) $request->input('sort', $this->defaultSort);
        $applied = false;

        foreach (array_filter(explode(',', $sort)) as $field) {
            $direction = 'asc';
            if (str_starts_with($field, '-')) {
                $direction = 'desc';
                $field = substr($field, 1);
            }
            if (in_array($field, $this->sortable, true)) {
                $query->orderBy($field, $direction);
                $applied = true;
            }
        }

        if (! $applied) {
            $query->orderBy('id', 'desc');
        }
    }

    protected function perPage(Request $request): int
    {
        $default = (int) config('saas.pagination.per_page', 20);
        $max = (int) config('saas.pagination.max_per_page', 100);
        $perPage = (int) $request->input('per_page', $default);

        return max(1, min($perPage, $max));
    }

    protected function table(): string
    {
        return (new $this->modelClass())->getTable();
    }

    protected function columns(): array
    {
        $table = $this->table();
        if (! isset(static::$columnCache[$table])) {
            static::$columnCache[$table] = Schema::getColumnListing($table);
        }

        return static::$columnCache[$table];
    }
}
