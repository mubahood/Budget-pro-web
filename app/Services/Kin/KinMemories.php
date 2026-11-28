<?php

namespace App\Services\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\KinEvent;
use App\Models\KinMemory;
use App\Models\KinMemoryFile;
use App\Models\SaccoMember;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Billing\Quotas;
use App\Services\Team\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Family memories (doc 13.3): stories, photos, voice notes and documents, tagged with the people they are about. The files
 * are `files` rows on the public disk like every upload (purpose kin_memory, and kin_memory_thumb for a photo's small
 * picture), counted against the plan's storage. Who adds what:
 * - the head and the secretary (kin_memories with every person in reach): anything;
 * - a branch coordinator: anything about the people of their own branches (at least one person named, all theirs);
 * - everyone else with kin_memories (a member, the treasurer): their own stories, words only.
 * Only the author or the head changes or removes one.
 */
class KinMemories
{
    public const MAX_FILES = 10;

    /** kind of file => [extensions, largest size in bytes] */
    public const FILES = [
        'image' => [['jpg', 'jpeg', 'png', 'webp', 'gif'], 10 * 1048576],
        'audio' => [['mp3', 'm4a', 'aac', 'ogg', 'wav'], 20 * 1048576],
        'pdf' => [['pdf'], 10 * 1048576],
    ];

