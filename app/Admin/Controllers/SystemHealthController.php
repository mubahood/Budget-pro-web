<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Ops\HealthService;
use Encore\Admin\Layout\Content;
use Illuminate\Support\Facades\DB;

/** Platform admins: errors, queue, scheduler, sync metrics and backups (plan Part D, P4-6). */
class SystemHealthController extends Controller
{
    public function index(Content $content, HealthService $health)
    {
        return $content->title('System health')->body(view('admin.system-health', ['h' => $health->snapshot()]));
    }

    public function resolve($id)
    {
        DB::table('error_events')->where('id', $id)->update(['resolved_at' => now()]);

        return back();
    }
}
