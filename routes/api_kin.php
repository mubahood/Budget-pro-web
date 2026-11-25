<?php

/*
| My Family Roots 360 (the kin module), API v1. Required from routes/api.php inside the v1 group, so every path here is
| /api/v1/kin/…. Everyone in the family reads; writes need kin_people (people, links, marriages) or kin_branches
| (branches), as ApiPermissionMap says. A record of another family is a 404.
*/

use App\Http\Controllers\Api\V1\Kin\BranchesController;
use App\Http\Controllers\Api\V1\Kin\LinksController;
use App\Http\Controllers\Api\V1\Kin\PeopleController;
use App\Http\Controllers\Api\V1\Kin\SiblingsController;
use App\Http\Controllers\Api\V1\Kin\SpousesController;
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
});
