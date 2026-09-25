<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\Shop\DuplicateService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** GET duplicates · POST duplicates/merge { kind, keep_id, merge_ids[] } (plan Appendix E). */
class DuplicateController extends Controller
{
    use ApiResponse;

    public function index(Request $request, DuplicateService $dups)
    {
        return $this->success($dups->find((int) $request->user()->company_id), 'Possible duplicates.');
    }

    public function merge(Request $request, DuplicateService $dups)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(DuplicateService::KINDS))], 'keep_id' => ['required', 'integer'], 'merge_ids' => ['required', 'array', 'min:1'], 'merge_ids.*' => ['integer']]);
        try {
            $n = $dups->merge((int) $request->user()->company_id, $data['kind'], (int) $data['keep_id'], $data['merge_ids']);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->toErrors());
        }

        return $this->success(['merged' => $n], "{$n} record(s) merged.");
    }
}
