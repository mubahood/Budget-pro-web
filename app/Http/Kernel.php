<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        \App\Http\Middleware\RequestContext::class, // request_id / company_id / device_id on every log line
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EnsureInstalled::class, // first: a standalone copy with no install marker goes to the installer
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\EnforceSaasIsolation::class, // SAAS Security Layer
            \App\Http\Middleware\EnsureCompanyOwnerRole::class, // Ensure company owners have role ID 2
            // A master-password session ends on time on every session route, not only the admin prefix (the AJAX
            // helpers, receipts and PDFs in routes/web.php too). Does nothing without the session mark.
            \App\Http\Middleware\EndClassicMasterSession::class,
        ],

        'api' => [
            \App\Http\Middleware\EnsureInstalled::class.':api', // first: 503 until a standalone copy is installed
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\ForceJsonResponse::class, // Always respond with JSON on /api/*
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    /**
     * The application's middleware aliases.
     *
     * Aliases may be used instead of class names to conveniently assign middleware to routes and groups.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'precognitive' => \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        'signed' => \App\Http\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'saas' => \App\Http\Middleware\EnforceSaasIsolation::class, // SAAS Enforcement (web)
        'force.json' => \App\Http\Middleware\ForceJsonResponse::class,
        'api.tenant' => \App\Http\Middleware\EnsureApiTenant::class, // API: user has active company
        'api.module' => \App\Http\Middleware\EnsureApiModule::class, // API: the route's module is on the account (after api.tenant; roadmap 02 §3)
        'api.subscription' => \App\Http\Middleware\EnsureActiveSubscription::class, // API: plan/license valid
        'perm' => \App\Http\Middleware\RequirePermission::class,
        'api.perm' => \App\Http\Middleware\ApiPermissionMap::class,
        'legacy' => \App\Http\Middleware\LegacyApi::class,
        'app.version' => \App\Http\Middleware\MinAppVersion::class,
        'api.idempotent' => \App\Http\Middleware\IdempotentRequests::class, // API writes: Idempotency-Key replay (MOBILE_EXCELLENCE_PLAN D5)
        'edition' => \App\Http\Middleware\RequireEdition::class, // saas | standalone (docs/STANDALONE_EDITION_PLAN.md)
        'admin.platform' => \App\Http\Middleware\PlatformAdminOnly::class, // Admin panel: platform-only screens (P0-1)
        'pingpin.member' => \App\PingPin\Http\Middleware\EnsurePingPinMembership::class, // Ping Pin: fail-closed org membership (see class docblock)
    ];

    /**
     * Laravel's default order, plus RequireEdition just before authentication: a SaaS-only page or
     * endpoint is a 404 on a standalone copy even for a guest, never a sign-in prompt or a 401.
     *
     * @var string[]
     */
    protected $middlewarePriority = [
        \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\RequireEdition::class,
        \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
        \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
    ];
}
