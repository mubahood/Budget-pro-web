<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BudgetItemCategoryController;
use App\Http\Controllers\Api\V1\BudgetItemController;
use App\Http\Controllers\Api\V1\BudgetProgramController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\ContributionRecordController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\FinancialCategoryController;
use App\Http\Controllers\Api\V1\FinancialPeriodController;
use App\Http\Controllers\Api\V1\FinancialRecordController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\PoultrySyncController;
use App\Http\Controllers\Api\V1\ProductBarcodeController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StockCategoryController;
use App\Http\Controllers\Api\V1\StockItemController;
use App\Http\Controllers\Api\V1\StockRecordController;
use App\Http\Controllers\Api\V1\StockSubCategoryController;
use App\Http\Controllers\Api\V1\StockTakeController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\TrackingController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\MobileApiController;
use App\PingPin\Http\Controllers\Api\V1\AuthController as PingPinAuthController;
use App\PingPin\Http\Controllers\Api\V1\BillingController as PingPinBillingController;
use App\PingPin\Http\Controllers\Api\V1\OrganisationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Budget Pro v1
|--------------------------------------------------------------------------
|
| All endpoints live under /api/v1 and return the standard envelope:
|   { "code": 1|0, "message": string, "data": mixed, "meta"?: object, "errors"?: object }
|
| Authentication: Laravel Sanctum bearer tokens (Authorization: Bearer <token>).
| Tenant + subscription enforcement runs after auth via api.tenant / api.subscription.
|
*/

/**
 * Register the standard resource route set for a CRUD controller:
 * list / show / create / update / delete, plus /options (dropdowns) and /search (typeahead).
 */
if (! function_exists('apiCrud')) {
    function apiCrud(string $uri, string $controller): void
    {
        Route::get("{$uri}/options", [$controller, 'options']);
        Route::get("{$uri}/search", [$controller, 'search']);
        Route::get($uri, [$controller, 'index']);
        Route::post($uri, [$controller, 'store']);
        Route::get("{$uri}/{id}", [$controller, 'show'])->whereNumber('id');
        Route::put("{$uri}/{id}", [$controller, 'update'])->whereNumber('id');
        Route::patch("{$uri}/{id}", [$controller, 'update'])->whereNumber('id');
        Route::delete("{$uri}/{id}", [$controller, 'destroy'])->whereNumber('id');
    }
}

/*
|--------------------------------------------------------------------------
| Legacy API (pre-v1) — kept ONLY for the currently-installed mobile app
|--------------------------------------------------------------------------
|
| The shipped Budget Dynamics app (pre-v1) authenticates with a
| `logged_in_user_id` request field and calls these exact paths. Do not
| remove or change their request/response shape without a coordinated
| mobile app release — see app/Http/Controllers/ApiController.php for the
| full rationale. New clients must use /api/v1 instead.
|
*/
Route::post('auth/login', [ApiController::class, 'login']);
Route::post('auth/register', [ApiController::class, 'register']);
Route::post('budget-item-create', [ApiController::class, 'budget_item_create']);
Route::post('contribution-records-create', [ApiController::class, 'contribution_records_create']);
Route::get('api/{model}', [ApiController::class, 'my_list']);
Route::post('api/{model}', [ApiController::class, 'my_update']);

Route::prefix('mobile')->group(function () {
    Route::get('dashboard', [MobileApiController::class, 'dashboard']);

    Route::get('budget-programs', [MobileApiController::class, 'budgetPrograms']);
    Route::get('budget-program/{id}', [MobileApiController::class, 'budgetProgramDetail']);
    Route::post('budget-program-save', [MobileApiController::class, 'budgetProgramSave']);

    Route::get('budget-categories', [MobileApiController::class, 'budgetCategories']);
    Route::post('budget-category-save', [MobileApiController::class, 'budgetCategorySave']);

    Route::get('budget-items', [MobileApiController::class, 'budgetItems']);
    Route::post('budget-item-save', [MobileApiController::class, 'budgetItemSave']);

    Route::get('contribution-records', [MobileApiController::class, 'contributionRecords']);
    Route::post('contribution-record-save', [MobileApiController::class, 'contributionRecordSave']);

    Route::get('list/{model}', [MobileApiController::class, 'genericList']);
    Route::post('save/{model}', [MobileApiController::class, 'genericSave']);
});

