<?php

namespace Tests\Feature;

use App\Scopes\CompanyScope;
use App\Support\TenantModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tenancy that cannot be forgotten (roadmap 02 §4, phase-0 plan P0-4): every model whose table has a
 * company_id applies CompanyScope, or is on App\Support\TenantModels with its reason. A new model that
 * does neither fails here, before it can leak one shop's rows into another's.
 */
class TenantModelsTest extends TestCase
{
    /** @return array<class-string<Model>, Model> every concrete Eloquent model under app/Models */
    private function models(): array
    {
        $root = app_path('Models');
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'App\\Models\\'.str_replace('/', '\\', $relative);
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }
            $out[$class] = $reflection->newInstance();
        }
        ksort($out);

        return $out;
    }

    private function hasCompanyId(Model $model): bool
    {
        return Schema::hasTable($model->getTable()) && Schema::hasColumn($model->getTable(), 'company_id');
    }

    private function scoped(Model $model): bool
    {
        return array_key_exists(CompanyScope::class, $model->getGlobalScopes());
    }

    public function test_every_model_with_a_company_id_is_scoped_or_global_on_purpose(): void
    {
        $models = $this->models();
        $this->assertGreaterThan(60, count($models), 'the scan sees app/Models');

        $offenders = [];
        $tenant = 0;
        foreach ($models as $class => $model) {
            if (! $this->hasCompanyId($model)) {
                continue;
            }
            $tenant++;
            if (! $this->scoped($model) && ! TenantModels::isGlobalOnPurpose($class)) {
                $offenders[] = $class.' ('.$model->getTable().')';
            }
        }

        $this->assertGreaterThan(60, $tenant);
        $this->assertSame([], $offenders, "Models with a company_id that neither apply CompanyScope (static::addGlobalScope(new CompanyScope) in booted()) nor appear in App\\Support\\TenantModels::GLOBAL_ON_PURPOSE with a reason:\n".implode("\n", $offenders));
    }

    public function test_the_global_on_purpose_list_stays_honest(): void
    {
        foreach (TenantModels::GLOBAL_ON_PURPOSE as $class => $reason) {
            $this->assertTrue(class_exists($class), "{$class} exists");
            $model = new $class;
            $this->assertTrue($this->hasCompanyId($model), "{$class} has a company_id (otherwise it does not belong on the list)");
            $this->assertFalse($this->scoped($model), "{$class} is on the list, so it is not scoped: take it off the list if it is");
            $this->assertNotSame('', trim($reason), "{$class} gives its reason");
        }
        foreach (TenantModels::THROUGH_PARENT as $class => $reason) {
            $this->assertFalse($this->hasCompanyId(new $class), "{$class} gained a company_id: scope it (or list it) instead");
            $this->assertNotSame('', trim($reason));
        }
    }

    public function test_the_models_that_had_no_scope_are_scoped_now(): void
    {
        foreach ([\App\Models\HeldCart::class, \App\Models\PriceChange::class, \App\Models\LabelQueueItem::class, \App\Models\FiscalSetting::class,
            \App\Models\FiscalSubmission::class, \App\Models\PurchaseOrderItem::class, \App\Models\PurchaseReturnItem::class, \App\Models\SyncBatch::class] as $class) {
            $this->assertTrue($this->scoped(new $class), "{$class} applies CompanyScope");
        }
    }
}
