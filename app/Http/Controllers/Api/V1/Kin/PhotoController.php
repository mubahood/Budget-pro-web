<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Services\Kin\KinPeople;
use App\Services\Kin\KinPhoto;
use App\Services\Kin\KinScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST kin/people/{id}/photo (multipart `photo`): the person's photo, made small on the server. kin_people (ApiPermissionMap). */
class PhotoController extends KinController
{
    public function store(Request $request, int $id): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp']]);

        return $this->attempt(function () use ($request, $id) {
            $person = KinPeople::find($this->cid($request), $id);
            KinScope::guard($request->user(), $this->cid($request), (int) $person->id);
            $person = app(KinPhoto::class)->store($this->company($request), $person, $request->file('photo'), $request->user(), $request->header('X-Device-Id'));

            return $this->success(KinPeople::present($person, true), 'Photo saved.');
        });
    }
}
