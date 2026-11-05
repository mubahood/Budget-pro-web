<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Rentals\Messages\RentalMessages;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalsModule;
use App\Services\Team\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The account's settings and its first run (docs/estate-ledger/04-architecture.md §3.1, §9). */
class SettingsController extends RentalsController
{
    /** POST rentals/setup {mode, currency, …}: the first run (agency or owner, defaults). */
    public function setup(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $this->wholeAccount($request);

            return $this->success($this->present($request)->settings(app(RentalsModule::class)->setup($this->company($request), $this->user($request), $request->only(RentalsModule::WRITABLE))), 'Set up.');
        });
    }

    /** GET rentals/settings */
    public function show(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $out = $this->present($request)->settings(RentalsModule::settings($this->cid($request)));
            if (RentalAccess::propertyIds($this->user($request)) !== null) {
                foreach (['commission_type', 'commission_bp', 'commission_minor', 'commission_on_items', 'commission_gap_bp', 'withholding_on', 'export_accounts'] as $k) {
                    $out[$k] = null; // the agency's terms with its landlords, not a caretaker's
                }
            }

            return $this->success($out);
        });
    }

    /**
     * GET rentals/team: the account's people, for "who looks after which property" (rentals_settings) and "who does this repair" (rentals_maintenance), as the web reads them for both.
     * A name and a role only: no email, no phone, whoever asks. `GET team` stays for those who manage the team (manage_team).
     */
    public function team(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $by = $this->user($request);
            if (! RentalAccess::can($by, 'rentals_maintenance')) {
                RentalAccess::authorize($by, 'rentals_settings');
            }
            $company = $this->company($request);
            $members = User::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('name')->orderBy('id')->get(['id', 'company_id', 'name', 'first_name', 'last_name', 'status'])
                ->map(function (User $u) use ($company) {
                    $role = Permissions::roleOf($u);

                    return ['id' => (int) $u->id, 'name' => (string) $u->name, 'role' => $role, 'role_label' => (string) config("permissions.roles.{$role}.label"),
                        'active' => strcasecmp((string) $u->status, 'Inactive') !== 0, 'is_owner' => (int) $company->owner_id === (int) $u->id];
                })->filter(fn (array $m) => $m['role'] !== 'none')->values()->all();

            return $this->success($members);
        });
    }

    /** A message's text may fill only its own blanks (RentalMessages::TAGS): any other would reach a tenant with the brace in it. @throws BusinessRuleException */
    private function assertMessages(mixed $messages): void
    {
        foreach (is_array($messages) ? $messages : [] as $kind => $text) {
            if (! is_string($text)) {
                continue; // the module's rules answer that one
            }
            preg_match_all('/\{([a-z_]+)\}/', $text, $m);
            $unknown = array_diff($m[1], RentalMessages::TAGS[$kind] ?? []);
            if ($unknown !== []) {
                throw BusinessRuleException::make('message_blank_unknown', 'This message cannot fill {'.reset($unknown).'}. Use the ones listed under it.', ['kind' => (string) $kind]);
            }
        }
    }

    /** PUT rentals/settings {…the fields to change} */
    public function update(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $this->wholeAccount($request);
            $this->assertMessages($request->input('messages'));

            return $this->success($this->present($request)->settings(app(RentalsModule::class)->saveSettings($this->company($request), $this->user($request), $request->only(RentalsModule::WRITABLE))), 'Saved.');
        });
    }
}
