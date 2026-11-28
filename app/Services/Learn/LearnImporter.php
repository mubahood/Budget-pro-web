<?php

namespace App\Services\Learn;

use App\Models\Learn\LearnLesson;
use App\Models\Learn\LearnSection;
use Illuminate\Support\Facades\DB;

/**
 * Loads lessons from a list (the learn:import JSON, or the local placeholder seeder). Sections are matched by slug,
 * lessons by their `key`, so running the same file again updates rather than duplicates. docs/LEARN_VIDEOS.md has the format.
 */
class LearnImporter
{
    /**
     * @param  array{sections?: array<int, array<string, mixed>>, lessons?: array<int, array<string, mixed>>}  $data
     * @return array{sections: int, created: int, updated: int, errors: array<int, string>}
     */
    public function import(array $data, bool $publish = false, bool $dryRun = false): array
    {
        $out = ['sections' => 0, 'created' => 0, 'updated' => 0, 'errors' => []];
        $apps = array_keys(Learn::apps());

        DB::beginTransaction();
        try {
            $sections = [];
            foreach ((array) ($data['sections'] ?? []) as $i => $s) {
                $slug = (string) ($s['slug'] ?? '');
                if (! preg_match('/^[a-z0-9-]{2,80}$/', $slug) || trim((string) ($s['title'] ?? '')) === '') {
                    $out['errors'][] = "Section #{$i}: needs a slug (a-z, 0-9, -) and a title.";

                    continue;
                }
                $row = LearnSection::query()->updateOrCreate(['slug' => $slug], [
                    'title' => trim((string) $s['title']), 'description' => $s['description'] ?? null, 'sort' => (int) ($s['sort'] ?? $i + 1),
                ]);
                $sections[$slug] = $row->id;
                $out['sections']++;
            }

            foreach ((array) ($data['lessons'] ?? []) as $i => $l) {
                $key = (string) ($l['key'] ?? '');
                $label = $key !== '' ? $key : "#{$i}";
                if (! preg_match('/^[a-z0-9_.-]{2,80}$/', $key) || trim((string) ($l['title'] ?? '')) === '') {
                    $out['errors'][] = "Lesson {$label}: needs a key (a-z, 0-9, - _ .) and a title.";

                    continue;
                }
                $targets = $l['apps'] ?? [];
                $all = $targets === 'all' || $targets === ['all'];
                $targets = $all ? [] : array_map(fn ($a) => Learn::app((string) $a), (array) $targets);
                if (! $all && ($targets === [] || in_array(null, $targets, true))) {
                    $out['errors'][] = "Lesson {$label}: apps must be \"all\" or a list of: ".implode(', ', $apps).'.';

                    continue;
                }
                $youtube = isset($l['youtube']) ? YouTube::videoId((string) $l['youtube']) : null;
                $type = $youtube ? 'youtube' : (! empty($l['video_url']) ? 'url' : null);
                if ($type === null) {
                    $out['errors'][] = "Lesson {$label}: give a youtube link or id, or a video_url.";

                    continue;
                }
                $audience = (string) ($l['audience'] ?? 'everyone');
                if (! array_key_exists($audience, LearnLesson::AUDIENCES)) {
                    $out['errors'][] = "Lesson {$label}: audience must be one of ".implode(', ', array_keys(LearnLesson::AUDIENCES)).'.';

                    continue;
                }
                $sectionSlug = (string) ($l['section'] ?? '');
                $sectionId = $sections[$sectionSlug] ?? LearnSection::query()->where('slug', $sectionSlug)->value('id');

                $lesson = LearnLesson::query()->firstOrNew(['key' => $key]);
                $isNew = ! $lesson->exists;
                $published = $publish || (bool) ($l['published'] ?? ($lesson->published ?? false));
                $lesson->fill([
                    'section_id' => $sectionId, 'title' => trim((string) $l['title']),
                    'summary' => $l['summary'] ?? null, 'description' => $l['description'] ?? null,
                    'all_apps' => $all, 'areas' => array_values((array) ($l['areas'] ?? [])) ?: null,
                    'audience' => $audience, 'roles' => array_values((array) ($l['roles'] ?? [])) ?: null,
                    'permissions' => array_values((array) ($l['permissions'] ?? [])) ?: null,
                    'language' => (string) ($l['language'] ?? 'en'),
                    'source_type' => $type, 'youtube_id' => $youtube, 'video_url' => $type === 'url' ? (string) $l['video_url'] : null,
                    'thumbnail_url' => $l['thumbnail_url'] ?? null,
                    'duration_seconds' => isset($l['duration']) ? (int) $l['duration'] : $lesson->duration_seconds,
                    'featured' => (bool) ($l['featured'] ?? false),
                    'sort' => (int) ($l['sort'] ?? ($lesson->sort ?: $i + 1)),
                    'published' => $published,
                ]);
                if ($published) {
                    $lesson->published_at ??= now();
                }
                $lesson->save();
                $lesson->syncApps(array_values(array_unique(array_filter($targets))));
                $out[$isNew ? 'created' : 'updated']++;
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $out;
    }
}