    /** @return array<string, array<int, mixed>> */
    public static function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'kind' => [$creating ? 'sometimes' : 'prohibited', Rule::in(KinMemory::KINDS)],
            'title' => [$req, 'string', 'max:150'],
            'body' => ['sometimes', 'nullable', 'string', 'max:60000'],
            'happened_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'happened_year' => ['sometimes', 'nullable', 'integer', 'min:1700', 'max:'.now()->year],
            'place' => ['sometimes', 'nullable', 'string', 'max:150'],
            'event_id' => ['sometimes', 'nullable', 'integer'],
            'people' => ['sometimes', 'array', 'max:100'],
            'people.*' => ['integer'],
            'files' => [$creating ? 'sometimes' : 'prohibited', 'array', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'max:'.(20 * 1024)],
        ];
    }

    public static function find(int $companyId, int $id): KinMemory
    {
        return KinMemory::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')->find($id)
            ?? throw BusinessRuleException::make('not_found', 'That memory was not found.');
    }

    /** 'all' | 'branch' | 'stories' | 'none' */
    public static function level(User $user, Company $company): string
    {
        if (! Permissions::can($user, 'kin_memories')) {
            return 'none';
        }
        if (KinPurse::isHead($user, $company)) {
            return 'all';
        }

        return match (KinScope::level($user)) {
            'all' => 'all',
            'branch' => 'branch',
            default => 'stories',
        };
    }

    /** @return array{used_bytes: int, limit_bytes: ?int} */
    public static function usage(Company $company): array
    {
        $q = new Quotas;
        $mb = $q->limit($company, 'storage');

        return ['used_bytes' => $q->storageBytes($company), 'limit_bytes' => $mb === null ? null : $mb * 1048576];
    }

    /**
     * @param array<string, mixed> $d
     * @param list<UploadedFile> $files
     */
    public function create(Company $company, array $d, array $files, User $by, ?string $deviceId = null): KinMemory
    {
        $cid = (int) $company->id;
        $kind = (string) ($d['kind'] ?? 'story');
        $people = array_values(array_unique(array_map('intval', $d['people'] ?? [])));
        $this->checkPeople($cid, $people);
        $this->checkEvent($cid, $d['event_id'] ?? null);

        $level = self::level($by, $company);
        if ($level === 'none') {
            throw BusinessRuleException::make('forbidden', 'Your role does not allow adding memories. Ask the head of the family.');
        }
        if ($level === 'stories' && ($kind !== 'story' || $files !== [])) {
            throw BusinessRuleException::make('forbidden', 'You may add your own stories in words. Ask the head or the secretary to add photos, voice notes and documents.');
        }
        if ($level === 'branch') {
            $mine = KinScope::branchIds($by, $cid);
            $theirs = $people === [] ? 0 : SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $people)->whereIn('branch_id', $mine ?: [0])->count();
            if ($people === [] || $theirs !== count($people)) {
                throw BusinessRuleException::make('forbidden', 'A branch coordinator adds memories about the people of their own branch: name them.', ['people' => ['Only your branch\'s people.']]);
            }
        }
        if ($kind !== 'story' && $files === []) {
            throw BusinessRuleException::make('files_required', 'Add the '.($kind === 'voice' ? 'voice note' : $kind).' itself.', ['files' => ['Required for a '.$kind.'.']]);
        }

        // Read and check every file before anything is stored, so a refusal leaves nothing behind.
        $prepared = [];
        $bytes = 0;
        foreach ($files as $i => $f) {
            $prepared[] = $p = $this->prepare($f, $i);
            $bytes += $p['bytes'] + strlen((string) $p['thumb']);
        }
        if ($bytes > 0) {
            try {
                (new Quotas)->assertCanStore($company, $bytes);
            } catch (BusinessRuleException) {
                $u = self::usage($company);
                throw BusinessRuleException::make('storage_full', 'The family\'s storage is full. Remove old files, or move to a larger plan.',
                    ['used_bytes' => $u['used_bytes'], 'limit_bytes' => $u['limit_bytes'], 'files' => ['No room left.']]);
            }
        }

        $written = [];
        try {
            return DB::transaction(function () use ($cid, $d, $kind, $people, $prepared, $by, $deviceId, &$written) {
                $m = KinMemory::create(['company_id' => $cid, 'kind' => $kind, 'title' => $d['title'], 'body' => $d['body'] ?? null, 'happened_on' => $d['happened_on'] ?? null,
                    'happened_year' => $d['happened_year'] ?? (! empty($d['happened_on']) ? (int) substr((string) $d['happened_on'], 0, 4) : null),
                    'place' => $d['place'] ?? null, 'event_id' => $d['event_id'] ?? null, 'created_by' => $by->id]);
                $this->tag($m, $people);
                foreach ($prepared as $sort => $p) {
                    $file = $this->store($cid, 'kin_memory', $p['raw'], $p['mime'], $p['ext'], $by, $deviceId, $written);
                    $thumb = $p['thumb'] !== null ? $this->store($cid, 'kin_memory_thumb', $p['thumb'], 'image/jpeg', 'jpg', $by, $deviceId, $written) : null;
                    KinMemoryFile::create(['memory_id' => $m->id, 'file_id' => $file->id, 'thumb_file_id' => $thumb?->id, 'mime' => $p['mime'], 'bytes' => $p['bytes'], 'sort' => $sort]);
                }

                return $m->fresh();
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($written);
            throw $e;
        }
    }

    /** @param array<string, mixed> $d */
    public function update(KinMemory $m, Company $company, array $d, User $by): KinMemory
    {
        $this->guardOwner($m, $company, $by);
        $cid = (int) $company->id;
        if (array_key_exists('event_id', $d)) {
            $this->checkEvent($cid, $d['event_id']);
        }
        DB::transaction(function () use ($m, $d, $cid) {
            $m->fill(array_intersect_key($d, array_flip(['title', 'body', 'happened_on', 'happened_year', 'place', 'event_id'])))->save();
            if (array_key_exists('people', $d)) {
                $people = array_values(array_unique(array_map('intval', $d['people'] ?? [])));
                $this->checkPeople($cid, $people);
                DB::table('kin_memory_people')->where('memory_id', $m->id)->delete();
                $this->tag($m, $people);
            }
        });

        return $m->fresh();
    }

    /** Remove a memory: the row is kept (soft delete) but its files leave the disk, so the family's storage is freed. */
    public function delete(KinMemory $m, Company $company, User $by): void
    {
        $this->guardOwner($m, $company, $by);
        DB::transaction(function () use ($m) {
            $rows = KinMemoryFile::where('memory_id', $m->id)->get();
            $ids = $rows->pluck('file_id')->merge($rows->pluck('thumb_file_id'))->filter()->map(fn ($v) => (int) $v)->all();
            $files = StoredFile::withoutGlobalScopes()->where('company_id', $m->company_id)->whereIn('id', $ids)->get();
            Storage::disk('public')->delete($files->pluck('path')->all());
            StoredFile::withoutGlobalScopes()->whereIn('id', $files->pluck('id'))->delete();
            KinMemoryFile::where('memory_id', $m->id)->delete();
            $m->delete();
        });
    }

    private function guardOwner(KinMemory $m, Company $company, User $by): void
    {
        if ((int) $m->created_by !== (int) $by->id && ! KinPurse::isHead($by, $company)) {
            throw BusinessRuleException::make('forbidden', 'Only the person who added this memory, or the head of the family, may change it.');
        }
    }

    /** @return array{raw: string, mime: string, ext: string, bytes: int, thumb: ?string} */
    private function prepare(UploadedFile $f, int $i): array
    {
        $ext = strtolower((string) ($f->getClientOriginalExtension() ?: $f->guessExtension()));
        $mime = (string) ($f->getMimeType() ?: 'application/octet-stream');
        $bytes = (int) $f->getSize();
        $type = null;
        foreach (self::FILES as $t => [$exts]) {
            if (in_array($ext, $exts, true)) {
                $type = $t;
            }
        }
        $mimeOk = match ($type) {
            'image' => str_starts_with($mime, 'image/'),
            'audio' => str_starts_with($mime, 'audio/') || in_array($mime, ['video/mp4', 'application/ogg', 'application/octet-stream'], true),
            'pdf' => $mime === 'application/pdf',
            default => false,
        };
        if ($type === null || ! $mimeOk) {
            throw BusinessRuleException::make('file_type', 'Send photos (JPG, PNG, WebP), voice notes (MP3, M4A, AAC, OGG, WAV) or PDF documents.', ["files.$i" => ['This kind of file is not taken.']]);
        }
        $max = self::FILES[$type][1];
        if ($bytes > $max) {
            throw BusinessRuleException::make('file_too_large', 'That file is too large: '.($type === 'audio' ? 'voice notes' : ($type === 'pdf' ? 'documents' : 'photos')).' may be up to '.intdiv($max, 1048576).' MB.',
                ["files.$i" => ['Too large.']]);
        }
        $raw = (string) file_get_contents($f->getRealPath());
        $thumb = null;
        if ($type === 'image') {
            $thumb = KinPhoto::thumb($raw);
            if ($thumb === null) {
                throw BusinessRuleException::make('photo_unreadable', 'That picture could not be read. Send a JPG, PNG or WebP photo.', ["files.$i" => ['Not a readable picture.']]);
            }
        }

        return ['raw' => $raw, 'mime' => $type === 'audio' && ! str_starts_with($mime, 'audio/') ? 'audio/'.($ext === 'm4a' ? 'mp4' : $ext) : $mime,
            'ext' => $ext === 'jpeg' ? 'jpg' : $ext, 'bytes' => $bytes, 'thumb' => $thumb];
    }

    /** @param list<string> $written */
    private function store(int $cid, string $purpose, string $raw, string $mime, string $ext, User $by, ?string $deviceId, array &$written): StoredFile
    {
        $uuid = (string) Str::uuid();
        $path = 'files/'.$cid.'/'.now()->format('Y/m').'/'.$uuid.'.'.$ext;
        Storage::disk('public')->put($path, $raw);
        $written[] = $path;

        return StoredFile::withoutGlobalScopes()->create(['company_id' => $cid, 'uuid' => $uuid, 'purpose' => $purpose, 'path' => $path, 'mime' => $mime,
            'size_bytes' => strlen($raw), 'uploaded_by_id' => $by->id, 'device_id' => $deviceId]);
    }

    /** @param list<int> $people */
    private function tag(KinMemory $m, array $people): void
    {
        if ($people !== []) {
            DB::table('kin_memory_people')->insertOrIgnore(array_map(fn ($id) => ['memory_id' => $m->id, 'member_id' => $id], $people));
        }
    }

    /** @param list<int> $people */
    private function checkPeople(int $cid, array $people): void
    {
        if ($people !== [] && SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereIn('id', $people)->count() !== count($people)) {
            throw BusinessRuleException::make('not_found', 'One of those people is not in this family.');
        }
    }

    private function checkEvent(int $cid, mixed $eventId): void
    {
        if ($eventId !== null && ! KinEvent::withoutGlobalScopes()->where('company_id', $cid)->whereKey((int) $eventId)->exists()) {
            throw BusinessRuleException::make('not_found', 'That gathering is not in this family.');
        }
    }

    // ---------------------------------------------------------------- what the API shows

    /** @param Collection<int, KinMemory> $rows @return list<array<string, mixed>> */
    public function present(Collection $rows, bool $full = false): array
    {
        $ids = $rows->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $cid = (int) $rows->first()->company_id;
        $tags = DB::table('kin_memory_people as t')->join('sacco_members as m', 'm.id', '=', 't.member_id')->whereIn('t.memory_id', $ids)->where('m.company_id', $cid)
            ->orderBy('m.first_name')->get(['t.memory_id', 'm.id', 'm.first_name', 'm.other_names', 'm.last_name'])->groupBy('memory_id');
        $files = KinMemoryFile::whereIn('memory_id', $ids)->orderBy('sort')->orderBy('id')->get()->groupBy('memory_id');
        $fileIds = $files->flatten()->flatMap(fn ($f) => [(int) $f->file_id, (int) $f->thumb_file_id])->filter()->unique()->values()->all();
        $paths = $fileIds === [] ? collect() : DB::table('files')->where('company_id', $cid)->whereIn('id', $fileIds)->pluck('path', 'id');
        $url = fn (?int $id) => $id && isset($paths[$id]) ? Storage::disk('public')->url($paths[$id]) : null;
        $authors = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('created_by')->filter()->unique())->get(['id', 'name', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => trim((string) ($u->name ?: $u->first_name.' '.$u->last_name))]);

        return $rows->map(function (KinMemory $m) use ($full, $tags, $files, $url, $authors) {
            $out = ['id' => (int) $m->id, 'kind' => $m->kind, 'title' => $m->title, 'body_preview' => $m->body === null ? null : Str::limit(trim((string) $m->body), 200),
                'happened_on' => $m->happened_on?->toDateString(), 'happened_year' => $m->happened_year !== null ? (int) $m->happened_year : null, 'place' => $m->place,
                'event_id' => $m->event_id ? (int) $m->event_id : null,
                'people' => ($tags[$m->id] ?? collect())->map(fn ($p) => ['id' => (int) $p->id, 'name' => trim(implode(' ', array_filter([$p->first_name, $p->other_names, $p->last_name])))])->values()->all(),
                'files' => ($files[$m->id] ?? collect())->map(fn ($f) => ['id' => (int) $f->id, 'url' => $url((int) $f->file_id), 'thumb_url' => $url($f->thumb_file_id ? (int) $f->thumb_file_id : null),
                    'mime' => $f->mime, 'bytes' => (int) $f->bytes])->values()->all(),
                'author_user_id' => $m->created_by ? (int) $m->created_by : null, 'author_name' => $m->created_by ? ($authors[(int) $m->created_by] ?? null) : null,
                'created_at' => $m->created_at?->toIso8601String(), 'updated_at' => $m->updated_at?->toIso8601String()];
            if ($full) {
                $out['body'] = $m->body;
            }

            return $out;
        })->values()->all();
    }
}
