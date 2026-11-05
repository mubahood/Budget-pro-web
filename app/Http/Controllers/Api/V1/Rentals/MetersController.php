<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalMeterReading;
use App\Models\RentalUnit;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Meters;
use App\Services\Rentals\RentalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Water and power meter readings (rentals_maintenance): the units used since the last reading become a bill for the
 * tenant living there. An offline-safe write: the body's `uuid` makes a resend the same reading (200 with the first
 * one); the same uuid with another unit, meter or figure is 409 idempotency_conflict.
 */
class MetersController extends RentalsController
{
    /** GET rentals/meter-readings?property_id&unit_id&utility&from&to */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            RentalAccess::authorize($this->user($request), 'rentals_maintenance');
            $cid = $this->cid($request);
            $units = $this->scoped(RentalUnit::withoutGlobalScopes()->where('company_id', $cid), $request);
            if ($request->filled('property_id')) {
                $units->where('property_id', (int) $request->query('property_id'));
            }
            $q = RentalMeterReading::withoutGlobalScopes()->where('company_id', $cid)->whereIn('unit_id', $units->select('id'));
            if ($request->filled('unit_id')) {
                $q->where('unit_id', (int) $request->query('unit_id'));
            }
            if (array_key_exists((string) $request->query('utility', ''), Meters::UTILITIES)) {
                $q->where('utility', $request->query('utility'));
            }
            if (($from = $this->day($request, 'from')) !== null) {
                $q->where('read_on', '>=', $from);
            }
            if (($to = $this->day($request, 'to')) !== null) {
                $q->where('read_on', '<=', $to);
            }
            $p = $this->paginate($q->orderByDesc('read_on')->orderByDesc('id'), $request, 100);
            $present = $this->present($request)->prime($p->items())->primeFiles(collect($p->items())->pluck('photo_uuid'));

            return $this->page($p, collect($p->items())->map(fn ($r) => $present->meterReading($r))->all());
        });
    }

    /** POST rentals/meter-readings {uuid, unit_id, utility: water|power, reading ("1234.5"), read_on, reset, photo_uuid, notes} */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $cid = $this->cid($request);
            $uuid = $request->input('uuid');
            if ($this->fromApp($request) && ($uuid === null || $uuid === '')) {
                throw BusinessRuleException::make('uuid_required', 'Send the reading\'s id (uuid) so a resend is never counted twice.');
            }
            if ($uuid !== null && $uuid !== '' && (! is_string($uuid) || ! Str::isUuid($uuid))) {
                throw BusinessRuleException::make('uuid_invalid', 'The reading id is not valid.');
            }
            if (($on = $this->day($request, 'read_on')) !== null && ! ($uuid && RentalMeterReading::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->exists())) {
                RentalLedger::assertOpen($cid, $on); // its bill would be dated in a month whose papers have gone out; a resend of a reading already kept is still that reading
            }
            $data = $request->only(['unit_id', 'utility', 'reading', 'read_on', 'reset', 'photo_uuid', 'notes']) + ['uuid' => $uuid ?: null];
            $existing = $uuid ? RentalMeterReading::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first() : null;
            if ($existing !== null) {
                $figure = str_replace([',', ' '], '', trim((string) ($data['reading'] ?? '')));
                if ((int) $existing->unit_id !== (int) ($data['unit_id'] ?? 0) || $existing->utility !== (string) ($data['utility'] ?? 'water')
                    || ! is_numeric($figure) || abs((float) $existing->reading - (float) $figure) > 0.0005) {
                    throw BusinessRuleException::make('idempotency_conflict', 'This reading id was already used for a different reading.');
                }
            }
            $r = app(Meters::class)->record($this->user($request), array_filter($data, fn ($v) => $v !== null));
            $shape = $this->present($request)->meterReading($r);

            return $existing !== null ? $this->success($shape, 'Already recorded.') : $this->created($shape, 'Reading recorded.');
        });
    }
}
