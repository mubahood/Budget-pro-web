<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Models\KinSpouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Marriages: a person may have several, to someone in the family (spouse_id) or to a name only (spouse_name). */
class SpousesController extends KinController
{
    /** POST kin/spouses {person_id, spouse_id? | spouse_name?, status?: current|ended|widowed, married_on?, ended_on?, sort_order?, notes?} */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(self::rules(true));

        return $this->attempt(function () use ($request, $d) {
            $s = new KinSpouse($d + ['company_id' => $this->cid($request), 'status' => 'current', 'created_by_id' => $request->user()->id]);
            $s->save();

            return $this->created(self::shape($s), 'Marriage added.');
        });
    }

    /** PUT|PATCH kin/spouses/{id}: the spouse, status, dates, order and notes (not whose marriage it is). */
    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(self::rules(false));

        return $this->attempt(function () use ($request, $id, $d) {
            $s = KinSpouse::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)->findOrFail($id);
            if (array_key_exists('spouse_id', $d) && $d['spouse_id'] !== null) {
                $d['spouse_name'] = null;
            }
            $s->fill($d)->save();

            return $this->success(self::shape($s->fresh()), 'Saved.');
        });
    }

    /** DELETE kin/spouses/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            KinSpouse::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)->findOrFail($id)->delete();

            return $this->success(null, 'Marriage removed.');
        });
    }

    /** @return array<string, array<int, mixed>> */
    private static function rules(bool $creating): array
    {
        return array_filter(['person_id' => $creating ? ['required', 'integer'] : null, 'spouse_id' => ['nullable', 'integer'], 'spouse_name' => ['nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'in:'.implode(',', config('kin.spouse_statuses'))], 'married_on' => ['nullable', 'date'], 'ended_on' => ['nullable', 'date'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:50'], 'notes' => ['nullable', 'string', 'max:255']]);
    }

    /** @return array<string, mixed> */
    public static function shape(KinSpouse $s): array
    {
        return ['id' => (int) $s->id, 'uuid' => $s->uuid, 'person_id' => (int) $s->person_id, 'spouse_id' => $s->spouse_id ? (int) $s->spouse_id : null,
            'spouse_name' => $s->spouse_name, 'status' => $s->status, 'married_on' => $s->married_on?->toDateString(), 'ended_on' => $s->ended_on?->toDateString(),
            'sort_order' => (int) $s->sort_order, 'notes' => $s->notes, 'version' => (int) $s->version];
    }
}
