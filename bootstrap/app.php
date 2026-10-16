<?php

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/

$app = new Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
*/

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

/*
| A standalone copy is served from the buyer's web folder (docs/STANDALONE_EDITION_PLAN.md §8.2), not
| from public/: the installer writes that folder to APP_PUBLIC_PATH. Set before any configuration is
| read, so public_path() (the admin disk, /storage) points there in web requests, artisan and the
| cached configuration alike. Unset (the SaaS) changes nothing.
*/
if (is_file($environmentFile = $app->basePath('.env'))
    && str_contains($environment = (string) file_get_contents($environmentFile), 'APP_PUBLIC_PATH=')) {
    $publicPath = Dotenv\Dotenv::parse($environment)['APP_PUBLIC_PATH'] ?? null;
    if (is_string($publicPath) && $publicPath !== '' && is_dir($publicPath)) {
        $app->usePublicPath(realpath($publicPath));
    }
}
unset($environmentFile, $environment, $publicPath);

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;
