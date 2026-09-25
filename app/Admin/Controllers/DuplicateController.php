<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\Shop\DuplicateService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;

/** Possible duplicates and merging on the web (plan Appendix E). */
class DuplicateController extends Controller
{
    public function index(Content $content, DuplicateService $dups)
    {
        return $content->title('Possible duplicates')->description('Records two phones created for the same thing')
            ->body(view('admin.duplicates', ['groups' => $dups->find((int) Admin::user()->company_id)]));
    }

    public function merge(DuplicateService $dups)
    {
        $ids = array_map('intval', (array) request('ids', []));
        $keep = (int) request('keep_id');
        try {
            $n = $dups->merge((int) Admin::user()->company_id, (string) request('kind'), $keep, array_values(array_diff($ids, [$keep])));
            admin_success('Merged', "{$n} record(s) merged into one.");
        } catch (BusinessRuleException $e) {
            admin_error('Not merged', $e->getMessage());
        }

        return redirect(admin_url('duplicates'));
    }
}
