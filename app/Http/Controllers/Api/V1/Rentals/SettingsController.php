<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Services\Rentals\RentalsModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The account's settings and its first run (docs/estate-ledger/04-architecture.md §3.1, §9). */
class SettingsController extends RentalsController
{
    /** POST rentals/setup {mode, currency, …}: the first run (agency or owner, defaults). */
    public function setup(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->present($request)->settings(
            app(RentalsModule::class)->setup($this->company($request), $this->user($request), $request->only(RentalsModule::WRITABLE))), 'Set up.'));
    }

    /** GET rentals/settings */
    public function show(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->present($request)->settings(RentalsModule::settings($this->cid($request)))));
    }

    /** PUT rentals/settings {…the fields to change} */
    public function update(Request $request): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->present($request)->settings(
            app(RentalsModule::class)->saveSettings($this->company($request), $this->user($request), $request->only(RentalsModule::WRITABLE))), 'Saved.'));
    }
}
