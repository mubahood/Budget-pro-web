<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Support\SchemaIntegrity::guardModels();

        // CompanyScope keeps each table's "has company_id" answer in the cache for good; a migration may change it.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\MigrationsEnded::class, fn () => \App\Scopes\CompanyScope::flushColumnCache());
        // LocalTime::prime() reuses the timezone of a company this request already loaded.
        \App\Support\LocalTime::listen();
        // Standalone: the buyer's own mail, SMS, WhatsApp and Flutterwave keys (Settings › Connections) over .env.
        \App\Support\InstallSettings::overlay();
        // The prefilled support messages (lang/en/support.php) as `bp::support.*`, the same name the web app uses.
        \Illuminate\Support\Facades\Lang::addNamespace('bp', lang_path());
    }
}
