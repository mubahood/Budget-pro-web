<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformOverview;
use Encore\Admin\Layout\Content;

/** Platform admins: every app, account, plan and book on one page (App\Services\Platform\PlatformOverview). */
class PlatformOverviewController extends Controller
{
    public function index(Content $content, PlatformOverview $overview)
    {
        return $content->title('Platform overview')
            ->description('Every app and every account')
            ->body(view('admin.platform-overview', ['o' => $overview->snapshot(), 'apps' => PlatformOverview::APPS, 'only' => request('app')]));
    }
}
