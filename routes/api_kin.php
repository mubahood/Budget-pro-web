<?php

/*
| My Family Roots 360 (the kin module), API v1. Required from routes/api.php inside the v1 group, so every path here is
| /api/v1/kin/…. Everyone in the family reads; writes need kin_people (people, links, marriages, photos), kin_branches
| (branches), kin_dues (contributions), kin_announce (announcements), kin_events (gatherings), kin_memories (memories) or
| kin_settings (the purse: the head), as ApiPermissionMap says. A record of another family is a 404.
*/

use App\Http\Controllers\Api\V1\Kin\AnnouncementsController;
use App\Http\Controllers\Api\V1\Kin\BranchesController;
use App\Http\Controllers\Api\V1\Kin\DuesController;
use App\Http\Controllers\Api\V1\Kin\EventsController;
use App\Http\Controllers\Api\V1\Kin\LinksController;
use App\Http\Controllers\Api\V1\Kin\MemoriesController;
use App\Http\Controllers\Api\V1\Kin\PeopleController;
use App\Http\Controllers\Api\V1\Kin\PhotoController;
use App\Http\Controllers\Api\V1\Kin\PurseController;
use App\Http\Controllers\Api\V1\Kin\SiblingsController;
use App\Http\Controllers\Api\V1\Kin\SpousesController;
use App\Http\Controllers\Api\V1\Kin\StatsController;
use App\Http\Controllers\Api\V1\Kin\TreeController;
use Illuminate\Support\Facades\Route;

Route::prefix('kin')->middleware(['auth:sanctum', 'api.tenant', 'api.module:kin', 'api.subscription', 'api.perm', 'api.idempotent'])->group(function () {
    Route::get('people', [PeopleController::class, 'index']);
    Route::post('people', [PeopleController::class, 'store']);
    Route::get('people/{id}', [PeopleController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'people/{id}', [PeopleController::class, 'update'])->whereNumber('id');

    Route::post('links', [LinksController::class, 'store']);
    Route::delete('links/{id}', [LinksController::class, 'destroy'])->whereNumber('id');
    Route::post('siblings', [SiblingsController::class, 'store']);

    Route::post('spouses', [SpousesController::class, 'store']);
    Route::match(['put', 'patch'], 'spouses/{id}', [SpousesController::class, 'update'])->whereNumber('id');
    Route::delete('spouses/{id}', [SpousesController::class, 'destroy'])->whereNumber('id');

    Route::get('tree', [TreeController::class, 'show']);

    Route::get('branches', [BranchesController::class, 'index']);
    Route::post('branches', [BranchesController::class, 'store']);
    Route::get('branches/{id}', [BranchesController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'branches/{id}', [BranchesController::class, 'update'])->whereNumber('id');
    Route::delete('branches/{id}', [BranchesController::class, 'destroy'])->whereNumber('id');

    Route::post('people/{id}/photo', [PhotoController::class, 'store'])->whereNumber('id');
    Route::get('stats', [StatsController::class, 'show']);

    // Contributions: `mine` and the reversal before {id}.
    Route::get('dues', [DuesController::class, 'index']);
    Route::post('dues', [DuesController::class, 'store']);
    Route::get('dues/mine', [DuesController::class, 'mine']);
    Route::get('dues/momo', [DuesController::class, 'momoStatus']);
    Route::post('dues/momo/setup', [DuesController::class, 'momoSetup']);
    Route::get('dues/momo/{requestId}', [DuesController::class, 'momoShow'])->whereNumber('requestId');
    Route::post('dues/payments/{id}/reverse', [DuesController::class, 'reverse'])->whereNumber('id');
    Route::get('dues/{id}', [DuesController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'dues/{id}', [DuesController::class, 'update'])->whereNumber('id');
    Route::post('dues/{id}/close', [DuesController::class, 'close'])->whereNumber('id');
    Route::post('dues/{id}/payments', [DuesController::class, 'pay'])->whereNumber('id');
    Route::get('dues/{id}/share', [DuesController::class, 'share'])->whereNumber('id');
    Route::post('dues/{id}/pledge', [DuesController::class, 'pledge'])->whereNumber('id');
    Route::post('dues/{id}/momo', [DuesController::class, 'momo'])->whereNumber('id');

    Route::get('purse', [PurseController::class, 'show']);
    Route::post('purse', [PurseController::class, 'store']);
    Route::post('purse/members', [PurseController::class, 'members']);

    Route::get('announcements', [AnnouncementsController::class, 'index']);
    Route::post('announcements', [AnnouncementsController::class, 'store']);
    Route::get('announcements/{id}', [AnnouncementsController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'announcements/{id}', [AnnouncementsController::class, 'update'])->whereNumber('id');
    Route::delete('announcements/{id}', [AnnouncementsController::class, 'destroy'])->whereNumber('id');

    // Gatherings (doc 13.2).
    Route::get('events', [EventsController::class, 'index']);
    Route::post('events', [EventsController::class, 'store']);
    Route::get('events/{id}', [EventsController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'events/{id}', [EventsController::class, 'update'])->whereNumber('id');
    Route::post('events/{id}/rsvp', [EventsController::class, 'rsvp'])->whereNumber('id');
    Route::post('events/{id}/attendance', [EventsController::class, 'attendance'])->whereNumber('id');
    Route::match(['put', 'patch'], 'events/{id}/minutes', [EventsController::class, 'minutes'])->whereNumber('id');
    Route::post('events/{id}/cancel', [EventsController::class, 'cancel'])->whereNumber('id');

    // Memories (doc 13.3): usage before {id}.
    Route::get('memories', [MemoriesController::class, 'index']);
    Route::post('memories', [MemoriesController::class, 'store']);
    Route::get('memories/usage', [MemoriesController::class, 'usage']);
    Route::get('memories/{id}', [MemoriesController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'memories/{id}', [MemoriesController::class, 'update'])->whereNumber('id');
    Route::delete('memories/{id}', [MemoriesController::class, 'destroy'])->whereNumber('id');
});
