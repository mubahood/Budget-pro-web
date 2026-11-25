<?php

/*
| UnityDesk (the unity module), API v1. Required from routes/api.php inside the v1 group, so every path here is /api/v1/unity/….
| Officers with unity_view read; writes need unity_members (members), unity_finance (categories, subscriptions) or
| unity_chapters (chapters), as ApiPermissionMap says. unity/me is open to everyone on the team. Another association's record is a 404.
*/

use App\Http\Controllers\Api\V1\Unity\CategoriesController;
use App\Http\Controllers\Api\V1\Unity\ChaptersController;
use App\Http\Controllers\Api\V1\Unity\MembersController;
use App\Http\Controllers\Api\V1\Unity\SubscriptionsController;
use App\Http\Controllers\Api\V1\Unity\SummaryController;
use Illuminate\Support\Facades\Route;

Route::prefix('unity')->middleware(['auth:sanctum', 'api.tenant', 'api.module:unity', 'api.subscription', 'api.perm', 'api.idempotent'])->group(function () {
    Route::get('summary', [SummaryController::class, 'show']);
    Route::get('me', [MembersController::class, 'me']);

    Route::get('members', [MembersController::class, 'index']);
    Route::post('members', [MembersController::class, 'store']);
    Route::get('members/{id}', [MembersController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], 'members/{id}', [MembersController::class, 'update'])->whereNumber('id');

    foreach (['categories' => CategoriesController::class, 'chapters' => ChaptersController::class] as $path => $controller) {
        Route::get($path, [$controller, 'index']);
        Route::post($path, [$controller, 'store']);
        Route::get($path.'/{id}', [$controller, 'show'])->whereNumber('id');
        Route::match(['put', 'patch'], $path.'/{id}', [$controller, 'update'])->whereNumber('id');
        Route::delete($path.'/{id}', [$controller, 'destroy'])->whereNumber('id');
    }

    Route::get('subscriptions', [SubscriptionsController::class, 'index']);
    Route::post('subscriptions', [SubscriptionsController::class, 'store']);
    Route::match(['put', 'patch'], 'subscriptions/{id}', [SubscriptionsController::class, 'update'])->whereNumber('id');
    Route::post('subscriptions/{id}/cancel', [SubscriptionsController::class, 'cancel'])->whereNumber('id');
});
