<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Device;
use App\Services\Billing\Entitlements;
use App\Support\Sync\SyncSequence;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Device registration (plan A.1, P1-2): a stable receipt prefix per device,
 * server time for clock-skew detection and the entitlement snapshot.
 *
 *  POST   devices/register       { device_id, name?, platform?, app_version?, push_token?, push_provider?, app?, locale? }
 *         push_token: the phone's FCM token (null clears it, a missing key leaves it as it is); app: the AppFamily slug.
 *  GET    devices                owner: list company devices
 *  POST   devices/{id}/revoke    owner: stop a lost/stolen till from syncing
 */
class DeviceController extends Controller
{
    use ApiResponse;

    public function register(Request $request)
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'min:8', 'max:36', 'regex:/^[A-Za-z0-9-]+$/'],
            'name' => ['nullable', 'string', 'max:100'],
            'platform' => ['nullable', 'string', 'max:20'],
            'app_version' => ['nullable', 'string', 'max:30'],
            'push_token' => ['nullable', 'string', 'max:255'],
            'push_provider' => ['nullable', 'in:fcm'],
            'app' => ['nullable', 'string', 'max:40'],
            'locale' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,4})?$/'],
        ]);
        $user = $request->user();
        $company = $request->attributes->get('company') ?? Company::find($user->company_id);

        $known = Device::withoutGlobalScopes()->where('company_id', $company->id)->where('device_id', $data['device_id'])->exists();
        if (! $known) {
            try {
                (new \App\Services\Billing\Quotas())->assertCanAdd($company, 'devices');
            } catch (\App\Exceptions\BusinessRuleException $e) {
                return $this->error($e->getMessage().' Or remove an old phone under Devices.', 422, $e->toErrors());
            }
        }
        $device = Device::register((int) $company->id, (int) $user->id, $data['device_id'], $data);
        if ($device->isRevoked()) {
            return $this->error('This device has been revoked by the owner.', 403, ['code' => 'device_revoked']);
        }
        $app = \App\Support\AppFamily::slug($data['app'] ?? null) ?? \App\Support\StoreLink::appOf($request, $company);
        if ($request->exists('push_token') || $app !== $device->app || (isset($data['locale']) && $data['locale'] !== $device->locale)) {
            $access = $user->currentAccessToken();
            $token = $request->exists('push_token') ? ($data['push_token'] ?? null) : $device->push_token;
            $device->setPush($token, $data['push_provider'] ?? null, $app, $data['locale'] ?? null,
                $access instanceof \Laravel\Sanctum\PersonalAccessToken ? (int) $access->id : $device->access_token_id);
        }

        return $this->success([
            'device_id' => $device->device_id,
            'number_prefix' => $device->number_prefix,
            // The highest receipt number this phone already sent: a phone whose counter started again continues after it.
            'number_floors' => ['receipt' => self::lastReceiptNumber((int) $company->id, $device->device_id)],
            'name' => $device->name,
            'push' => $device->push_token !== null,
            'server_time' => SyncSequence::nowMs(),
            'server_seq' => SyncSequence::current(),
            'entitlements' => Entitlements::for($company),
        ], 'Device registered.');
    }

    private static function lastReceiptNumber(int $companyId, string $deviceId): int
    {
        $max = 0;
        \App\Models\SaleRecord::withoutGlobalScopes()->where('company_id', $companyId)->where('device_id', $deviceId)
            ->where('provisional_number', 'like', 'RCP-%')->pluck('provisional_number')
            ->each(function ($n) use (&$max) {
                if (preg_match('/^RCP-[A-Z0-9]+-(\d+)/', (string) $n, $m)) {
                    $max = max($max, (int) $m[1]);
                }
            });

        return $max;
    }

    public function index(Request $request)
    {
        $companyId = (int) $request->user()->company_id;
        $devices = Device::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('prefix_index')->get(['id', 'device_id', 'name', 'platform', 'app_version', 'number_prefix', 'status', 'last_seen_at', 'user_id']);

        return $this->success($devices, 'Devices listed.');
    }

    public function revoke(Request $request, $id)
    {
        $user = $request->user();
        $company = Company::find($user->company_id);
        if ((int) $company->owner_id !== (int) $user->id) {
            return $this->forbidden('Only the company owner can revoke devices.');
        }
        $device = Device::withoutGlobalScopes()->where('company_id', $company->id)->find($id);
        if ($device === null) {
            return $this->notFound('Device not found.');
        }
        $device->status = 'revoked';
        $device->revoked_at = now();
        $device->push_token = null; // a lost phone gets no more pushes
        $device->access_token_id = null;
        $device->save();

        return $this->success($device, 'Device revoked.');
    }
}
