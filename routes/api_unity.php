<?php

/*
| UnityDesk (the unity module), API v1. Required from routes/api.php inside the v1 group, so every path here is /api/v1/unity/….
| Officers with unity_view read; writes need unity_members, unity_finance, unity_chapters, unity_meetings, unity_welfare,
| unity_voice or unity_settings, as ApiPermissionMap says. The self-service paths (access, me, my card and statement, the
| noticeboard, meetings and ballots, RSVP, voting, asking for welfare) are open to everyone on the team, and the controllers
| keep a member to their own record. Another association's record is a 404. The public card check is in routes/api.php.
| Contract: unitydesk/docs/12-modules-contract.md.
*/

use App\Http\Controllers\Api\V1\Unity\CategoriesController;
use App\Http\Controllers\Api\V1\Unity\ChaptersController;
use App\Http\Controllers\Api\V1\Unity\CommitteesController;
use App\Http\Controllers\Api\V1\Unity\DuesController;
use App\Http\Controllers\Api\V1\Unity\HomeController;
use App\Http\Controllers\Api\V1\Unity\MeetingsController;
use App\Http\Controllers\Api\V1\Unity\MemberActionsController;
use App\Http\Controllers\Api\V1\Unity\MembersController;
use App\Http\Controllers\Api\V1\Unity\SubscriptionsController;
use App\Http\Controllers\Api\V1\Unity\SummaryController;
use App\Http\Controllers\Api\V1\Unity\VoiceController;
use App\Http\Controllers\Api\V1\Unity\WelfareController;
use Illuminate\Support\Facades\Route;

