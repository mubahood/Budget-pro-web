# Budget Pro — Backend & API Master Task Plan (SaaS Readiness)

> **Scope of this document:** A ground‑truth analysis of the **web backend + REST API** and a prioritized,
> checkbox‑driven task backlog to bring the API to a *complete, consistent, secure, production‑grade
> multi‑tenant SaaS* — including the "small stuff" the request called out (live search, dropdown/typeahead
> option endpoints, pagination, filtering, sorting).
>
> **Method:** Findings below were produced by reading the full codebase **and by booting the app against
> the live `budget_pro` MySQL database and exercising the API with dummy tenants** (register → login →
> impersonate → CRUD → cross‑tenant probes → cleanup). Where a claim was reproduced at runtime it is marked
> **✅ verified live**. File references use `path:line`.
>
> **Audience & ordering:** Fix top‑down. **Phase 0 (Security)** is a hard gate — the API must not ship to a
> single paying customer until it is done. Mobile app work ("mobo") is intentionally deferred; every task
> here is server‑side.
>
> _Last analyzed: 2026‑08‑15 · Laravel 10 · PHP 8.1+ (running under 8.4) · encore/laravel-admin · MySQL_

---

## ✅ Implementation status (updated 2026-08-16)

A new, versioned, token-authenticated API (`/api/v1`) has been built and verified with an
automated PHPUnit suite (**19 tests / 108 assertions passing** against an isolated
`budget_pro_test` database). Work landed on branch **`api-saas-hardening`**.

**Done**
- **Phase 0 (Security) — complete.** Sanctum bearer-token auth replaces the forgeable
  `logged_in_user_id` param; all `/api/v1` routes require `auth:sanctum` + tenant/subscription
  guards. Removed public `/migrate`, `/generate-models`, and the hardcoded personal
  "wedding" pages; removed the hardcoded personal email from notifications. Generic
  arbitrary-model routes deleted; every write uses an explicit field allow-list (no mass
  assignment — verified). Errors now return correct HTTP status codes via a JSON exception
  handler that never leaks internals. File upload is authenticated + validated. Auth
  endpoints are rate-limited.
- **Phase 1 (Consistency) — complete.** One `{code,message,data,meta,errors}` envelope;
  `/api/v1`; FormRequest validation; API Resources; real HTTP verbs; the two legacy
  controllers (`ApiController` generic methods, `MobileApiController`) removed/retired.
- **Phase 2 (SaaS foundations) — complete incl. payments.** `plans`, `subscriptions`,
  `subscription_invoices` tables + models; `PlanSeeder` (4 tiers, USD + UGX pricing) with
  backfill; `EnsureActiveSubscription` middleware gates lapsed tenants (402); 14-day trial on
  signup. **Flutterwave billing is live** (`FlutterwaveService` + `BillingController`):
  `GET /plans`, `POST /subscription/checkout`, `POST /subscription/verify`,
  `POST /webhooks/flutterwave`. Uganda (UGX) → mobile money/card/bank/USSD; international
  (USD) → card. Confirmation via client-verify **and** webhook, both server-side re-verified
  against the exact amount/currency and idempotent. Verified end-to-end against the real
  Flutterwave API (real hosted links returned for both UGX and USD) + 9 automated tests.
  _Remaining: per-plan quota/feature enforcement (flags/limits exist on plans but aren't
  enforced yet)._
- **Phase 3 (Integrity) — high-value items done.** Added `company_id` indexes to all hot
  tables; fixed the `stock_records` `double(8,2)` money-overflow (→ `decimal(20,2)`).
  _Remaining: FKs, remaining money-type unification, and repairing `migrate:fresh`._
- **Phase 4 (Completeness) — complete.** Full CRUD for all resources, pagination,
  filtering, sorting, `?q=` search, `/options` dropdowns, barcode lookup, POS `checkout`,
  dashboard, company profile.
- **Phase 5 (Correctness) — partial.** Fixed `Utils::my_date_time` minutes bug, the
  `StockCategory` phantom-column crashes, and the admin quick-sale double-deduction.
  _Remaining: discount-price-in-ledger, double-counted report profit, stock oversell locking._
- **Phase 7 (Tests) — API suite done.** Isolated test DB + auth/isolation/CRUD/checkout tests.

**Not yet done (tracked below):** payment provider integration, full FK/migration repair,
remaining Phase 5 math fixes, Phase 6 dead features (auto-reorder/forecasting), admin-panel
IDOR hardening, and porting the Postman collection to v1.

---

## 0. TL;DR — the honest state of the backend

Budget Pro is **two products sharing one tenant table**:

- **(A) Inventory + POS + Finance** — stock items/categories, a stock‑movement ledger, POS sales with
  per‑item profit, income/expense financial records, purchase orders, inventory forecasting, auto‑reorder.
- **(B) Budget / Fundraising** — budget programs → categories → line items, plus contribution (pledge)
  records and cash handovers. This half is effectively a church/community pledge tracker, denominated in UGX.

They share only `Company`, `User`, and `FinancialPeriod`. No report, service, or foreign key joins A to B.

**The web admin (laravel‑admin) is the real, working product.** The dashboard, grids, POS flow, PDF
receipts, and budget rollups function and are (mostly) tenant‑scoped. **The REST API is a thin, insecure
shim** bolted on for a future mobile app, and it is where nearly all the risk and incompleteness lives.

**Verdict:** The API is **not safe to expose to the public internet in its current form.** It has a total
authentication bypass, arbitrary‑model write access, an unauthenticated file upload, and an unauthenticated
`GET /migrate`. The good news: the domain model is rich and the "happy path" works — this is a
security/architecture/consistency problem, not a "start over" problem.

### Severity scoreboard (what this plan fixes)

