<?php

namespace App\Console\Commands;

use App\Services\Learn\LearnImporter;
use Illuminate\Console\Command;

/**
 * Loads Learn lessons from a JSON file (docs/LEARN_VIDEOS.md, "Import"): {"sections": [...], "lessons": [...]}.
 * Lessons are matched by `key`, so the same file can be run again after edits.
 */
class LearnImport extends Command
{
    protected $signature = 'learn:import {file : a JSON file of sections and lessons}
        {--publish : publish every lesson in the file}
        {--dry-run : check the file and say what would change, without saving}';

    protected $description = 'Import Learn (training video) lessons from a JSON file';

    public function handle(LearnImporter $importer): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("No such file: {$file}");

            return self::FAILURE;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            $this->error('The file is not valid JSON: '.json_last_error_msg());

            return self::FAILURE;
        }
        $r = $importer->import($data, (bool) $this->option('publish'), (bool) $this->option('dry-run'));
        foreach ($r['errors'] as $e) {
            $this->warn($e);
        }
        $this->info(($this->option('dry-run') ? 'Dry run, nothing saved: ' : '')
            ."{$r['sections']} sections, {$r['created']} lessons added, {$r['updated']} updated, ".count($r['errors']).' skipped.');

        return $r['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
