<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformOverview;
use App\Services\Platform\SamePersonTwice;
use Encore\Admin\Layout\Content;

/** Platform admins: logins that share an email or phone (App\Services\Platform\SamePersonTwice). Read only. */
class SamePersonTwiceController extends Controller
{
    public function index(Content $content, SamePersonTwice $people)
    {
        return $content->title('Same person twice')
            ->description('Logins that share an email or a phone number')
            ->body(view('admin.same-person-twice', ['groups' => $people->groups(), 'apps' => PlatformOverview::APPS]));
    }
}
