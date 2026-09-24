<?php

namespace App\Admin\Controllers;

use Encore\Admin\Controllers\AdminController;

/**
 * Base for every tenant-facing admin resource (P0-2).
 *
 * laravel-admin's Form::destroy() swallows ModelNotFoundException and replies
 * `{status:false, message:"No query results for model [App\Models\X]"}` with
 * HTTP 200 — no data is deleted, but the response leaks the model name and
 * isn't a 404. With CompanyScope live under the admin guard, a row from
 * another tenant simply doesn't exist for this query, so we 404 up front.
 */
abstract class TenantAdminController extends AdminController
{
    public function destroy($id)
    {
        $query = $this->form()->model()->newQuery();

        foreach (array_filter(explode(',', (string) $id)) as $one) {
            if (! (clone $query)->whereKey($one)->exists()) {
                abort(404);
            }
        }

        return parent::destroy($id);
    }
}
