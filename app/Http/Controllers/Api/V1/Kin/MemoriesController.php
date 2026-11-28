<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Models\KinMemory;
use App\Services\Kin\KinMemories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Family memories (doc 13.3). Everyone reads; adding needs kin_memories (what each role may add: KinMemories); only the
 * author or the head changes or removes one.
 */
class MemoriesController extends KinController
{
    private const PER_PAGE = 20;

    public function __construct(private KinMemories $memories) {}

    /** GET kin/memories?person=&event=&kind=&page= : newest first, 20 a page. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['person' => ['sometimes', 'nullable', 'integer'], 'event' => ['sometimes', 'nullable', 'integer'],
            'kind' => ['sometimes', 'nullable', Rule::in(KinMemory::KINDS)], 'page' => ['sometimes', 'integer', 'min:1']]);
        $page = max(1, (int) $request->query('page', '1'));
        $q = KinMemory::withoutGlobalScopes()->where('company_id', $this->cid($request))->whereNull('deleted_at')
            ->when($request->query('kind'), fn ($w, $v) => $w->where('kind', $v))
            ->when($request->query('event'), fn ($w, $v) => $w->where('event_id', (int) $v))
            ->when($request->query('person'), fn ($w, $v) => $w->whereIn('id', \Illuminate\Support\Facades\DB::table('kin_memory_people')->select('memory_id')->where('member_id', (int) $v)));
        $rows = $q->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE + 1)->get();
        $more = $rows->count() > self::PER_PAGE;

        return $this->success(['memories' => $this->memories->present($rows->take(self::PER_PAGE)), 'next_page' => $more ? $page + 1 : null], 'Listed successfully.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success($this->memories->present(collect([KinMemories::find($this->cid($request), $id)]), true)[0]));
    }

    /** GET kin/memories/usage */
    public function usage(Request $request): JsonResponse
    {
        return $this->success(KinMemories::usage($this->company($request)));
    }

    /** POST kin/memories (multipart: the fields and files[]) */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(KinMemories::rules(true));
        $files = array_values(array_filter((array) $request->file('files', []), fn ($f) => $f instanceof UploadedFile));

        return $this->attempt(function () use ($request, $d, $files) {
            $m = $this->memories->create($this->company($request), $d, $files, $request->user(), $request->header('X-Device-Id'));

            return $this->created($this->memories->present(collect([$m]), true)[0], 'Memory saved.');
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(KinMemories::rules(false));

        return $this->attempt(function () use ($request, $id, $d) {
            $company = $this->company($request);
            $m = $this->memories->update(KinMemories::find((int) $company->id, $id), $company, $d, $request->user());

            return $this->success($this->memories->present(collect([$m]), true)[0], 'Saved.');
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $company = $this->company($request);
            $this->memories->delete(KinMemories::find((int) $company->id, $id), $company, $request->user());

            return $this->success(null, 'Memory removed.');
        });
    }
}