Route::prefix('v1')->group(function () {

    // ── Public auth endpoints (rate-limited to deter brute force) ──
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/otp/request', [AuthController::class, 'otpRequest']);
        Route::post('auth/otp/verify', [AuthController::class, 'otpVerify']);
        Route::post('auth/password/reset', [AuthController::class, 'resetPassword']);

        // Ping Pin's own, independent signup/login (Task 3.1) — NOT the
        // routes above. Same admin_users/Sanctum identity (DECISIONS.md D6),
        // its own registration shape (phone-or-email, no company_name/
        // currency required) and its own trial-subscription creation.
        Route::post('pingpin/auth/register', [PingPinAuthController::class, 'register']);
        Route::post('pingpin/auth/login', [PingPinAuthController::class, 'login']);

        // Re-authentication step before weakening protection on an already-
        // enrolled device (disable tracking, sign out) — rate-limited here
        // too, since it's a password check an attacker could otherwise
        // brute-force. Only needs auth:sanctum (checks the CALLER's own
        // password), not pingpin.member — it isn't scoped to an organisation.
        Route::middleware('auth:sanctum')->post('pingpin/auth/verify-password', [PingPinAuthController::class, 'verifyPassword']);
    });

    // ── Public billing: pricing page + Flutterwave webhook (signature-verified) ──
    Route::get('plans', [BillingController::class, 'plans']);
    Route::post('webhooks/flutterwave', [BillingController::class, 'webhook']);

    // ── Ping Pin — same public shape, its own separate billing (DECISIONS.md D2) ──
    Route::get('pingpin/plans', [PingPinBillingController::class, 'plans']);
    Route::post('pingpin/webhooks/flutterwave', [PingPinBillingController::class, 'webhook']);

    // ── Authenticated: session/profile (no subscription gate) ──
    Route::middleware(['auth:sanctum', 'api.tenant', 'api.perm'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::put('auth/password', [AuthController::class, 'updatePassword']);
        Route::put('auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('auth/verify/request', [AuthController::class, 'verifyRequest'])->middleware('throttle:5,1');
        Route::post('auth/verify', [AuthController::class, 'verify'])->middleware('throttle:10,1');
        Route::get('auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('auth/sessions/{id}', [AuthController::class, 'revokeSession'])->whereNumber('id');
        Route::post('auth/refresh', [AuthController::class, 'refresh']);
        Route::get('notifications', [\App\Http\Controllers\Api\V1\NotificationController::class, 'index']);
        Route::post('notifications/read', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markRead']);
        Route::get('notifications/preferences', [\App\Http\Controllers\Api\V1\NotificationController::class, 'preferences']);
        Route::put('notifications/preferences', [\App\Http\Controllers\Api\V1\NotificationController::class, 'updatePreferences']);

        Route::get('company', [CompanyController::class, 'show']);
        Route::put('company', [CompanyController::class, 'update']);

        // Billing — reachable even when the subscription has lapsed, so a
        // customer can always pay to reactivate.
        Route::get('subscription', [BillingController::class, 'current']);
        Route::post('subscription/checkout', [BillingController::class, 'checkout']);
        Route::post('subscription/verify', [BillingController::class, 'verify']);
        Route::get('subscription/quote', [BillingController::class, 'quote']);
        Route::post('subscription/cancel', [BillingController::class, 'cancel']);
        Route::post('subscription/resume', [BillingController::class, 'resume']);
        Route::get('subscription/invoices/{id}.pdf', [BillingController::class, 'invoicePdf'])->whereNumber('id');

        // Setup wizard, checklist and modules (plan C2/C4).
        Route::get('onboarding', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'show']);
        Route::post('onboarding/steps/{step}', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'step']);
        Route::put('onboarding/business', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'business']);
        Route::put('onboarding/money', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'money']);
        Route::get('onboarding/templates', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'templates']);
        Route::post('onboarding/templates/apply', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'applyTemplates']);
        Route::post('onboarding/import', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'import']);
        Route::post('onboarding/checklist/dismiss', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'dismissChecklist']);
        Route::put('company/modules', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'modules']);

        // Team (plan C5): owner/managers with manage_team.
        Route::get('team', [\App\Http\Controllers\Api\V1\TeamController::class, 'index']);
        Route::get('team/roles', [\App\Http\Controllers\Api\V1\TeamController::class, 'roles']);
        Route::put('team/roles/{role}', [\App\Http\Controllers\Api\V1\TeamController::class, 'updateRole']);
        Route::post('team/invites', [\App\Http\Controllers\Api\V1\TeamController::class, 'invite']);
        Route::post('team/invites/{id}/resend', [\App\Http\Controllers\Api\V1\TeamController::class, 'resend'])->whereNumber('id');
        Route::delete('team/invites/{id}', [\App\Http\Controllers\Api\V1\TeamController::class, 'revoke'])->whereNumber('id');
        Route::patch('team/members/{userId}', [\App\Http\Controllers\Api\V1\TeamController::class, 'updateMember'])->whereNumber('userId');
        Route::get('team/members/{userId}/activity', [\App\Http\Controllers\Api\V1\TeamController::class, 'activity'])->whereNumber('userId');
        Route::post('team/transfer-ownership', [\App\Http\Controllers\Api\V1\TeamController::class, 'transferOwnership']);
    });

    // Invite links (public, rate-limited): see who invited you, then accept with a password.
    Route::middleware('throttle:20,1')->group(function () {
        Route::get('invites/{token}', [\App\Http\Controllers\Api\V1\InviteController::class, 'show']);
        Route::post('invites/{token}/accept', [\App\Http\Controllers\Api\V1\InviteController::class, 'accept']);
    });

    // ── Ping Pin — organisation membership (Task 1.7 / DECISIONS.md D13) ──
    // Deliberately its OWN group, not nested under api.tenant/api.subscription:
    // api.tenant checks the user's single PRIMARY company_id, which is the
    // wrong question for a multi-org membership action on a DIFFERENT
    // {company} route param, and api.subscription gates on budget-pro's own
    // subscription — Ping Pin has its own separate billing (DECISIONS.md D2)
    // not built yet (Phase 2), so gating on the other product's billing here
    // would be exactly the cross-product coupling D2 exists to avoid.
    // pingpin.member does the real per-organisation authorization itself.
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('pingpin/organisations', [OrganisationController::class, 'index']);
        Route::post('pingpin/organisations/members/{membershipId}/accept', [OrganisationController::class, 'acceptInvite']);
        Route::middleware('pingpin.member')->group(function () {
            Route::post('pingpin/organisations/{company}/members/invite', [OrganisationController::class, 'inviteMember']);
            Route::post('pingpin/organisations/{company}/members/{userId}/revoke', [OrganisationController::class, 'revokeMember']);
            Route::post('pingpin/organisations/{company}/members/{userId}/role', [OrganisationController::class, 'changeRole']);
            Route::post('pingpin/organisations/{company}/transfer-ownership', [OrganisationController::class, 'transferOwnership']);

            // Billing — reachable even with a lapsed/no subscription, same as
            // budget-pro's own pattern above, so an org can always pay to
            // (re)activate. pingpin.member checks membership only, never
            // subscription status, so no further gate is needed here.
            Route::get('pingpin/organisations/{company}/subscription', [PingPinBillingController::class, 'current']);
            Route::post('pingpin/organisations/{company}/subscription/checkout', [PingPinBillingController::class, 'checkout']);
            Route::post('pingpin/organisations/{company}/subscription/verify', [PingPinBillingController::class, 'verify']);
        });
    });

    // ── Authenticated + active subscription: the product surface ──
    // Offline sync v2 (plan Appendix A). Deliberately outside api.subscription: a lapsed
    // tenant's devices keep syncing; batches are held (not lost) once the grace ends.
    Route::middleware(['auth:sanctum', 'api.tenant', 'api.perm'])->group(function () {
        Route::post('devices/register', [DeviceController::class, 'register']);
        Route::get('devices', [DeviceController::class, 'index']);
        Route::post('devices/{id}/revoke', [DeviceController::class, 'revoke'])->whereNumber('id');
        Route::post('sync/push', [SyncController::class, 'push']);
        Route::get('sync/pull', [SyncController::class, 'pull']);
        Route::post('sync/bootstrap', [SyncController::class, 'bootstrap']);
        Route::get('sync/conflicts', [SyncController::class, 'conflicts']);
        Route::post('sync/conflicts/{id}/resolve', [SyncController::class, 'resolve'])->whereNumber('id');
        Route::post('files', [FileController::class, 'store']);
    });

    Route::middleware(['auth:sanctum', 'api.tenant', 'api.subscription', 'api.perm'])->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::post('uploads', [UploadController::class, 'store']);

        // Inventory
        apiCrud('stock-categories', StockCategoryController::class);
        apiCrud('stock-sub-categories', StockSubCategoryController::class);
        apiCrud('stock-items', StockItemController::class);
        Route::get('stock-items/by-barcode/{code}', [StockItemController::class, 'byBarcode']);
        Route::get('stock-records/types', [StockRecordController::class, 'types']);
        apiCrud('stock-records', StockRecordController::class);
        Route::post('stock-records/{id}/reverse', [StockRecordController::class, 'reverse']);

        // Sales / POS
        apiCrud('sales', SaleController::class);
        Route::post('sales/checkout', [SaleController::class, 'checkout']);
        Route::post('sales/{id}/payments', [SaleController::class, 'addPayment']);
        Route::post('sales/{id}/void', [SaleController::class, 'void']);
        Route::post('sales/{id}/returns', [SaleController::class, 'returns'])->whereNumber('id');
        Route::get('sales/{id}/receipt.txt', [SaleController::class, 'receiptText'])->whereNumber('id');
        Route::get('sales/{id}/receipt.pdf', [SaleController::class, 'receiptPdf'])->whereNumber('id');

        // Phase 2 — POS & inventory
        apiCrud('units', UnitController::class);
        apiCrud('product-barcodes', ProductBarcodeController::class);
        apiCrud('customers', CustomerController::class);
        Route::get('customers/{id}/statement', [CustomerController::class, 'statement'])->whereNumber('id');
        Route::post('customers/{id}/payments', [CustomerController::class, 'pay'])->whereNumber('id');
        apiCrud('suppliers', SupplierController::class);
        Route::get('suppliers/{id}/statement', [SupplierController::class, 'statement'])->whereNumber('id');
        Route::post('suppliers/{id}/payments', [SupplierController::class, 'pay'])->whereNumber('id');
        Route::get('shifts/current', [ShiftController::class, 'current']);
        Route::post('shifts/open', [ShiftController::class, 'open']);
        Route::post('shifts/{id}/close', [ShiftController::class, 'close'])->whereNumber('id');
        apiCrud('shifts', ShiftController::class);
        apiCrud('stock-takes', StockTakeController::class);
        Route::post('stock-takes/{id}/counts', [StockTakeController::class, 'counts'])->whereNumber('id');
        Route::post('stock-takes/{id}/post', [StockTakeController::class, 'post'])->whereNumber('id');
        apiCrud('goods-receipts', GoodsReceiptController::class);

        // Finance
        apiCrud('financial-categories', FinancialCategoryController::class);
        apiCrud('financial-periods', FinancialPeriodController::class);
        apiCrud('financial-records', FinancialRecordController::class);

        // Budget / fundraising
        apiCrud('budget-programs', BudgetProgramController::class);
        apiCrud('budget-item-categories', BudgetItemCategoryController::class);
        apiCrud('budget-items', BudgetItemController::class);
        apiCrud('contribution-records', ContributionRecordController::class);

        // Poultry module sync (§Phase 8/9) — mobile-facing, matches the
        // Flutter client's PoultrySyncTransport push/pull contract exactly.
        Route::get('poultry/sync/pull', [PoultrySyncController::class, 'pull']);
        Route::post('poultry/sync/push', [PoultrySyncController::class, 'push']);

        // Find My Phone — device registration, location push, config/command pull.
        Route::post('tracking/devices/register', [TrackingController::class, 'register']);
        Route::post('tracking/devices/{uuid}/locations/batch', [TrackingController::class, 'pushLocations']);
        Route::get('tracking/devices/{uuid}/config', [TrackingController::class, 'getConfig']);
        Route::post('tracking/devices/{uuid}/config', [TrackingController::class, 'updateConfig']);
        Route::post('tracking/devices/{uuid}/commands/{commandId}/ack', [TrackingController::class, 'ackCommand']);
    });
});
