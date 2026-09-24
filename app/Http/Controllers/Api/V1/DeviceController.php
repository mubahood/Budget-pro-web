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
 *  POST   devices/register       { device_id, name?, platform?, app_version? }
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
        ]);
        $user = $request->user();
        $company = $request->attributes->get('company') ?? Company::find($user->company_id);

        $device = Device::register((int) $company->id, (int) $user->id, $data['device_id'], $data);
        if ($device->isRevoked()) {
            return $this->error('This device has been revoked by the owner.', 403, ['code' => 'device_revoked']);
        }

        return $this->success([
            'device_id' => $device->device_id,
            'number_prefix' => $device->number_prefix,
            'name' => $device->name,
            'server_time' => SyncSequence::nowMs(),
            'server_seq' => SyncSequence::current(),
            'entitlements' => Entitlements::for($company),
        ], 'Device registered.');
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
        $device->save();

        return $this->success($device, 'Device revoked.');
    }
}
