<?php

namespace Database\Seeders;

use App\Services\Learn\LearnImporter;
use Illuminate\Database\Seeder;

/**
 * A few placeholder Learn lessons to see the screens work on a local copy. Local and testing only; never published,
 * and they point at no real video (the id is all zeros). Run: php artisan db:seed --class=LearnPlaceholderSeeder
 */
class LearnPlaceholderSeeder extends Seeder
{
    public function run(LearnImporter $importer): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Placeholder lessons are for local copies only; nothing done.');

            return;
        }
        $fake = '00000000000';
        $importer->import([
            'sections' => [
                ['slug' => 'getting-started', 'title' => 'Getting started', 'sort' => 1],
                ['slug' => 'everyday-work', 'title' => 'Everyday work', 'sort' => 2],
            ],
            'lessons' => [
                ['key' => 'placeholder.welcome', 'section' => 'getting-started', 'title' => '[Placeholder] A tour of the app', 'summary' => 'What each tab is for.',
                    'apps' => 'all', 'audience' => 'public', 'youtube' => $fake, 'duration' => 120, 'featured' => true, 'published' => false],
                ['key' => 'placeholder.team', 'section' => 'getting-started', 'title' => '[Placeholder] Bring in your team', 'summary' => 'Invite people and give each a role.',
                    'apps' => 'all', 'audience' => 'officers', 'youtube' => $fake, 'duration' => 150, 'published' => false],
                ['key' => 'placeholder.kin-contributions', 'section' => 'everyday-work', 'title' => '[Placeholder] Record a contribution', 'summary' => 'From the Contributions tab.',
                    'apps' => ['family-roots'], 'areas' => ['contributions'], 'audience' => 'everyone', 'youtube' => $fake, 'duration' => 95, 'published' => false],
                ['key' => 'placeholder.sale', 'section' => 'everyday-work', 'title' => '[Placeholder] Make a sale', 'summary' => 'At the till, step by step.',
                    'apps' => ['budget-pro', 'shelfie'], 'areas' => ['till'], 'audience' => 'everyone', 'youtube' => $fake, 'duration' => 180, 'published' => false],
            ],
        ]);
        $this->command?->info('Placeholder lessons are in, unpublished. Publish them in Platform › Learn videos to try the app screens.');
    }
}