Route::prefix('unity')->middleware(['auth:sanctum', 'api.tenant', 'api.module:unity', 'api.subscription', 'api.perm', 'api.idempotent'])->group(function () {
    // Home: who I am here, the numbers, the tree.
    Route::get('access', [HomeController::class, 'access']);
    Route::get('summary', [SummaryController::class, 'show']);
    Route::get('insights', [HomeController::class, 'insights']);
    Route::get('tree', [HomeController::class, 'tree']);
    Route::get('tree/outline', [HomeController::class, 'outline']);
    Route::get('tree/find', [HomeController::class, 'find']);

    // Me (any signed-in member)
    Route::get('me', [MembersController::class, 'me']);
    Route::get('me/card', [MemberActionsController::class, 'myCard']);
    Route::get('me/statement', [MemberActionsController::class, 'myStatement']);

    // Members and their life events
    Route::get('members', [MembersController::class, 'index']);
    Route::post('members', [MembersController::class, 'store']);
    Route::get('members/{id}', [MembersController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'members/{id}', [MembersController::class, 'update'])->whereNumber('id');
    Route::post('members/{id}/photo', [MemberActionsController::class, 'photo'])->whereNumber('id');
    foreach (['approve', 'reject', 'suspend', 'reinstate', 'exit', 'died', 'transfer'] as $action) {
        Route::post("members/{id}/{$action}", [MemberActionsController::class, $action])->whereNumber('id');
    }
    Route::get('members/{id}/history', [MemberActionsController::class, 'history'])->whereNumber('id');
    Route::get('members/{id}/statement', [MemberActionsController::class, 'statement'])->whereNumber('id');
    Route::get('members/{id}/card', [MemberActionsController::class, 'card'])->whereNumber('id');

    // Categories, chapters and regions
    foreach (['categories' => CategoriesController::class, 'chapters' => ChaptersController::class] as $path => $controller) {
        Route::get($path, [$controller, 'index']);
        Route::post($path, [$controller, 'store']);
        Route::get($path.'/{id}', [$controller, 'show'])->whereNumber('id');
        Route::match(['put', 'patch'], $path.'/{id}', [$controller, 'update'])->whereNumber('id');
        Route::delete($path.'/{id}', [$controller, 'destroy'])->whereNumber('id');
    }

    // Committees and officials
    Route::get('committees', [CommitteesController::class, 'index']);
    Route::post('committees', [CommitteesController::class, 'store']);
    Route::get('committees/{id}', [CommitteesController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'committees/{id}', [CommitteesController::class, 'update'])->whereNumber('id');
    Route::delete('committees/{id}', [CommitteesController::class, 'destroy'])->whereNumber('id');
    Route::post('committees/{id}/officials', [CommitteesController::class, 'addOfficial'])->whereNumber('id');
    Route::post('committees/{id}/officials/{memberId}/end', [CommitteesController::class, 'endOfficial'])->whereNumber(['id', 'memberId']);

    // Dues and drives (levies, harambees)
    Route::get('subscriptions', [SubscriptionsController::class, 'index']);
    Route::post('subscriptions', [SubscriptionsController::class, 'store']);
    Route::post('subscriptions/renew', [DuesController::class, 'renew']);
    Route::match(['put', 'patch'], 'subscriptions/{id}', [SubscriptionsController::class, 'update'])->whereNumber('id');
    Route::post('subscriptions/{id}/cancel', [SubscriptionsController::class, 'cancel'])->whereNumber('id');
    Route::get('dues/summary', [DuesController::class, 'summary']);
    Route::get('dues/arrears', [DuesController::class, 'arrears']);
    Route::get('drives', [DuesController::class, 'drives']);
    Route::post('drives', [DuesController::class, 'storeDrive']);
    Route::get('drives/{id}', [DuesController::class, 'drive'])->whereNumber('id');
    Route::match(['put', 'patch'], 'drives/{id}', [DuesController::class, 'updateDrive'])->whereNumber('id');
    Route::post('drives/{id}/close', [DuesController::class, 'closeDrive'])->whereNumber('id');
    Route::post('drives/{id}/apply', [DuesController::class, 'apply'])->whereNumber('id');
    Route::post('drives/{id}/pledges', [DuesController::class, 'pledge'])->whereNumber('id');
    Route::post('drives/{id}/payments', [DuesController::class, 'pay'])->whereNumber('id');

    // Meetings, attendance and resolutions
    Route::get('meetings', [MeetingsController::class, 'index']);
    Route::post('meetings', [MeetingsController::class, 'store']);
    Route::get('meetings/{id}', [MeetingsController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'meetings/{id}', [MeetingsController::class, 'update'])->whereNumber('id');
    Route::post('meetings/{id}/cancel', [MeetingsController::class, 'cancel'])->whereNumber('id');
    Route::get('meetings/{id}/attendance', [MeetingsController::class, 'attendance'])->whereNumber('id');
    Route::post('meetings/{id}/attendance', [MeetingsController::class, 'saveAttendance'])->whereNumber('id');
    Route::post('meetings/{id}/checkin', [MeetingsController::class, 'checkin'])->whereNumber('id');
    Route::post('meetings/{id}/rsvp', [MeetingsController::class, 'rsvp'])->whereNumber('id');
    Route::post('meetings/{id}/minutes', [MeetingsController::class, 'minutes'])->whereNumber('id');
    Route::post('meetings/{id}/resolutions', [MeetingsController::class, 'storeResolution'])->whereNumber('id');
    Route::get('resolutions', [MeetingsController::class, 'resolutions']);
    Route::match(['put', 'patch'], 'resolutions/{id}', [MeetingsController::class, 'updateResolution'])->whereNumber('id');

    // Welfare and the fund
    Route::get('welfare/summary', [WelfareController::class, 'summary']);
    Route::get('welfare/entries', [WelfareController::class, 'entries']);
    Route::post('welfare/entries', [WelfareController::class, 'storeEntry']);
    Route::get('welfare/requests', [WelfareController::class, 'requests']);
    Route::post('welfare/requests', [WelfareController::class, 'storeRequest']);
    Route::post('welfare/requests/{id}/approve', [WelfareController::class, 'approve'])->whereNumber('id');
    Route::post('welfare/requests/{id}/reject', [WelfareController::class, 'reject'])->whereNumber('id');
    Route::post('welfare/requests/{id}/pay', [WelfareController::class, 'pay'])->whereNumber('id');

    // Voice: ballots, the noticeboard, messages
    Route::get('ballots', [VoiceController::class, 'ballots']);
    Route::post('ballots', [VoiceController::class, 'storeBallot']);
    Route::get('ballots/{id}', [VoiceController::class, 'ballot'])->whereNumber('id');
    Route::match(['put', 'patch'], 'ballots/{id}', [VoiceController::class, 'updateBallot'])->whereNumber('id');
    Route::post('ballots/{id}/open', [VoiceController::class, 'openBallot'])->whereNumber('id');
    Route::post('ballots/{id}/close', [VoiceController::class, 'closeBallot'])->whereNumber('id');
    Route::post('ballots/{id}/vote', [VoiceController::class, 'vote'])->whereNumber('id');
    Route::get('announcements', [VoiceController::class, 'announcements']);
    Route::post('announcements', [VoiceController::class, 'storeAnnouncement']);
    Route::match(['put', 'patch'], 'announcements/{id}', [VoiceController::class, 'updateAnnouncement'])->whereNumber('id');
    Route::delete('announcements/{id}', [VoiceController::class, 'destroyAnnouncement'])->whereNumber('id');
    Route::post('messages', [VoiceController::class, 'message']);

    // Settings
    Route::get('settings', [VoiceController::class, 'settings']);
    Route::match(['put', 'patch'], 'settings', [VoiceController::class, 'updateSettings']);
});