| Area | State today | Target |
|---|---|---|
| **API authentication** | ❌ Client sends `logged_in_user_id=<any>`; no token | Sanctum bearer tokens, real sessions |
| **Tenant isolation (API)** | ❌ Global scope inert for API; manual & inconsistent | Enforced centrally, defense‑in‑depth |
| **Authorization / roles** | ❌ 9 policies exist, **0 are ever called** | Policy‑gated, role‑aware |
| **Response consistency** | ❌ 3–4 different envelopes; **every error is HTTP 200** | One envelope, correct status codes |
| **Validation** | ❌ Mostly raw `$request->all()` mass‑copy | FormRequests on every write |
| **Pagination / search / sort** | ❌ **Zero** endpoints paginate; `limit(100000)` | Standard pagination + filter + sort |
| **Live search / dropdowns** | ⚠️ 3 exist (unauth); missing for ~15 resources | Uniform `/options` + `/search` layer |
| **CRUD completeness** | ⚠️ No DELETE anywhere; many list/get‑one gaps | Full REST per resource |
| **SaaS billing / plans** | ❌ None. `license_expire` written, never checked | Plans, subscriptions, quota enforcement |
| **Data integrity** | ❌ 4 FKs total, money in `double(8,2)`, `migrate:fresh` fails | FKs, indexes, money types, clean migrations |
| **Tests** | ❌ 1 real test file; 0 API tests | Contract + isolation + business‑logic suites |

---

## 1. Core purpose & feature map (for shared context)

### Module A — Inventory / POS / Finance
- **StockCategory → StockSubCategory → StockItem**: catalog with SKU/barcode, buying/selling price,
  `original_quantity` (immutable) and `current_quantity` (mutated only through the ledger).
- **StockRecord**: the inventory ledger. `type ∈ {Sale, Expired, Other, Stock In}`. On a `Sale` it deducts
  `current_quantity`, computes profit, and **posts an `Income` FinancialRecord** — the general‑ledger link.
- **SaleRecord / SaleRecordItem**: POS sale header + lines; `processAndCompute()` snapshots cost, computes
  profit, creates the stock movement, and derives payment status; generates receipt/invoice numbers + PDFs.
- **FinancialPeriod / FinancialCategory / FinancialRecord / FinancialReport**: fiscal years, chart of
  accounts (auto‑seeded Sales/Purchase/Expense per company), transactions, and 13 period‑type PDF reports.
- **PurchaseOrder / InventoryForecast / AutoReorderRule**: procurement + demand planning (largely
  non‑functional today — see §6).

### Module B — Budget / Fundraising
- **BudgetProgram → BudgetItemCategory → BudgetItem**: 3‑level denormalized budget with upward‑cascading
  rollups (`target_amount`, `invested_amount`, `budget_total/spent/balance`, `percentage_done`).
- **ContributionRecord**: pledges vs. payments (`amount`, `paid_amount`, `not_paid_amount`, `fully_paid`).
- **HandoverRecord**: cash handover between users with approval.

### Tenancy & identity
- **Company** is the tenant root; one owner (`companies.owner_id`), one‑company‑per‑user
  (`admin_users.company_id`), no membership pivot, no org switching.
- **User** extends laravel‑admin's `Administrator` (table `admin_users`); roles via `admin_roles` /
  `admin_role_users` (role **2 = Company Owner**, hardcoded in 4 places).

### ✅ Verified working live (the "happy path")
- `POST /api/auth/register` → creates user + company + owner role + default financial period.
- `POST /api/auth/login` → verifies password, returns user + company.
- `POST /api/mobile/budget-program-save`, `GET /api/mobile/dashboard`, budget CRUD → functional and
  correctly company‑scoped.
- Web admin POS sale → stock deduction is correct and covered by the one real test
  (`tests/Feature/SaleRecordStockDeductionTest.php`).

---

## PHASE 0 — CRITICAL SECURITY (hard gate; do first) 🔴

> Every item here is exploitable today with nothing but `curl`. **✅ verified live** items were reproduced
> against the running app during this analysis. Do not expose the API publicly until Phase 0 is complete.

### 0.1 Replace the fake auth with real token auth
- [ ] **Kill `Utils::get_user()` parameter‑based auth.** `app/Models/Utils.php:90-96` resolves the "logged
  in" user from `$request->get('logged_in_user_id')` — a plain query/body param. **✅ verified live:**
  `GET /api/api/StockItem?logged_in_user_id=44` authenticates as user 44 with zero credentials; iterating
  the ID walks every tenant. This is a total authentication bypass.
- [ ] **Issue Sanctum tokens on login/register.** `HasApiTokens` is already on `app/Models/User.php:40` but
  `createToken()` is never called anywhere. Return a bearer token from `login`/`register`.
- [ ] **Protect all `/api/*` routes with `auth:sanctum`** and resolve the user via `$request->user()`. Add a
  single `resolveUser()`/middleware that every controller uses; delete `Utils::get_user`.
- [ ] **Add logout / token‑refresh / revoke** endpoints (`POST /auth/logout`, token rotation).
- [ ] **Enforce account & tenant status at login:** reject `User.status != 'Active'` and expired/inactive
  `Company` (neither is checked today — `ApiController.php:476-510`).
- [ ] **Stop returning the password hash.** `login`/`register`/`manifest` serialize the full `User`
  including `password`. Add `password`, `remember_token` to `$hidden` and return a DTO/Resource.

### 0.2 Lock down the generic model endpoints
- [ ] **Whitelist models in the legacy generic routes.** `ApiController::my_list`/`my_update`
  (`app/Http/Controllers/ApiController.php:55,408`) do `"App\Models\\".$model` with **no allowlist**.
  **✅ verified live:** `POST /api/api/User` created a real, login‑capable `admin_users` row (privilege
  escalation) with a forged auth param. Also crashes with a **leaked SQL + stack trace** on models without
  a `company_id` column (`Company`, `Gen`, `CodeGen`, `SaleRecordItem`) — **✅ verified live** on `Company`.
