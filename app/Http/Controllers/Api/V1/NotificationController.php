<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Notifications\Notifier;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** In-app notification centre + per-user preferences (plan C7). */
class NotificationController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $u = $request->user();
        $rows = DB::table('app_notifications')->where('company_id', $u->company_id)
            ->where(fn ($q) => $q->where('user_id', $u->id)->orWhereNull('user_id'))
            ->orderByDesc('id')->limit(100)->get()->map(fn ($r) => (array) $r + ['data' => json_decode((string) $r->data, true)]);

        return $this->success(['items' => $rows, 'unread' => $rows->whereNull('read_at')->count()], 'Notifications.');
    }

    public function markRead(Request $request)
    {
        $data = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']]);
        $u = $request->user();
        DB::table('app_notifications')->where('company_id', $u->company_id)->where(fn ($q) => $q->where('user_id', $u->id)->orWhereNull('user_id'))
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))->whereNull('read_at')->update(['read_at' => now()]);

        return $this->success(null, 'Marked as read.');
    }

    public function preferences(Request $request, Notifier $notifier)
    {
        $out = [];
        foreach (Notifier::TYPES as $key => [$label]) {
            $out[] = ['key' => $key, 'label' => $label] + $notifier->preference((int) $request->user()->id, $key);
        }

        return $this->success($out, 'Preferences.');
    }

    public function updatePreferences(Request $request, Notifier $notifier)
    {
        $data = $request->validate(['preferences' => ['required', 'array'], 'preferences.*.key' => ['required', 'in:'.implode(',', array_keys(Notifier::TYPES))],
            'preferences.*.enabled' => ['required', 'boolean'], 'preferences.*.channels' => ['required', 'array']]);
        foreach ($data['preferences'] as $p) {
            $notifier->setPreference((int) $request->user()->id, $p['key'], (bool) $p['enabled'], $p['channels']);
        }

        return $this->preferences($request, $notifier);
    }
}
