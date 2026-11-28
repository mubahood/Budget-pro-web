<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Services\Kin\KinScope;
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

        // Who may change whom, and which person the sign-in is (for "Jump to me"), ride beside the tree (not inside it: the ETag
        // is the family's, the same for everyone).
        $me = \App\Services\Sacco\Dues\DuesService::personOf($request->user(), $this->cid($request));

        return $this->success($tree['data'], 'The family tree.', 200, ['access' => KinScope::describe($request->user(), $this->cid($request)),
            'me_id' => $me ? (int) $me->id : null])->header('ETag', $tree['etag'])->header('Cache-Control', 'private, no-cache');
    }
}