- [ ] **Remove or gate `User`/`Company` from every generic write path**, including the mobile whitelist
  (`MobileApiController.php:816` currently allows `POST /api/mobile/save/User`). User creation must go
  through a dedicated, authorized endpoint.
- [ ] **Retire the duplicate insecure legacy methods.** `budget_item_create` (`ApiController.php:60`) and
  `contribution_records_create` (`ApiController.php:335`) are unpatched copies with **no ownership check on
  the edited `id`** (steal another tenant's record by passing its id — it gets re‑stamped to your company)
  and **no company check on `treasurer_id`** (`ApiController.php:347`). Delete them; the `MobileApiController`
  equivalents already do this correctly.

### 0.3 Fix mass assignment on every write
- [ ] **Stop the schema‑column mass‑copy.** Four copies of a loop assign every request key straight onto the
  model (`ApiController.php:79-93, 366-380, 426-440`; `MobileApiController.php:846-851`), bypassing
  `$fillable`. **✅ verified live:** a forged `created_by_id=9999` flowed straight into the INSERT. This lets
  clients write `paid_amount`, `balance`, `profit`, `current_quantity`, `status`, `is_active`, prices, etc.
- [ ] Replace with **explicit allowed‑field lists per endpoint** (or `$request->validated()` from a
  FormRequest — see 1.4). Never trust computed/audit/money‑derived columns from the client.

### 0.4 Unauthenticated endpoints that must die or be locked
- [ ] **Delete `GET /migrate`.** `routes/web.php:28-32` runs `Artisan::call('migrate', ['--force'=>true])`
  for any anonymous visitor. **✅ verified live** (route exists and is public).
- [ ] **Delete `GET /generate-models`.** `routes/web.php:270` invokes a code generator that writes PHP files
  to disk. **✅ verified live** (route exists and is public).
- [ ] **Delete the hardcoded personal wedding pages.** `routes/web.php:33` (`/thanks`) and `:54`
  (`/data-exports-print`) contain a real person's name, three real phone numbers, and a hardcoded 2024
  deadline; `/data-exports-print` dumps **all tenants'** contribution records (no `company_id` filter). **✅
  verified live.** Also remove `mubahood360@gmail.com` hardcoded into every tenant's budget notification
  recipients (`app/Jobs/SendBudgetItemNotification.php:105-107`).
- [ ] **Authenticate + tenant‑scope the PDF routes.** `/financial-report`, `/budget-program-print`,
  `/sale-receipt-pdf`, `/sale-invoice-pdf` (`routes/web.php:140,176,214,241`) take `?id=` with no auth and
  no company check → cross‑tenant IDOR on customer names, amounts, line items.
- [ ] **Lock the file upload.** `POST /api/file-uploading` (`routes/api.php:18` → `Utils::file_upload`,
  `Utils.php:75-88`) has **no auth, no MIME/extension whitelist, no size limit**, keeps the client‑supplied
  extension, and writes under the public web root → arbitrary file write / potential RCE (`shell.php`).
- [ ] **Authenticate the autocomplete closures.** `GET /api/stock-items` and `/api/stock-sub-categories`
  (`routes/api.php:53,85`) trust a **client‑supplied `company_id`** with no auth → anyone reads any tenant's
  catalog. **✅ verified live** (route requires only `company_id` param).

### 0.5 Error handling that doesn't leak and uses real status codes
- [ ] **Replace `Utils::success()`/`Utils::error()`** (`Utils.php:98-121`). They `echo json_encode()` +
  `exit()` at **hardcoded HTTP 200** — so auth failures, validation errors, and crashes all return 200 with
  `{code:0}`. **✅ verified live** (bad login → HTTP 200). `exit()` also bypasses the Laravel response
  pipeline, so **CORS and rate‑limit headers are stripped** and middleware `terminate` never runs.
- [ ] **Stop leaking internals.** Endpoints return `$e->getMessage()` verbatim (raw SQL, table/column names)
  — `ApiController.php:99,174,266`; `MobileApiController.php:241,350,491,678,858`. **✅ verified live**
  (INSERT SQL returned to client). Log the detail; return a generic message + error code.
- [ ] **Add a JSON API exception handler** in `app/Exceptions/Handler.php` so uncaught exceptions on `/api/*`
  return the standard envelope, never an HTML/whoops page.
- [ ] **Set `APP_DEBUG=false`** for any non‑local env (committed as `true` in `.env`/`.env.example:4`).

### 0.6 Make the "SaaS Security Layer" actually work
- [ ] **`EnforceSaasIsolation` is inert for the API.** It only acts under `Auth::check()`
  (`app/Http/Middleware/EnforceSaasIsolation.php:30`), but the API never logs in → no‑op on every API
  request. **✅ verified via code + the auth‑bypass test.** After 0.1 (real auth), make it *reject*
  `company_id` mismatches (today it silently `merge()`s the "right" one at `:67`) and drop the dead
  `$user->user_type` branch (`:56` — the column doesn't exist).
- [ ] **`CompanyScope` is inert for the API too.** It early‑returns when `!Auth::check()`
  (`app/Scopes/CompanyScope.php:21`). Once tokens exist and set the guard user, the global scope will start
  protecting API reads; until then all isolation rests on manual `where()` calls. Verify scope engages
  under token auth, and keep the explicit `where('company_id', …)` as defense‑in‑depth.

### 0.7 Rate limiting & abuse
- [ ] **Throttle auth endpoints.** `RouteServiceProvider.php:29` limits by `user()?->id ?: ip()`; since no
  API request is ever authenticated, it's **IP‑only** and shared behind NAT. Add stricter, dedicated
  throttles on `login` (credential stuffing) and `register` (tenant‑spam): e.g. `throttle:5,1`.
- [ ] Tighten CORS. `config/cors.php:22` is `allowed_origins => ['*']` on `api/*`; combined with the old
  param auth this was "any site reads any tenant from a visitor's browser". Restrict to known app origins.

**Phase 0 exit criteria:** no unauthenticated data/write endpoints; forging identity is impossible; errors
return correct status codes and leak nothing; a security‑review pass (`/security-review`) is clean.

---

## PHASE 1 — API ARCHITECTURE & CONSISTENCY 🟠

> Make the API a coherent, predictable contract. This is the foundation the mobile app and any third party
> will build on, so it must be uniform *to the dot*.

### 1.1 One versioned, RESTful route surface
- [ ] **Introduce `/api/v1`.** `API_VERSIONING_STRATEGY.md` specs this in full but **nothing is
  implemented** (no `v1` segment anywhere). Move all endpoints under `routes/api_v1.php`.
- [ ] **Collapse the two controllers.** `ApiController` (legacy, unsafe) and `MobileApiController` (safer)
  duplicate list/save/budget/contribution logic with divergent safety. Standardize on one set of
  resource controllers; the ~20‑line "schema column loop" upsert is copy‑pasted **4×** — extract once.
- [ ] **Use real HTTP verbs & resource routes.** Today only `GET`/`POST` exist; **no `PUT`/`PATCH`/`DELETE`
  anywhere**. Adopt `Route::apiResource` per domain (`stock-items`, `sales`, `budget-programs`, …).
- [ ] **Remove the web‑session "API" endpoints from the API mental model.** `product_quick_add`,
  `quick_sale_record`, `global_search` (`ApiController.php:118,183,275`) are routed from `routes/web.php`
  and use `Admin::user()` (session + CSRF) — unreachable by a real API client. Either port them to
  token‑auth API endpoints or clearly keep them as admin‑only AJAX.

### 1.2 One response envelope, everywhere
- [ ] **Standardize on `{code, message, data, meta, errors}`** (keep the existing `code:1|0` for backward
  compat) returned through Laravel responses with correct status codes. Today there are **3–4 shapes**:
  `{code,message,data}`, `{success,message,data}`, bare `{data:[...]}`, and Laravel's default 422.
- [ ] **Add `meta` for pagination** (`current_page`, `per_page`, `total`, `last_page`) and `errors` for
  field‑level validation.
- [ ] **Serialize through API Resources**, not raw models. There is **no `app/Http/Resources` directory**;
  some endpoints return Eloquent models, others raw `DB::table()` `stdClass` — so the *same* resource
  serializes differently (dates, `$appends`, `$hidden`) depending on the endpoint.

### 1.3 Consistent identifiers, dates, money in payloads
- [ ] **ISO‑8601 dates** everywhere (mix of `Y-m-d H:i:s` strings and Carbon JSON today).
- [ ] **Money as integer minor units or typed decimal**, with an explicit `currency` field per amount (see
  Phase 3 money tasks). Don't ship bare ints whose scale depends on the tenant.
- [ ] **Fix the register response tenant bug.** `register` returns `company_id: 1` because the user object
  is serialized before the `Company::created` hook back‑fills the real id (`ApiController.php:557,566` vs
  `Company.php:74-85`). **✅ verified live** (register showed `company_id:1`, login showed the real `30`).
  Re‑fetch the user after company creation, or set `company_id` explicitly before returning.

### 1.4 Validation on every write (FormRequests)
- [ ] **Create `app/Http/Requests/*` FormRequests** for every write endpoint. There are **none** today;
  writes use hand‑rolled `if ($x == null)` checks or nothing.
- [ ] Enforce types, lengths, ranges, enum membership, `exists` (tenant‑scoped), and `unique`
  (tenant‑scoped). Register currently has no password min length, no confirmation, and a check‑then‑insert
  email race (`ApiController.php:515-547`); login's email‑format check is commented out (`:483-485`).
- [ ] Return field‑level errors in the standard `errors` object (422).

### 1.5 Observability
- [ ] **Restore audit logging on API writes.** Every API write uses `saveQuietly()` (`ApiController.php:97`,
  `MobileApiController.php:239,348,489,676,856`), which **skips the `AuditLogger` trait and all model boot
  hooks**. Decide per endpoint whether to run hooks (preferred) or explicitly audit.
- [ ] Add request IDs / structured logging for `/api/*`; stop the two **silent empty `catch`** blocks that
  swallow rollup failures with a "log but don't fail" comment and then don't log
  (`MobileApiController.php:495-499, 686-690`).

---

## PHASE 2 — SaaS FOUNDATIONS (think beyond CRUD) 🟠

> The request is explicit: **this is a SaaS — plan everything in that context.** Today there is **no billing,
> no plans, no quota enforcement, and licensing is decorative.** These are net‑new capabilities.

### 2.1 Plans, subscriptions, billing
- [ ] **Add a plans/subscriptions schema:** `plans` (name, price, interval, feature flags, quotas),
  `subscriptions` (company_id, plan_id, status, trial_ends_at, current_period_end, provider refs),
  `invoices`/`payments`. None exist today (`composer.json` has no Cashier/Stripe/PayPal/Flutterwave).
- [ ] **Pick a billing integration** appropriate to the target market (Stripe for cards; Flutterwave/Pesapal
  for East African mobile money given the UGX orientation). Wire webhooks → subscription status.
- [ ] **Free trial → paid conversion flow**, dunning, and grace periods.

### 2.2 Enforce entitlements & the license that already "exists"
- [ ] **Actually check `companies.license_expire` and `companies.status`.** Both are written at signup and
  **never read for enforcement anywhere** (only rendered in two grids). Add middleware that blocks/streams
  a "subscription expired" state for expired/inactive tenants (web + API).
- [ ] **Quota enforcement per plan:** max users, stock items, sales/month, budget programs, storage. Enforce
  at the service layer and surface remaining quota via the API.
- [ ] **Feature gating** driven by plan flags (e.g. forecasting, auto‑reorder, multi‑user, API access).

### 2.3 Multi‑user tenancy done properly
- [ ] **Team management API:** invite/list/update/deactivate members, assign roles, resend invite.
- [ ] **Real invitation flow.** None exists — creating an employee silently sets the password to
  `bcrypt('admin')` (`app/Models/User.php:79-81`) and the UI *claims* "credentials sent via SMS/Email" with
  no mail/SMS code behind it. Implement token‑based invites + forced first‑login password set.
- [ ] **Wire up the per‑worker permission flags.** `companies.settings_worker_can_*` exist in the schema but
  are **never read anywhere in `app/`**. Either enforce them (authorization) or remove them.
- [ ] **Company‑scoped roles.** Roles (`Worker`, `Treasurer`, …) are currently **global** across all tenants;
  the vanilla laravel‑admin role/user pages let a tenant edit *all* tenants' users. Scope role/user admin
  per company, and stop hardcoding role id `2` (it's a literal in `User.php:132`,
  `EnsureCompanyOwnerRole.php:37`, `AuthController.php:156`, `ApiController.php:594`; **no seeder even
  creates it** → fresh installs dangle).

### 2.4 Onboarding & account lifecycle
- [ ] **Public signup hardening:** email verification, terms acceptance, captcha/rate‑limit, currency from an
  ISO‑4217 whitelist (see §3), and an atomic transaction so a half‑created tenant can't be orphaned in
  company 1 (today `register` sets `company_id = 1` first and can `exit()` mid‑flow).
- [ ] **Auth lifecycle API:** forgot/reset password, change password, verify email, profile update — **none
  exist**.
- [ ] **Company profile API:** update logo, currency, address, tax settings — **no endpoint exists** (only
  register creates a company).

### 2.5 Super‑admin / operator console
- [ ] **Repair the broken super‑admin distinction.** Code branches on `$user->user_type === 'admin'`
  (`CompanyController.php:33,127,169`; `EnforceSaasIsolation.php:56`) but **`admin_users` has no `user_type`
  column** → the branch never fires, so the license/status/owner fields are permanently hidden and nobody can
  renew a license via the UI. Add a proper platform‑admin role and a tenant‑management console (list
  tenants, impersonate, suspend, adjust plan).
- [ ] **Fix the "every company shows Inactive" bug:** grid compares `status == 'active'` (lowercase) while
  writes store `'Active'` (`CompanyController.php:58`).

---

## PHASE 3 — DATA INTEGRITY & SCHEMA 🟠

> The database does not match the migrations, has almost no constraints, and stores money in types that are
> **already overflowing in live data.**

### 3.1 Money types (live corruption risk)
- [ ] **Fix undersized money columns.** `stock_records.quantity`, `selling_price`, `total_sales` are
  `double(8,2)` → **max 999,999.99**. Live `total_sales` already reaches 450,000 and a single item sells at
  260,000 UGX; a two‑item sale overflows. This is an imminent, not theoretical, bug.
- [ ] **Unify money storage.** Today it's a mix: `bigInteger` (budget/stock/contribution), `decimal(15,2)`
  (sales/PO/financial — correct), and `float` (`total_sales`, `selling_price`) and even `string`
  (`contribution_records.custom_amount`). **✅ verified** across migrations. Pick one representation
  (recommend `decimal(20,2)` or integer minor units) and migrate all money columns to it.
- [ ] **Stop casting `decimal:2` over `bigint` columns.** Models declare `'buying_price'=>'decimal:2'` etc.
  against integer columns, silently truncating cents on write and faking `.00` on read
  (`StockItem.php:36-43`, `StockRecord.php:32-41`).
- [ ] **Fix `percentage_done` stored as `bigint`** while code computes `round(…,2)` — decimals discarded
  (`BudgetItem.php:147`, `BudgetItemCategory.php:97`).

### 3.2 Foreign keys & referential integrity
- [ ] **Add foreign keys.** The entire DB has **4 FKs**; every `company_id`, `stock_item_id`,
  `budget_program_id`, `financial_period_id`, `created_by_id`, `treasurer_id` is an unconstrained int.
  `foreignIdFor()` was used but never `->constrained()`.
- [ ] **Fix the audit FK pointing at the wrong table.** `audit_logs.user_id → users.id`, but the app's users
  live in `admin_users` (the `users` table has 0 rows). Result: **1481/1481 audit rows have
  `user_id = NULL`** — attribution is 100% broken (`app/Traits/AuditLogger.php:51-55`).
- [ ] **Reconcile column type mismatches that currently block FKs:** `admin_users.company_id int(11)` vs
  `companies.id bigint unsigned`; `companies.owner_id int(11)` vs `admin_users.id int unsigned`;
  budget/financial `company_id bigint signed` vs `companies.id bigint unsigned`.

### 3.3 Indexes (performance)
- [ ] **Add `company_id` indexes to every scoped table.** Only 5 tables have one; `CompanyScope` appends
  `WHERE company_id = ?` to *every* query on 16 models, so the app is doing full table scans on
  `stock_items`, `stock_records`, all `financial_*`, all `budget_*`, `contribution_records`, `admin_users`,
  etc. Note migration `2025_11_08_071924` has its `stock_items` index **commented out** and a `down()` that
  drops an index it never created.
- [ ] **Cache the schema lookup.** `CompanyScope::hasCompanyIdColumn()` runs `Schema::getColumnListing()` —
  an `INFORMATION_SCHEMA` query — on **every scoped query build** (`app/Scopes/CompanyScope.php:46-54`).
  Cache per model/class.
- [ ] **Reduce default eager loading.** 9 models set `$with` that fans out (a `StockRecord` pulls item → sub
  → category → company), turning a 100‑row list into hundreds of unindexed `company_id` queries.

### 3.4 Repair migrations so the schema is reproducible
- [ ] **`migrate:fresh` fails today.** 12 budget‑module migrations begin with a bare `return;` before
  `Schema::create` (so the tables are never created), yet later `Schema::table('budget_programs', …)`
  migrations run and fatal. Rebuild these migrations from the live schema.
- [ ] Remove/fix the migration targeting a non‑existent `organisations` table
  (`2024_05_31_111210`), and the self‑nested `Schema::table` in `2024_05_31_112507`.
- [ ] Add migrations for columns that exist in MySQL but not in any migration (`budget_items.priority`,
  `contribution_records.category_id`).
- [ ] Commit a canonical `schema.sql` dump and verify `migrate:fresh --seed` builds a working DB in CI.

### 3.5 Constraints, nullability, string‑typed data
- [ ] **Add unique indexes** currently enforced only by check‑then‑insert PHP (races): `stock_items.sku`
  (per company), `admin_users.email`, `budget_items(name, category)`, `budget_item_categories(name,
  program)`, `contribution_records(name, program)`, `financial_categories(company_id, name)`.
- [ ] **Tighten nullability:** `financial_records.company_id/amount/type/date` and every budget table's
  `company_id` are nullable — a NULL `company_id` row is invisible to `CompanyScope` and orphaned.
- [ ] **Convert boolean/enum "Yes/No" varchars** (`fully_paid`, `is_complete`, `approved`, `in_stock`, all
  `settings_worker_can_*`, financial report flags) to real booleans/enums.
- [ ] **Convert money/FK columns stored as `varchar`** (`contribution_records.custom_amount`,
  `custom_paid_amount`, `category_id`; `data_exports.category_id`) to proper types.
- [ ] **Add `company_id` (or documented parent‑scoping) to `sale_record_items`** and confirm it can't be
  read cross‑tenant via `find()`.

---

## PHASE 4 — API COMPLETENESS (CRUD, pagination, filtering, search, dropdowns) 🟡

> This is the "100% complete, up to the dot" layer the request emphasized — including **live search and
> dropdown/typeahead option endpoints for every resource.**

### 4.1 Full CRUD for every domain resource
Provide `list / show / create / update / delete` for each. Status today (⚠️ = only via the untyped generic
route; ❌ = missing):

| Resource | list | show | create | update | delete |
|---|---|---|---|---|---|
| StockItem | ⚠️ | ❌ | ⚠️ | ⚠️ | ❌ |
| StockCategory | ⚠️ | ❌ | ⚠️ | ⚠️ | ❌ |
| StockSubCategory | ⚠️ | ❌ | ⚠️ | ⚠️ | ❌ |
| StockRecord | ⚠️ | ❌ | ⚠️ | ⚠️ | ❌ |
| SaleRecord | ⚠️ | ❌ | ⚠️ | ⚠️ | ❌ |
| FinancialRecord/Category/Period/Report | ⚠️ | ❌ | ⚠️ | ⚠️ | ❌ |
| PurchaseOrder | ⚠️ | ❌ | ⚠️ | ⚠️ | ❌ |
| InventoryForecast / AutoReorderRule | ⚠️ | ❌ | ⚠️ | ⚠️ | ❌ |
| BudgetProgram | ✅ | ✅ | ✅ | ✅ | ❌ |
| BudgetItemCategory / BudgetItem / ContributionRecord | ✅ | ❌ | ✅ | ✅ | ❌ |
| Company (profile) | ❌ | (manifest) | (register) | ❌ | ❌ |
| User / Employee | ⚠️ | ❌ | ⚠️ | ⚠️ | ❌ |

- [ ] **Build typed, validated, policy‑gated CRUD** for all of the above (replacing generic routes).
- [ ] **Add DELETE (soft‑delete) support** — there is no `Route::delete` in the codebase; add soft deletes +
  restore where destructive.
- [ ] **Add `show` (get‑one)** for every resource; several lists have no detail endpoint.
- [ ] **Domain actions as endpoints:** POS checkout, sale refund/return, PO approve/receive, forecast
  generate, auto‑reorder trigger, financial report generate/download, period close. Several exist only as
  admin‑web actions today.

### 4.2 Pagination — required on every list
- [ ] **No endpoint paginates today** (`my_list` uses `limit(100000)`, `genericList` `limit(10000)`, budget
  lists are unbounded `->get()`). Add cursor or page pagination with `per_page` (capped) and `meta`.
- [ ] Kill the N+1s in list endpoints (`budgetPrograms` runs 4 queries/row; `budgetItems`,
  `contributionRecords` similar) — eager‑load or aggregate in SQL.

### 4.3 Filtering & sorting — uniform query grammar
- [ ] Adopt a consistent filter syntax (e.g. `?filter[status]=Active&filter[created_at][gte]=…`). Only 3
  endpoints support any filter today, all exact‑match on a single FK.
- [ ] Support `sort=-created_at,name`. Sorting is hardcoded everywhere today (no client control).
- [ ] Add domain filters that clients will need: date ranges, status, amount ranges, `fully_paid`,
  `is_active`, low‑stock, category/period.

### 4.4 Live search + dropdown/option endpoints (explicitly requested)
- [ ] **Uniform `GET /api/v1/{resource}/options`** returning `{id, text}` (Select2‑style) for **every**
  lookup used in forms/dropdowns: StockCategory, StockSubCategory, StockItem, BudgetProgram,
  BudgetItemCategory, BudgetItem, FinancialCategory, FinancialPeriod, User/employee (treasurer picker),
  PurchaseOrder supplier, Company. Today only StockItem & StockSubCategory have this — and **unauthenticated
  with client‑supplied `company_id`** (fix under 0.4).
- [ ] **Typeahead/live search per resource** (`?q=`), debounce‑friendly, `limit`‑capped, searching the
  fields users expect (StockItem search should cover name **+ SKU + barcode**, not just name).
- [ ] **A global search API** (products, categories, sales, budgets, contributions) — one exists but is
  web‑session only (`global_search`, `ApiController.php:275`); port to token auth.
- [ ] **Barcode/SKU lookup endpoint** for POS scanning (`GET /api/v1/stock-items/by-barcode/{code}`).
- [ ] Standard `{id, text, …extra}` option shape and pagination on option endpoints (large catalogs).

### 4.5 Bulk, import/export, and reporting APIs
- [ ] Bulk create/update/delete endpoints (there are 28 admin batch actions, only 7 wired — mirror the useful
  ones in the API).
- [ ] CSV/Excel import + export endpoints (stock, sales, contributions).
- [ ] Reporting API: sales report, stock valuation, profit/loss, low‑stock, period summaries — **the entire
  reports domain is admin‑web only today.**
- [ ] Mobile‑friendly **sync/delta endpoint** (updated‑since) for offline support (design now, implement with
  mobo).

---

## PHASE 5 — BUSINESS‑LOGIC CORRECTNESS 🟡

> Bugs where the math is simply wrong. Several are masked because API writes use `saveQuietly()` and skip the
> hooks — but the web app hits them, and any API that runs hooks will inherit them.

- [ ] **Discounted sale price is discarded in the ledger.** `SaleRecord::processAndCompute` sets the
  negotiated `unit_price`, but `StockRecord::creating` overwrites `selling_price`/`total_sales`/`profit`
  from the catalog list price (`StockRecord.php:109-117`), and the posted `Income` FinancialRecord uses
  that list price (`:190`). Discounts/overrides silently mis‑state revenue.
- [ ] **Profit & revenue double‑counted in reports.** `FinancialReportService::getSummaryStatistics()` adds
  `financial_records` totals **and** `sale_records` totals, but every sale already wrote an Income
  financial record → sales counted twice (`:218-230`).
- [ ] **"Fully paid" destroys the real receipt figure.** `ContributionRecord::prepare()` overwrites
  `paid_amount = amount` when `fully_paid == 'Yes'` (`:146`), so a 60%‑collected pledge marked paid reports
  100% collected up the rollup. Also `(int)`‑truncates amounts (`:136`).
- [ ] **`StockCategory` references 4 non‑existent columns** (`current_quantity`, `reorder_level`,
  `measurement_unit`, `code`) in casts/fillable/accessors/scopes → SQL errors if those paths run;
  `getNameTextAttribute` always renders `Name ()` (`StockCategory.php:37-53,92,141-151`).
- [ ] **`scopeLowStock` bugs:** `StockCategory` compares a column to the string literal `'reorder_level'`
  (`:141`); `StockItem` hardcodes `< 10` ignoring per‑subcategory `reorder_level` (`StockItem.php:342`).
- [ ] **Inventory value never depreciates** — category rollups multiply by `original_quantity`
  (`StockCategory.php:71`, `StockSubCategory.php:75`), so "inventory value" reflects lifetime purchases, not
  holdings.
- [ ] **`is_complete` uses two different thresholds** (`>=98` for items vs `>=98 || balance<=0` for
  categories) → item/category completion disagree.
- [ ] **`getPercentageDoneAttribute` clamps to 100**, hiding over‑budget categories
  (`BudgetItemCategory.php:123-136`).
- [ ] **Concurrency: stock oversell.** Read‑modify‑write on `current_quantity` with **no row locking
  anywhere** (`lockForUpdate`/`increment`/`decrement` grep‑empty). Two concurrent sales both pass the check
  and both write absolutes → lost update / negative stock. Use atomic `decrement` + `lockForUpdate` in a
  transaction. Same class of bug in `generatePONumber`/`generateUniqueReceiptNumber` (read‑max‑then‑+1).
- [ ] **`Utils::my_date_time()` prints month where minutes belong** — format `'d M, Y - h:m a'` (`m` is
  month) (`Utils.php:61`). Fix to `i`.
- [ ] **`Utils::generateSKU()` uses `COUNT(*)+1`** with no company scope → collisions after deletes
  (`Utils.php:134`).
- [ ] **Cache invalidation never happens** — `Cache::forget()` called with glob patterns it doesn't support
  (`CacheService.php:158-172`, `FinancialReportService.php:236-245`).
- [ ] **Email is entirely disabled** — `Utils::mail_sender()` and `Utils::importRecs()` start with a bare
  `return;` (`Utils.php:346,404`). All "notification sent" flows are no‑ops. Implement real mail/queue.

---

## PHASE 6 — NON‑FUNCTIONAL / DEAD FEATURES 🟢

> Features advertised as done that don't run. Either fix or remove before selling them.

- [ ] **AutoReorderService cannot execute** — imports/creates `App\Models\PurchaseOrderItem` (no such class,
  no such table), calls `->sum()` on an array‑cast `items` field, writes a status outside the enum, and
  derives annual demand from the order size (circular EOQ) (`app/Services/AutoReorderService.php`).
- [ ] **InventoryForecastService** queries `type IN ('sale','stock_out')` but real values are
  `Sale`/`Expired`/`Other` (`'stock_out'` never matches); unguarded divide‑by‑zero in trend/seasonality
  (`:217,286`).
- [ ] **`UpdateFinancialCategoryAggregates` job is a permanent no‑op** — guards on
  `method_exists(…, 'update_self')` which `FinancialCategory` lacks, so `total_income`/`total_expense` stay
  zero (`app/Jobs/UpdateFinancialCategoryAggregates.php:42`).
- [ ] **PurchaseOrder / InventoryForecast / HandoverRecord admin grids are raw scaffold** (every column
  dumped, no scoping on some) — finish or hide.
- [ ] **HandoverRecord grid leaks across tenants** — no `company_id` filter and shows the column
  (`app/Admin/Controllers/HandoverRecordController.php:25-41`). (Model also has no `CompanyScope`.)
- [ ] **IDOR on every admin resource** — no controller re‑checks `company_id` on `show/edit/update/destroy`;
  laravel‑admin loads by id via `findOrFail`, so `/{resource}/{id-of-another-tenant}` works. Add a global
  tenant guard (e.g. `Model::forCompany()` in `edit`/`update`/`destroy`, or a trait).
- [ ] **Invoke the 9 policies** (registered in `AuthServiceProvider`, never called) or delete them; add the
  missing ones (SaleRecord, Company, PurchaseOrder, …). Fix their `===` int/bigint comparisons.

---

## PHASE 7 — TESTING, DOCS, DEVEX, HYGIENE 🟢

### 7.1 Tests (currently ~1 real test, 0 API tests)
- [ ] **Enable an isolated test DB.** `phpunit.xml:24-25` has the sqlite lines commented out, so
  `RefreshDatabase` runs against the **real** `budget_pro` DB. Point tests at sqlite `:memory:` or a
  dedicated test schema.
- [ ] **API contract tests** for every endpoint: auth required, envelope shape, status codes, pagination.
- [ ] **Multi‑tenant isolation tests** (the crown jewel): tenant A must never read/write tenant B — for each
  resource, each verb. (These would have caught every Phase 0 bug.)
- [ ] **Business‑logic tests**: stock deduction (exists), sale profit with discount, contribution
  reconciliation, budget rollups, concurrency/oversell.
- [ ] **Add model factories.** Only `UserFactory` exists (and it's broken — writes `email_verified_at` which
  `admin_users` lacks). Add factories for all domain models so tests are writable.
- [ ] Keep/port `BudgetTestSeeder` assertions into PHPUnit (today it's an artisan command that bypasses
  Eloquent, so it never exercises the hooks it claims to verify, and asserts around known bugs).

### 7.2 Docs
- [ ] **Rewrite `API_DOCUMENTATION.md` to match reality** — it promises Sanctum bearer auth that doesn't
  exist, documents client‑supplied `company_id` as intended, and omits the entire `mobile/*` namespace.
- [ ] **Regenerate the Postman collection** (add mobile endpoints, fix auth).
- [ ] Consider **OpenAPI/Swagger** generation as the single source of truth once Phase 1 lands.
- [ ] Reconcile the contradictory status docs (version 1.0.0 vs 2.0.0; completion 48%↔95%; "tests passing"
  both checked and unchecked). Delete stale `SESSION_*`, `PHASE_1_*`, `*-old`, `*.backup` files.

### 7.3 Hygiene / cleanup
- [ ] Remove dead code: ~1,400 lines of unused traits (6 of 7, some referencing a non‑existent
  `spent_amount` column), `Gen`/`CodeGen` model generators exposed via admin routes, `ExampleController`
  referencing a missing model, `CompleteDemoSeeder` (imports non‑existent classes), empty
  `DatabaseSeeder`, `dashboard-old.blade.php`.
- [ ] Remove emoji from API payloads (`ApiController.php:161`).
- [ ] Fix the shadowed `auto-reorder-rules/trigger` route (registered after the resource route, so it 404s).
- [ ] Standardize the admin auth accessor (mix of `Admin::user()`, `auth()->user()`, `\Encore\…::user()`),
  and add null guards (several controllers deref `->company_id` on a possibly‑null user).
- [ ] Move `ValidationService::removeSqlInjectionPatterns()` out of the write path — it corrupts legitimate
  input (strips `#`, `or … =`) while adding no real protection over parameterized queries.
- [ ] Fix the PHP 8.4 deprecation noise (Laravel 10 on PHP 8.4) — either pin PHP 8.2/8.3 for the target
  environment or upgrade the framework; document the supported PHP range.

---

## Suggested execution order (milestones)

1. **M0 — Security gate (Phase 0):** real token auth, kill unauth endpoints, fix mass assignment, correct
   status codes + no leaks. *Nothing ships before this.*
2. **M1 — Contract (Phase 1) + integrity quick wins (3.1 money overflow, 3.4 migrations):** one envelope,
   FormRequests, v1 routes, stop imminent data corruption.
3. **M2 — SaaS core (Phase 2):** plans/subscriptions/billing, entitlement enforcement, team + invitations,
   onboarding, operator console.
4. **M3 — API completeness (Phase 4):** full CRUD, pagination, filtering/sorting, live search + dropdown
   option endpoints, reporting API.
5. **M4 — Correctness + dead features (Phases 5–6):** fix the math, make forecasting/auto‑reorder either work
   or disappear, close admin IDOR, invoke policies.
6. **M5 — Hardening (Phases 3 remainder + 7):** FKs/indexes/constraints, full test suite, docs, cleanup.

---

## Appendix — how these findings were verified

Against the live app (dummy tenants created and cleaned up during analysis):

- **Auth bypass:** listed data as arbitrary users by changing `?logged_in_user_id=`. → HTTP 200.
- **Privilege escalation:** `POST /api/api/User` created a real `admin_users` row (rogue login user).
- **Mass assignment:** forged `created_by_id=9999` appeared verbatim in the INSERT.
- **Error handling:** failed login returned **HTTP 200**; a generic‑list call on `Company` returned a full
  **SQL error + stack trace** (APP_DEBUG=true).
- **Public danger routes:** confirmed `GET /migrate` and `GET /generate-models` exist and are unauthenticated;
  confirmed the hardcoded personal wedding pages in `routes/web.php`.
- **Happy path:** register → login → create budget program → dashboard all succeed and are company‑scoped.
- **Schema:** confirmed money column types (`double(8,2)`/`bigint`/`decimal`/`string` mix) and that only
  `sale_record_items`, `companies`, `gens`, `code_gens` lack a `company_id` column.
- **Register bug:** register response returns `company_id:1`; login returns the real id (hook back‑fills).

All dummy users/companies/records created during testing were deleted afterward; the database was left clean.
