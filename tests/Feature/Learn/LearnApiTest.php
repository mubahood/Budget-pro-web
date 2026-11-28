<?php

namespace Tests\Feature\Learn;

use App\Models\Learn\LearnLesson;
use App\Models\Learn\LearnSection;
use Tests\Concerns\MakesKin;
use Tests\Feature\Api\ApiTestCase;

/**
 * Learn API (docs/LEARN_VIDEOS.md): lessons scoped to the app in X-App (one app, several, or all), the audience rules,
 * public lessons when signed out, drafts hidden, areas for "How this works", progress, and "new since you last looked".
 */
class LearnApiTest extends ApiTestCase
{
    use MakesKin;

    private function lesson(string $title, array $apps = [], array $attrs = []): LearnLesson
    {
        $section = LearnSection::query()->firstOrCreate(['slug' => 'test-basics'], ['title' => 'Test basics', 'sort' => 1]);
        $l = LearnLesson::query()->create($attrs + [
            'title' => $title, 'section_id' => $section->id, 'all_apps' => $apps === ['*'], 'audience' => 'everyone',
            'source_type' => 'youtube', 'youtube_id' => 'abcdefghijk', 'duration_seconds' => 100,
            'published' => true, 'published_at' => now()->subDay(), 'language' => 'en',
        ]);
        $l->syncApps($apps === ['*'] ? [] : $apps);

        return $l;
    }

    /** @return array<int, string> every lesson title in a library response */
    private function titles($res): array
    {
        return collect($res->json('data.sections'))->flatMap(fn ($s) => collect($s['lessons'])->pluck('title'))->all();
    }

    private function learn(?string $token = null, string $app = 'family-roots', string $query = '')
    {
        return $this->getJson('/api/v1/learn'.$query, ($token ? $this->auth($token) : []) + ['X-App' => $app]);
    }

    public function test_lessons_are_scoped_to_the_app_and_all_apps_lessons_show_everywhere(): void
    {
        [, , $token] = $this->kinAccount();
        $this->lesson('Family only', ['family-roots']);
        $this->lesson('For every app', ['*']);
        $this->lesson('Shop only', ['shelfie']);
        $this->lesson('Two apps', ['shelfie', 'family-roots']);

        $titles = $this->titles($this->learn($token)->assertOk());
        $this->assertContains('Family only', $titles);
        $this->assertContains('For every app', $titles);
        $this->assertContains('Two apps', $titles);
        $this->assertNotContains('Shop only', $titles);

        // The phone's older flavour name works too, and the other app sees its own.
        $this->assertContains('Family only', $this->titles($this->learn($token, 'kin')));
        $shop = $this->titles($this->learn($token, 'shelfie'));
        $this->assertContains('Shop only', $shop);
        $this->assertNotContains('Family only', $shop);

        $this->getJson('/api/v1/learn', $this->auth($token))->assertStatus(422);
        $this->learn($token, 'nonsense')->assertStatus(422);
        $this->getJson('/api/v1/learn?app=family-roots', $this->auth($token))->assertOk();
    }

    public function test_drafts_are_hidden_and_signed_out_people_see_only_public_lessons(): void
    {
        [, , $token] = $this->kinAccount();
        $draft = $this->lesson('A draft', ['*'], ['published' => false]);
        $this->lesson('Open to all', ['family-roots'], ['audience' => 'public']);
        $this->lesson('Signed in only', ['family-roots']);

        $this->assertNotContains('A draft', $this->titles($this->learn($token)));
        $this->getJson("/api/v1/learn/{$draft->id}", $this->auth($token) + ['X-App' => 'family-roots'])->assertNotFound();

        $out = $this->learn()->assertOk()->assertJsonPath('data.signed_in', false);
        $this->assertSame(['Open to all'], array_values(array_intersect($this->titles($out), ['Open to all', 'Signed in only', 'A draft'])));
    }

    public function test_officers_and_members_each_see_their_own_lessons(): void
    {
        [$company, , $ownerToken] = $this->kinAccount();
        $this->lesson('For officers', ['family-roots'], ['audience' => 'officers']);
        $this->lesson('For members', ['family-roots'], ['audience' => 'members']);
        $this->lesson('Treasurer only', ['family-roots'], ['audience' => 'officers', 'roles' => ['kin_treasurer']]);
        $member = $this->kinStaff($company, 'kin_member');
        $memberToken = $member->createToken('t')->plainTextToken;
        $treasurer = $this->kinStaff($company, 'kin_treasurer');
        $treasurerToken = $treasurer->createToken('t')->plainTextToken;

        $owner = $this->titles($this->learn($ownerToken));
        $this->assertContains('For officers', $owner);
        $this->assertNotContains('For members', $owner);
        $this->assertNotContains('Treasurer only', $owner);

        $m = $this->titles($this->learn($memberToken));
        $this->assertContains('For members', $m);
        $this->assertNotContains('For officers', $m);

        $this->assertContains('Treasurer only', $this->titles($this->learn($treasurerToken)));
    }

    public function test_progress_resume_completion_area_help_and_new_marks(): void
    {
        [, , $token] = $this->kinAccount();
        $a = $this->lesson('Record a contribution', ['family-roots'], ['areas' => ['contributions'], 'featured' => true]);
        $b = $this->lesson('Plan a gathering', ['family-roots'], ['areas' => ['gatherings']]);
        $other = $this->lesson('Shop only', ['shelfie']);
        $h = $this->auth($token) + ['X-App' => 'family-roots'];

        $lib = $this->learn($token)->assertOk();
        $this->assertSame($a->id, $lib->json('data.featured.id'));
        $this->assertGreaterThanOrEqual(2, $lib->json('data.new_count'));

        $area = $this->learn($token, 'family-roots', '?area=contributions');
        $this->assertSame(['Record a contribution'], $this->titles($area));

        // A first report starts a sitting; "watched" is capped at 60 seconds a report.
        $this->postJson("/api/v1/learn/{$a->id}/progress", ['position' => 30, 'watched' => 600, 'started' => true], $h)
            ->assertOk()->assertJsonPath('data.progress.watched_seconds', 60)->assertJsonPath('data.progress.completed', false);
        $this->postJson("/api/v1/learn/{$a->id}/progress", ['position' => 92, 'watched' => 40, 'duration' => 100], $h)
            ->assertOk()->assertJsonPath('data.progress.completed', true)->assertJsonPath('data.progress.position', 92);

        $show = $this->getJson("/api/v1/learn/{$a->id}", $h)->assertOk();
        $this->assertSame(92, $show->json('data.lesson.progress.position'));
        $this->assertSame(100, $show->json('data.lesson.progress.watched_seconds'));
        $this->assertContains($b->id, [$show->json('data.previous_id'), $show->json('data.next_id')]);

        $this->assertSame(1, $this->learn($token)->json('data.completed'));
        $this->assertSame(1, \App\Models\Learn\LearnProgress::query()->where('lesson_id', $a->id)->value('views'));

        // Not this app's lesson, and not signed in: no progress.
        $this->postJson("/api/v1/learn/{$other->id}/progress", ['position' => 5], $h)->assertNotFound();
        $this->postJson("/api/v1/learn/{$a->id}/progress", ['position' => 5], ['X-App' => 'family-roots'])->assertUnauthorized();

        $this->postJson('/api/v1/learn/seen', [], $h)->assertOk();
        $this->assertSame(0, $this->learn($token)->json('data.new_count'));
    }
}
