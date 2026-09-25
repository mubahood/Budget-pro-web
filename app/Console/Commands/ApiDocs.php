<?php

namespace App\Console\Commands;

use App\Support\OpenApi\Generator;
use Illuminate\Console\Command;

/** Writes docs/openapi.json and the Postman collection from the live routes (plan A8, P4-7). */
class ApiDocs extends Command
{
    protected $signature = 'api:docs';

    protected $description = 'Generate the OpenAPI spec and Postman collection for /api/v1';

    public function handle(Generator $gen): int
    {
        $spec = $gen->spec();
        @mkdir(base_path('docs/postman'), 0755, true);
        file_put_contents(base_path('docs/openapi.json'), json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        file_put_contents(base_path('docs/postman/budget-pro-v1.postman_collection.json'), json_encode($gen->postman($spec), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $ops = array_sum(array_map('count', $spec['paths']));
        $this->info(count($spec['paths'])." paths, {$ops} operations → docs/openapi.json, docs/postman/budget-pro-v1.postman_collection.json");

        return self::SUCCESS;
    }
}
