<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Services\Kin\TreeService;
use Illuminate\Http\Request;

/** GET kin/tree: the whole family to draw, with an ETag. Send If-None-Match with it to get a 304 when nothing changed. */
class TreeController extends KinController
{
    public function show(Request $request)
    {
        $tree = TreeService::payload($this->cid($request));
        $sent = array_map('trim', explode(',', (string) $request->header('If-None-Match', '')));
        if (in_array($tree['etag'], $sent, true)) {
            return response('', 304)->header('ETag', $tree['etag'])->header('Cache-Control', 'private, no-cache');
        }

        return $this->success($tree['data'], 'The family tree.')->header('ETag', $tree['etag'])->header('Cache-Control', 'private, no-cache');
    }
}
