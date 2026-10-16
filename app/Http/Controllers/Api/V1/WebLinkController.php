<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Support\WebLink;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * POST web-link {path} → {url, expires_at, path} (MOBILE_EXCELLENCE_PLAN.md D7): a two-minute, single-use
 * link that signs this person into the web portal at `path` ("/promotions", "/stock/locations"…), for
 * "Open on the web" without typing a password. Any active member; throttled.
 */
class WebLinkController extends Controller
{
    use ApiResponse;

    public function store(Request $request)
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:500']]);
        $user = $request->user();
        if (strtolower((string) $user->status) === 'inactive') {
            return $this->error('This account has been switched off. Ask the shop owner to switch it back on.', 403, ['code' => 'account_inactive']);
        }
        try {
            $link = WebLink::issue($user, $data['path']);
        } catch (BusinessRuleException $e) {
            return $this->error($e->getMessage(), $e->errorCode() === 'web_link_unavailable' ? 503 : 422, $e->toErrors());
        }
        \Illuminate\Support\Facades\Log::info('Web link made for the phone', ['user_id' => $user->id, 'company_id' => $user->company_id, 'path' => $link['path']]);

        return $this->created($link, 'Web link ready. It works once, for two minutes.');
    }
}
