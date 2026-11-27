<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Http\Controllers\Controller;
use App\Services\Unity\CardTokens;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/public/unity/verify/{token}: what a scanned membership card proves. No sign-in, rate-limited. A bad token,
 * another kind of account or a group that turned the page off all answer the same 404, so nothing can be probed.
 */
class PublicVerifyController extends Controller
{
    use ApiResponse;

    public function show(string $token): JsonResponse
    {
        $v = strlen($token) <= 200 ? CardTokens::verify($token) : null;

        return $v === null
            ? $this->error('This card could not be verified.', 404, ['code' => 'not_found'])
            : $this->success($v, 'Verified.');
    }
}
