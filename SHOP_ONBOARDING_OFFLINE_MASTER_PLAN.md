# Budget Pro — Shop Management, Onboarding & Offline-First Master Plan

> **Purpose.** A ground-truth audit of the whole system (Laravel backend at `/Applications/MAMP/htdocs/budget-pro`
> + Flutter app "Budget Dynamics" at `/Users/mac/Desktop/github/budget-pro-mobo`) followed by a detailed,
> prioritised plan to make it **robust, complete and genuinely offline-first**, with focus on three areas:
>
> 1. **Shop management** (inventory, POS/sales, purchasing, ledger, reports)
> 2. **Onboarding** (signup → first sale in under 10 minutes, on web and mobile)
> 3. **Offline capability** — a shop must run **fully offline** (sell, restock, take stock, print/share
>    receipts, see today's numbers) on one or several phones, and sync cleanly later.
>
> **Method.** Every claim in Part 1 was produced by reading the code of both repos (file:line refs are
> given) and, where noted, by querying the live database and exercising the API. Nothing here is guessed
> from documentation — several earlier docs (`BACKEND_API_MASTER_TASKS.md`, `POULTRY_MODULE_MASTER_PLAN.md`)
> turned out to be stale in places, so **this document supersedes them where they conflict**.
>
> **How to read it.** Part 1 is "where we are". Parts 2–5 are "where we're going", by area. Part 6 is the
> phased roadmap with a checkbox backlog. The appendices hold the concrete specs (sync protocol, schemas,
> API contracts) so implementation can start from them directly.
>
> _Analysed: 2026-09-24 · Laravel 10 / PHP 8.3 / MySQL · Flutter (sqflite + dio + GetX for navigation)_

---

## Table of contents

- [0. Executive summary](#0-executive-summary)
- [1. Ground truth — what exists today](#1-ground-truth--what-exists-today)
  - [1.1 Backend shop module](#11-backend-shop-module)
  - [1.2 Mobile app shop module](#12-mobile-app-shop-module)
  - [1.3 Existing sync engines (three of them)](#13-existing-sync-engines-three-of-them)
  - [1.4 Onboarding, identity, tenancy, billing](#14-onboarding-identity-tenancy-billing)
  - [1.5 Consolidated defect register](#15-consolidated-defect-register)
- [2. Vision & design principles](#2-vision--design-principles)
- [3. Part A — Shop management: the target design](#3-part-a--shop-management-the-target-design)
- [4. Part B — Offline-first architecture](#4-part-b--offline-first-architecture)
- [5. Part C — Onboarding, identity & team](#5-part-c--onboarding-identity--team)
- [6. Part D — Cross-cutting quality, security, operations](#6-part-d--cross-cutting-quality-security-operations)
- [7. Part E — Differentiating ideas (the creative backlog)](#7-part-e--differentiating-ideas-the-creative-backlog)
- [8. Part F — Phased roadmap & checkbox backlog](#8-part-f--phased-roadmap--checkbox-backlog)
- [Appendix A — Sync protocol v2 specification](#appendix-a--sync-protocol-v2-specification)
- [Appendix B — Target database schema changes](#appendix-b--target-database-schema-changes)
- [Appendix C — Mobile local schema & outbox](#appendix-c--mobile-local-schema--outbox)
- [Appendix D — Offline numbering scheme](#appendix-d--offline-numbering-scheme)
- [Appendix E — Conflict resolution matrix](#appendix-e--conflict-resolution-matrix)
- [Appendix F — Onboarding wizard spec](#appendix-f--onboarding-wizard-spec)
- [Appendix G — Decision log](#appendix-g--decision-log)
- [Appendix H — Open questions](#appendix-h--open-questions)

---

## 0. Executive summary

### Where we are (one paragraph)

The backend has a **solid, tenant-scoped, token-authenticated `/api/v1`** with CRUD for every shop resource,
a working POS `checkout`, Flutterwave billing and a passing test suite. The mobile app has a **genuinely
offline-first poultry module** with client UUIDs, tombstones, a push/pull sync service and a status UI.
But the **shop module on mobile is online-only** (every write fails offline; there is no POS, cart,
receipt, customer, barcode or restock screen at all), and the **backend shop math has real correctness
holes**: restocking is effectively broken, discounts are dropped from the ledger, stock is never locked
(oversell + lost updates under concurrency), receipt/PO numbers collide across tenants, deleting a sale
leaves its income in the ledger, and business-rule errors surface as HTTP 500. Onboarding is three
divergent signup flows, a dashboard of zeros, no wizard, no staff invite (every employee's password is
literally `admin`), no forgot-password, no emails at all, and an admin panel where **every role has `*`
permission — any tenant user can open the global Subscriptions screen and activate their own plan.**

### The twelve findings that matter most

| # | Finding | Where | Severity |
|---|---|---|---|
| 1 | Every admin role has permission `*`; Billing/Plans/Subscriptions and global Users/Roles are reachable by any tenant user, with no company filter | `admin_role_permissions`, `app/Admin/Controllers/SubscriptionController.php:24-117` | **Critical (security)** |
| 2 | Shop writes on mobile are impossible offline; no POS exists; the half-built generic sync layer (`SyncEngine`/`OfflineStore`) is wired to nothing | `mobo lib/services/*`, `lib/model/Utils.dart:198-204` | **Critical (product)** |
| 3 | Only `type='Sale'` changes stock; "Stock In"/"Damage"/"Expired"/"Lost" are accepted but do nothing — and Stock In is *rejected* if it exceeds on-hand stock. There is no restock path anywhere | `app/Models/StockRecord.php:124-127, 146-158` | **Critical (correctness)** |
| 4 | No `lockForUpdate`/atomic decrement anywhere in shop code → oversell and lost updates under concurrency; no idempotency key → mobile retries double-sell | `SaleRecord.php:319-503`, `SaleController.php:43-110` | **Critical (correctness)** |
| 5 | Discounted line price is kept on the sale but the stock record + Income ledger entry are recomputed from **list price**; ledger always says `Cash` and full total even for credit sales; deleting a sale/stock record never reverses the ledger | `StockRecord.php:109-117, 170-200, 205-230` | **High (money)** |
| 6 | `receipt_number`, `invoice_number`, `po_number` are UNIQUE **globally** but generated per company from a 3-letter company prefix + date + counter → two "ABC…" companies collide; impossible to generate offline | `SaleRecord.php:217-285`, `PurchaseOrder.php:181-197` | **High** |
| 7 | Shop models throw plain `\Exception` → HTTP 500 for insufficient stock, closed period, immutable field etc. (only `BusinessRuleException` maps to 422) | `BaseCrudController.php:149-151`, all shop models | **High (API stability)** |
| 8 | Poultry sync (the pattern to generalise) uses the **device clock as the pull cursor** → rows from a skewed/long-offline device are never pulled by others; page boundary skips; one-row-per-request push; raw `DB::table` writes bypass all model logic; conflict log nobody can see | `app/Traits/PoultrySyncable.php:63-137`, `mobo lib/poultry/services/poultry_sync_service.dart` | **High (sync design)** |
| 9 | Three signup paths produce three different tenants (14-day trial vs 1-year free, subscription row or not, `company_members` row or not, `'Active'` vs `'active'`); web admin never checks subscription/expiry at all | `Api/V1/AuthController.php:30-108`, `ApiController.php:371-495`, `Admin/Controllers/AuthController.php:51-219` | **High** |
| 10 | Adding a cashier is broken: no password field (defaults to `admin`), no role assigned (route permission then fails), email optional but username = email, "credentials sent via SMS" is fiction; roles 3/4/5 are never used; `settings_worker_can_*` flags are never read | `EmployeesController.php`, `User.php:64-84`, `Company.php` | **High** |
| 11 | All email is disabled (`Utils::mail_sender` returns immediately); no welcome/reset/verify/trial/dunning/low-stock notifications; queue is `sync`; scheduler runs nothing shop-related | `Utils.php:404-406`, `app/Console/Kernel.php:13-18` | **High** |
| 12 | Logout on mobile doesn't clear tenant data; poultry tables have no `company_id` → next user on the same phone sees/pushes the previous tenant's data; token stored in plaintext SQLite | `mobo HomeScreen.dart:84-88`, `LoggedInUser.dart:73-119` | **High (security)** |

### Where we're going (the vision in one paragraph)

A shop owner in Kampala installs the app, registers with a phone number and OTP, picks "Retail shop /
UGX / Africa-Kampala", imports 30 products from a template, invites a cashier by WhatsApp link, and
makes the first sale — **all in under ten minutes, most of it while the data bundle is off.** From then on
the phone is the till: barcode or search → cart → cash/MoMo/credit → receipt shared to the customer's
WhatsApp, even in a basement with no signal. Two phones behind the same counter never oversell each other
by accident and reconcile automatically when they're back online. The owner opens the web dashboard from
the internet café and sees the same numbers. The system never *loses* a sale, never *invents* one, and
every shilling in the ledger can be traced back to the movement that produced it.

### What this plan delivers, in order

- **Phase 0 (weeks 1–2): Stop the bleeding.** Security holes (#1, #12), correctness holes (#3–#7), unify
  signup (#9), staff creation (#10). No new features — but the system becomes *trustworthy*.
- **Phase 1 (weeks 3–6): Offline foundation.** One sync engine (v2 protocol, Appendix A), event-sourced
  stock movements, offline numbering, outbox + background sync, tenant-safe local storage.
- **Phase 2 (weeks 6–10): Real POS.** Cart, payments (cash/MoMo/credit/split), receipts (WhatsApp share,
  thermal print), customers & credit book, barcode, stock-in/adjustments/stock-take — all offline-capable.
- **Phase 3 (weeks 10–13): Onboarding & team.** Unified signup, OTP, setup wizard, templates/import, demo
  mode, staff roles + invites, notifications, tenant billing screen, grace periods.
- **Phase 4 (weeks 13–17): Purchasing, reports, analytics.** Suppliers, PO lifecycle → GRN → stock, real
  reporting API, fixed forecasting/reorder (or removed), quota enforcement.
- **Phase 5 (ongoing): Differentiators** from Part E.

---

## 1. Ground truth — what exists today

### 1.1 Backend shop module

**Entities that exist:** `StockCategory` → `StockSubCategory` (holds `measurement_unit`, `reorder_level`,
`in_stock`) → `StockItem` (prices, `original_quantity`, `current_quantity`, `sku`, `barcode`) →
`StockRecord` (the movement ledger, `type` free string) and `SaleRecord`/`SaleRecordItem` (the POS
header/lines), `FinancialRecord`/`FinancialCategory`/`FinancialPeriod` (general ledger), `PurchaseOrder`
(JSON `items`, inline supplier strings), `InventoryForecast`, `AutoReorderRule`.

**Entities that do NOT exist (confirmed against migrations):** Customer, Supplier, Unit-of-measure (per
item), Product variant, Batch/Lot, Expiry, Price list, Tax rate, Discount, Payment (per sale), Return /
Refund / Credit note, Stock take / Adjustment document, Stock transfer / Location / Warehouse, Till /
Shift / Cash-up.

**Money & quantity types are a mix** (see the table in §1.5): `stock_items` prices and quantities are
`bigint` cast as `decimal:2` (fractions silently truncated; 2.5 kg cannot exist), `sale_records` and
`sale_record_items` are `decimal(15,2)`, `financial_records.amount` is `bigint`. The API accepts
`quantity >= 0.01` while `StockRecord::creating` rejects `< 1`.

**How a sale works today** (`app/Http/Controllers/Api/V1/SaleController.php:43-110` →
`app/Models/SaleRecord.php:319-503` `processAndCompute()` → `app/Models/StockRecord.php:82-202`):

1. Header saved (receipt/invoice numbers generated) **outside any transaction**; line items saved one by
   one, also outside a transaction; then `processAndCompute()` opens its own transaction.
2. Per line: `StockItem` fetched fresh **without a lock**, availability checked, cost snapshotted, a
   `StockRecord` created. `StockRecord::creating` **overwrites `selling_price` with the item's list price**
   and recomputes `total_sales`/`profit` from it — so any discount on the line is lost downstream.
3. `StockRecord::created` decrements `current_quantity` by read-modify-write (`find()` → subtract in PHP →
   `save()`), recalculates the sub-category/category rollups, and posts an `Income` `FinancialRecord` with
   `payment_method='Cash'` hardcoded and `amount = total_sales` at list price — regardless of the real
   payment method, of `amount_paid`, or of whether the sale is on credit.
4. `FinancialRecord::creating` forces `financial_period_id` to the *currently active* period, so a
   backdated sale's income lands in today's period.
5. On failure the controller manually deletes items and header (no outer rollback).
6. `SaleRecord::deleting` restores stock via `StockRecord::deleting` — **but the Income FinancialRecord is
   never reversed.** Same for a bare `DELETE /stock-records/{id}`.

**Restocking:** `StockRecord::created` only touches quantity when `type == 'Sale'` (`:146-158`, comment:
"can be extended later"). `Stock In`, `Expired`, `Damage`, `Lost`, `Internal Use`, `Other` leave
`current_quantity` unchanged. Worse, `creating` checks "enough stock" for **every** type (`:124-127`),
so a `Stock In` larger than what is on hand is rejected. `StockItem::updating` blocks changes to
`current_quantity` unless a runtime PHP flag `$skipQuantityCheck` is set (`:119-124`) and blocks
`original_quantity` entirely. **There is no code path that adds stock to an existing item.** The admin
form's type radio offers `Sale, Damage, Expired, Lost, Internal Use, Other` (no Stock In); the API offers
`Sale, Stock In, Expired, Other` (`StockRecordController.php:38`). Its help box says "Stock will be
automatically reduced", which is false for non-Sale types.

**Numbering:** `RCP-{3 letters of company name}-{Ymd}-{%04d}` / `INV-…` / `PO-{year}-{%04d}` — read the
max, add one, retry with `substr(uniqid(), -4)` on collision. The uniqueness check runs through
`CompanyScope` (per company) but the DB unique index is **global** → cross-tenant collisions for companies
sharing a prefix. Cannot be produced offline.

**Concurrency:** zero `lockForUpdate`, `increment` or `decrement` calls in shop code (they exist only in
PingPin and Billing). Two concurrent checkouts both pass the check and both write absolute values → one
update lost. No idempotency key → a mobile retry after a timeout creates a second sale and deducts twice.

**Rollups:** `StockSubCategory::update_self()` / `StockCategory::update_self()` rescan **all items in the
active period only** on every item/record change (N queries per write; items from previous periods drop
out of totals; "inventory value" = lifetime purchases at `buying_price × original_quantity`, not stock on
hand). `scopeLowStock` and the dashboard hardcode `< 10` / `<= 10`; the per-sub-category `reorder_level`
written by the API is never read.

**Ledger integrity:** `FinancialCategory` scopes query a `type` column and a `status` column that
**don't exist** in the migration (`FinancialCategory.php:73-86`); duplicate-name prevention is `return
false` in `creating` so the API reports "created" but nothing is saved; `UpdateFinancialCategoryAggregates`
guards on a non-existent `update_self` and never runs; `FinancialRecord::deleting` dissociates the category
before deleting so the aggregate job can't run for deletes; "one active period" is PHP-only; closing a
period snapshots nothing.

**Purchasing / forecasting / reorder:** `PurchaseOrder` has no approve/receive workflow and never touches
stock; the admin grid is raw scaffold (editable `company_id` numeric input!). `AutoReorderService` imports
a class that doesn't exist (`PurchaseOrderItem`), creates POs missing NOT NULL columns with a status not in
the enum, reads a non-existent `unit_cost`, and its "trigger" route is shadowed by the resource route
(`app/Admin/routes.php:38-39`). `InventoryForecastService` filters on `created_at` not `date`, matches
`'sale'` only thanks to case-insensitive collation, and has divide-by-zero paths. Nothing is scheduled.
Plan flags `forecasting`/`auto_reorder` exist but are never checked.

**API surface (`routes/api.php:175-200`, `BaseCrudController`):** solid generic layer — tenant-scoped
queries, allow-listed writes, `filter[col][op]=v`, `sort=-a,b`, `?q=`, `/options`, `/search`, pagination
envelope `{code,message,data,meta}`. Gaps: hard deletes everywhere (`$usesSoftDeletes` declared, unused),
no idempotency keys, no `updated_since`/delta, no ETag/version, no bulk ops, no date-range filter on
`sales`, `current_quantity` neither writable nor filterable, `status`/`payment_status` free strings
(`'completed'` from API vs `'Completed'` default), zero-price line impossible (0 → list price), overpayment
silently clamped, PDFs only via web-session routes (`routes/web.php:102-136`). Missing endpoints:
customers, suppliers, POs, receive, returns/refunds/voids, payments-against-sale, restock/adjust/stock-take/
transfer, units, batches/expiry, price lists/tax, discounts, reports, receipt PDF, forecast/reorder,
bulk import/export, sync/delta, worker-permission enforcement, movement history with date filter.

**Legacy web-session helpers still live:** `api/products/quick-add`, `api/sales/quick-record` (ignores
the `price` override), `api/global-search` (`routes/web.php:31-33`).

**Admin panel:** `StockItemController` (667 lines) is polished (search, barcode filter, exporter, SKU
auto/manual, clone) but has no restock action. `SaleRecordController` (659 lines) is the real web POS
(hasMany item picker, pre-validation in `saving`, `processAndCompute` in `saved` — a failure there leaves
the rollback partial). UGX is hardcoded across POS/stock/receipt screens. `HomeController::getFinancialOverview`
and `FinancialReportService::getSummaryStatistics` **double-count sales income** (sum of `amount_paid` +
all Income financial records, which already include the sale postings).

**Tests:** `SaleRecordStockDeductionTest` (5), `CrudAndSalesTest` (4 — the checkout test only asserts
100→97, not totals/profit/ledger), `TenantIsolationTest` (4, only on stock categories), `PoultrySyncTest`
(12). Uncovered: checkout math/ledger, discounts, concurrency, numbering, stock-records API types,
barcode, sale update/delete ledger effects, financial-* isolation, 422-vs-500, PO/forecast/reorder,
quotas, aggregates.

### 1.2 Mobile app shop module

**Stack:** Flutter, `setState` + GetX (navigation only, one controller), `sqflite` (single DB file
`INVETO_TRACK_1`, no migration strategy), `dio`, `flutter_form_builder`, `flutx`, `webview_flutter`,
`flutter_local_notifications`. **Missing packages:** `connectivity_plus`, `workmanager`, `uuid`,
`mobile_scanner`, `printing`/`esc_pos`, `share_plus`, `path_provider`, `flutter_secure_storage`, charts.

**Modules:** `lib/modules/app_module.dart` registry (shop → `MenuRoute`, poultry, budget). No per-tenant
or per-plan enabling, no remembered module.

**Shop screens:** Products list/create/details (photo, SKU auto/manual, no barcode field in UI, no unit,
no reorder level, **no delete**), Categories (name field registered as `'first_name'` — copy-paste bug,
`StockCategoryCreateScreen.dart:59`), Sub-categories, Stock records (= "sales": one item per record, type
radio, quantity, description — **no price, discount, customer, payment method**), Finance transactions,
periods, reports (server PDF viewed via `docs.google.com/gview` — needs internet + public URL), employees.
Shop home shows product count, units, low-stock (`q < 10` hardcoded) — no sales today, revenue, profit,
or sync state.

**Data pattern (13 near-identical model classes):** `get_items()` reads the local table; if empty, awaits
the online fetch, else fires it in the background (UI shows stale data until next open).
`get_online_items()` calls **legacy** `/api/api/<Model>` (`ApiController@my_list`, auth by
`logged_in_user_id` header/param, no bearer token), then `delete_all()` **outside** the insert transaction
(kill the app in between → empty cache), then `REPLACE` inserts. Every column is TEXT; numbers parsed with
`Utils.int_parse` which **truncates decimals**. Search is string-concatenated SQL (`"name LIKE '%$kw%'"`,
apostrophe breaks it). Sorting by quantity is a string compare. Writes call legacy `ApiController@my_update`
via multipart; when offline `Utils.http_post` returns "You're offline — nothing was submitted" and nothing
is saved locally.

**What breaks fully offline:** login/register (server), every shop write, all sale logic (server-side
decrement/profit/period/SKU), receipt numbers (none exist), items/records without an active financial
period (never created by mobile onboarding), image uploads (inline multipart), reports/PDFs, cache refresh
(wipes tables), poultry→finance bridge (legacy API, not idempotent), logout tenant separation, currency/
company changes.

**Auth:** `LoginScreen` → legacy `/api/auth/login` (Dio raw); token saved in plaintext SQLite
(`LoggedInUser.dart:73-119`); session check is `user.id >= 1`; token never validated/refreshed; 401 never
handled; "Forgot password" shows an email address. `RegisterScreen` → legacy `/api/auth/register` with a
free-typed 3-letter currency. Onboarding = 4 static slides (hardcodes "shilling"), seen-flag in
shared_preferences. No offline/guest mode, no PIN.

### 1.3 Existing sync engines (three of them)

| Engine | Where | State | Verdict |
|---|---|---|---|
| **A. Model cache** | 13× `get_online_items` in `lib/model/*Model.dart` | In use by the shop; read-through cache, online-only writes, full re-download, wipe-then-insert | **Retire.** |
| **B. Generic `SyncEngine` + `OfflineStore`** | `lib/services/SyncEngine.dart`, `OfflineStore.dart`, `SyncResources.dart` | Built, **not wired to any screen**; single `sync_records(resource, server_id, data JSON, status)` table; `local:<id>` FK resolution; POST/PUT/DELETE to v1 REST; full pull every time, no delta, no server-delete propagation, no idempotency (lost response → duplicate create); can't carry files | **Retire** (competing design; integer-id + JSON-blob model is the wrong fit for offline POS). |
| **C. Poultry engine** | `lib/poultry/{data,services}/*`, backend `app/Traits/PoultrySyncable.php`, `PoultrySyncController.php` | Live. Per-table sync columns (`uuid` PK, `created_at`/`updated_at` device-ms, `synced_at`, `is_dirty`, `is_deleted`, `version`, `entered_by`), ULID-like ids, `PoultryStore` generic CRUD, transport interface (`NoopTransport` for tests, `HttpSyncTransport` via Sanctum), push oldest-first with 4-try backoff, pull by `cursor_<table>`, conflicts logged to `poultry_sync_conflicts`, `SyncStatusController` + AppBar badge + sync screen. **Balances are derived, never stored** (`domain/farm_stats.dart`) — the right model for stock. | **Generalise** — after fixing the flaws below. |

**Engine C flaws to fix before generalising** (verified in both repos):

1. **Pull cursor = client's device clock** (`client_updated_at > since`, `PoultrySyncable.php:63-67`).
   A device that was offline for days pushes rows stamped in the past; other devices whose cursor already
   passed that time **never pull them**. Clock drift causes the same. Needs a server-assigned monotonic
   `server_seq`.
2. **Page-boundary skips:** strict `>` + `LIMIT 200` + `cursor = max(ts)` drops rows sharing the boundary
   timestamp. Needs a compound cursor `(seq)` or `(ts, id)`.
3. **Whole-row last-writer-wins on client timestamp** — fine for master data, wrong for counters/money.
   `version` is transmitted but never used.
4. **Server writes with raw `DB::table()`** → bypasses Eloquent hooks, `AuditLogger`, and all business
   logic. A pushed *sale* must run stock/ledger logic → needs a service layer, not an upsert.
5. **One row per HTTP request**, no batch, no per-batch transaction → a sale (header + lines + payment +
   movements) cannot be pushed atomically. Client pulls one page per table per sync (no paging loop);
   server caps at 200 → fresh installs need many cycles.
6. **`markSynced` race:** sets `is_dirty=0` by uuid without a version check; an edit made while the push is
   in flight is silently marked clean and never pushed.
7. **Unknown parent → NULL FK + `ok:true`**; client marks child synced; next pull overwrites the local link
   with null.
8. **Four tables have no wire key** (`p_price_history`, `p_audit`, `p_tasks`, `p_task_reports`) → every
   row fails 4× with backoff on every sync (~2.1 s each), the audit backlog grows forever, and the pending
   badge is inflated.
9. No transactions around multi-row local saves; no upload queue for photos (local paths pushed as-is);
   `lastSyncAtMs` in memory only; no connectivity listener / periodic / background sync; conflict log has
   no screen; tombstones never purged; demo seed data is pushed to production; no `company_id` on poultry
   tables and no cleanup on logout.

### 1.4 Onboarding, identity, tenancy, billing

**Three registration flows, three outcomes** (`Api/V1/AuthController.php:30-108` vs `ApiController.php:
371-495` vs `Admin/Controllers/AuthController.php:51-219`): trial 14 days vs 1 year; `subscriptions` row
(trialing) vs none; `company_members` row vs none; phone optional vs required; password confirmed or not;
currency list 21 vs 7 (hardcoded in the view); user status `'Active'` vs `'active'`; role 2 inserted by
hook only vs hook + manual (duplicate rows); temporary `company_id = 1`; web shows raw exception messages.
Branding: "InvetoTrack" / "Inveto admin" (`config/admin.php`), `APP_NAME='Budget Pro.'`, "Budget Dynamics"
(mobile), "Ping Pin" (menu). Ping Pin signup bug: `User::creating` overwrites `username` with `email`, so
phone-only users get `username = NULL` and can't log into the web.

**What signup creates:** user, company (`Company::created` → owner `company_id`, role 2 via `User::updated`
hook, three `FinancialCategory` rows Sales/Purchase/Expense), `company_members` owner row, trial
`Subscription`, calendar-year `FinancialPeriod`, Sanctum token (never expires). **Not created:** stock
categories, sample data, roles other than owner, timezone, tax, receipt settings, logo, welcome email,
verification. `Utils::generate_dummy()` runs on every admin request but every line inside is commented out.

**Tenancy enforcement:** `EnforceSaasIsolation` checks the `web` guard while admin uses the `admin` guard →
inert; its super-admin bypass checks `user_type`, a column that **doesn't exist**. `EnsureCompanyOwnerRole`
re-inserts role 2 on every request. API: `EnsureApiTenant` (403) + `EnsureActiveSubscription` (402, auth/
company/subscription routes exempt) work. **Web admin never checks subscription or `license_expire`** —
locally 24/28 subscriptions are `expired` and all still work. `hasActiveAccess()` grants access when both
subscription and `license_expire` are null.

**Roles & permissions:** five global `admin_roles`, **all with `*` permission**. Billing menu has no
`admin_role_menu` rows → visible to everyone; `/admin/subscriptions` and `/admin/plans` have no role check
and no company filter; global `/admin/auth/users` and `/admin/auth/roles` reachable by URL. Roles 3/4/5
never assigned or checked; `settings_worker_can_*` never read. `EmployeesController`: no password field
(→ `bcrypt('admin')`), no role, email optional (→ `username NULL`), `detail()` shows password hash and
has no company check (IDOR), "credentials via SMS/Email" text with no code behind it. No team API for
budget-pro (only Ping Pin's invite).

**Identity:** no forgot/reset password (table + config exist, nothing uses them; login page has no link),
no email verification (`email_verified_at` cast but column doesn't exist), no OTP/phone login for
budget-pro, no social login, no profile-update endpoint. `PUT v1/auth/password` exists.

**Billing:** Plans seeded (Trial/Starter/Business/Enterprise, USD + UGX), Flutterwave checkout/verify/
webhook are correct and idempotent. Gaps: quotas/features never enforced (`Plan::limit()` has zero
call sites in budget-pro), any member can start checkout, upgrade = stack a full period (no proration/
downgrade/cancel), no dunning/grace/reminders, no invoice PDF, `FLW_REDIRECT_URL=/payment/callback` **route
doesn't exist** (404 after paying in a browser), no tenant-facing billing screen on web, `PUT /company`
currency change silently switches billing region, `DatabaseSeeder` empty (fresh install has no plans/roles).

**Notifications:** `Utils::mail_sender()` → `return;`. No `app/Mail`, no `app/Notifications`, no SMS.
`AutoReorderService` mails a view that doesn't exist. `QUEUE_CONNECTION=sync`. Scheduler runs only
`tracking:backfill-location-names`.

**Localisation:** `app.timezone=UTC` but date helpers hardcode `Africa/Nairobi`; dashboard SQL uses
`CURDATE()` (MySQL server tz); no per-company timezone; no central money formatter (UGX hardcoded in
receipts, categories, budget screens, `?? 'UGX'` fallbacks); currency lists 21/7/DB-default-USD; `locale=en`
only, no `sw`/`lg`; phones free text, no E.164; Flutterwave MoMo only for UGX (KES/TZS billed in USD by
card, no M-Pesa); no in-app MoMo for *sales*.

**Admin first run:** dashboard of `UGX 0` tiles, no checklist/wizard/tips; menu shows Poultry, Ping Pin,
Billing and lowercase technical labels ("stock-categories", "financial-records") to every new shop owner;
duplicate menu entries; `CompanyEditController` currency select has 7 options defaulting to USD (saving it
silently drops e.g. ZAR); `CompanyController` (super-admin) errors on `user_type` and shows every company
as Inactive (`'active'` vs `'Active'`).

### 1.5 Consolidated defect register

Grouped by area; **[S]** security, **[C]** correctness/money, **[R]** robustness, **[U]** UX, **[P]**
performance. File refs are the place to start. "New" = not in `BACKEND_API_MASTER_TASKS.md`.

**Backend shop — correctness**
- [C] Non-Sale stock record types never change quantity; Stock In rejected when > on-hand — `StockRecord.php:124-127,146-158` (new)
- [C] No restock path for an existing item; `current_quantity` guarded by a runtime PHP flag — `StockItem.php:103-124` (new)
- [C] Discount discarded in stock record + ledger — `StockRecord.php:109-117,190`
- [C] Ledger always `Cash`, full total on credit sales; later payments post nothing — `StockRecord.php:170-200` (new)
- [C] Delete sale / stock record doesn't reverse Income — `SaleRecord.php:182-210`, `StockRecord.php:205-230` (new)
- [C] Ledger period forced to active period for backdated sales — `FinancialRecord.php:62-74` (new)
- [C] Oversell / lost update: no locking, absolute-value saves, no outer transaction — `SaleRecord.php:319-503`, `SaleController.php:43-110`
- [C] No idempotency → duplicate sales on retry — `SaleController.php` (new)
- [C] Receipt/invoice/PO numbers: global UNIQUE, per-company generation, prefix collisions, offline-impossible — `SaleRecord.php:217-285`, `PurchaseOrder.php:181-197` (new)
- [C] Zero-price line impossible; overpayment/change clamped — `SaleRecord.php:~380,~450` (new)
- [C] Sales income double-counted in dashboard + report service — `HomeController.php:209-238`, `FinancialReportService.php:218-230`
- [C] `stock_items` bigint quantities/prices vs decimal API validation (`0.01` vs `< 1`) — migrations + `StockRecord.php:105-108`
- [C] `FinancialCategory` scopes on non-existent `type`/`status`; duplicate-name silently not saved; aggregates job never runs — `FinancialCategory.php:40-86`, `UpdateFinancialCategoryAggregates.php:42` (new)
- [C] Inventory value = lifetime purchases; rollups only count active-period items — `StockCategory.php:49-76`, `StockSubCategory.php:60-100`
- [C] `reorder_level` never read; low stock hardcoded `< 10` — `StockItem.php:342`, `DashboardController.php:21`, `HomeController.php`
- [C] `SaleRecordItem` has no `company_id`, no CompanyScope — migration
- [C] Sale `status`/`payment_status`/`payment_method` free strings; `'completed'` vs `'Completed'` — `SaleController.php:84,115-127`
- [C] `payment_status=Paid` via PUT fakes full payment — `SaleRecord.php:132-148`
- [C] `scopeOutOfStock` ungrouped `orWhere` leaks across tenant predicate — `StockSubCategory.php:156-160` (new)
- [C] `AutoReorderService`: non-existent class/column, invalid enum, shadowed trigger route — `AutoReorderService.php:9,120,139,147,220`, `app/Admin/routes.php:38-39`
- [C] `InventoryForecastService` uses `created_at`, collation-dependent type match, divide-by-zero — `:102-119,217,286`
- [C] `PurchaseOrder` fillable misses `created_by_rule_id`, `auto_generated`, `order_date` — `PurchaseOrder.php:69-98` (new)

**Backend shop — robustness/API**
- [R] Shop models throw `\Exception` → 500 — all shop models vs `BaseCrudController.php:149-151` (new)
- [R] Hard deletes everywhere; deleting a category orphans sub-categories/items — `BaseCrudController::destroy`
- [R] Sub-category `measurement_unit` nullable in API but NOT NULL in DB → 500 — `StockSubCategoryController`
- [R] `original_quantity` accepted on update but immutable → 500 — `StockItemController.php:16-41`
- [R] Stock-record update blocked but delete allowed (restores stock) — `StockRecordController.php:47-50`
- [R] Legacy `quick-record` ignores price override — `ApiController.php:555-631`
- [P] `CompanyScope` runs `Schema::getColumnListing()` per query — `CompanyScope.php:46-54`
- [P] Rollup rescans on every write; `$with` + `$appends` N+1 on `StockItem` — `StockItem.php:28,297-307`
- [P] SKU `COUNT(*)+1` collides after deletes; PHP-only uniqueness (race) — `Utils.php:129-138`, `StockItem.php:216-235`

**Sync engine (poultry, to be generalised)** — items 1–9 in §1.3.

**Mobile shop**
- [R] All shop writes online-only; legacy API; no bearer token — `lib/model/Utils.dart:196-296`
- [R] Cache wipe outside transaction — every `get_online_items`
- [R] String-built SQL search; string sort on quantity; `int_parse` truncates — `StockItemsScreen.dart:54-70,151,155`, `Utils.dart:116-131`
- [U] No POS/cart/receipt/customer/barcode/restock/delete/sync indicator
- [U] Category name field registered as `first_name` — `StockCategoryCreateScreen.dart:59`
- [R] Connectivity check = raw socket to port 443 (breaks on local http) — `Utils.dart:163-194`
- [S] Token plaintext in SQLite; logout doesn't clear tenant data; poultry tables have no `company_id` — `LoggedInUser.dart`, `HomeScreen.dart:84-88`
- [R] Loader not in try/finally on create screens; unhandled async sync errors on `HomeScreen.initState`
- [R] Demo seed data pushed to production — `poultry_seeder.dart`
- [R] Photos never reach the server (local paths) — `MortalityEvent.photoPath`

**Onboarding / identity / tenancy / billing**
- [S] All roles `*`; Billing/Users/Roles reachable by any tenant user; Subscriptions unfiltered — `admin_role_permissions`, `SubscriptionController.php` (new)
- [S] `EmployeesController::detail` IDOR + shows password hash — `:136,139`
- [S] Employees created with password `admin`, no role, maybe no username — `EmployeesController.php`, `User.php:64-84`
- [S] Web admin ignores subscription/expiry — no call sites of `hasActiveAccess` under `app/Admin`
- [S] `EnforceSaasIsolation` inert (wrong guard, `user_type` missing) — `EnforceSaasIsolation.php:34-47`
- [S] Any member can start checkout — `BillingController.php:89`
- [C] Three divergent signups; duplicate role rows; `company_id=1` interim — three controllers
- [C] Ping Pin phone-only users get `username NULL` — `User.php:77` vs `PingPin/.../AuthController.php:56`
- [C] `FLW_REDIRECT_URL` → non-existent `/payment/callback` — `.env`, `routes/web.php`
- [C] Upgrade stacks periods; no proration/downgrade/cancel/dunning/grace — `Company::activateSubscription`
- [C] Quotas/feature flags unenforced — zero `Plan::limit()` call sites
- [R] All email disabled; queue sync; nothing scheduled — `Utils.php:404-406`, `Kernel.php`
- [U] No wizard/checklist/empty states; technical menu labels; Poultry/Ping Pin/Billing shown to shop owners
- [U] Currency: 21 vs 7 vs USD default; UGX hardcoded in ~10 places; tz `Africa/Nairobi` hardcoded vs `UTC` config
- [U] Mobile: no forgot password, no OTP, no PIN, 4 generic slides, free-typed currency

---

## 2. Vision & design principles

1. **Offline is the default, not a fallback.** Every shop action a cashier can take must complete locally
   in < 200 ms with no network, and the UI must never say "you're offline, nothing was saved".
2. **Events are the truth; balances are derived.** Stock on hand = Σ movements. Cash in drawer = Σ
   payments − Σ payouts. Never store a counter that two devices can both overwrite. (The poultry module
   already does this for eggs/feed — `domain/farm_stats.dart` — copy it.)
3. **Every write is idempotent by client UUID.** Retries, duplicate pushes and app kills can never create
   a second sale.
4. **The server is authoritative for *derived* facts, the device for *observed* facts.** A device records
   "I sold 3 sodas at 1,500 each, paid 4,500 cash" — that is never rewritten. The server decides the
   resulting on-hand quantity, ledger postings, and final receipt number.
5. **Conflicts are policy, not exceptions.** Each entity type has a written rule (Appendix E). Users see
   a conflict screen only for the handful of cases that genuinely need a human.
6. **One engine, one wire format, one local schema pattern** for shop, budget and poultry. Retire engines
   A and B.
7. **Money is `DECIMAL(20,2)` end to end; quantities are `DECIMAL(15,3)`.** Currency is explicit on every
   money-bearing row.
8. **Tenant boundary everywhere:** `company_id` on every tenant table (server and device), unique
   `(company_id, uuid)`, cleared on logout, enforced by policy classes not by hope.
9. **East-African fit is a feature:** MoMo (MTN/Airtel/M-Pesa), WhatsApp as the receipt/report channel,
   USSD-grade phones, expensive data (delta sync, small payloads, image compression), UGX/KES/TZS/RWF
   first, Swahili/Luganda ready.
10. **Ten minutes to first sale.** Onboarding is measured, not assumed.
11. **"Done" means a user can see it in the app** (lesson recorded in `clock/DECISIONS.md:49`). Every
    backlog item below has an acceptance test phrased from the user's side.
12. **Don't break the shipped app.** The legacy `ApiController`/`MobileApiController` routes stay until
    the new app is rolled out; new behaviour is additive and versioned.

---

## 3. Part A — Shop management: the target design

### A1. Domain model (target)

New and changed entities. Full DDL in Appendix B. Every tenant table gets the **sync columns** from
Appendix B.0 (`uuid`, `company_id`, `server_seq`, `client_created_at`, `client_updated_at`, `version`,
`is_deleted`, `created_by_uuid`, `device_id`).

| Entity | Purpose | Key fields | Notes |
|---|---|---|---|
| **Product** (rename of `StockItem`, keep table) | Sellable thing | name, sku (unique per company), barcodes[] (own table `product_barcodes`, unique per company), unit_id, category, sub-category, `cost_price`, `selling_price`, `min_stock` (per product), `track_stock` bool, `allow_negative_stock` bool, `tax_rate_id`, `is_active`, `image_uuid`, variants (optional `parent_product_uuid` + attributes JSON) | `current_quantity` becomes a **materialised cache** recomputed from movements (server-side, atomic), never client-written. `original_quantity` becomes the first `opening` movement. |
| **Unit** | UoM per company | name, abbreviation, `base_unit_id`, factor (e.g. carton = 24 × bottle) | Enables selling by piece and by pack from one stock. |
| **StockMovement** (replaces `StockRecord` semantics) | Append-only ledger | product_uuid, `type` enum: `opening, sale, sale_return, purchase_receipt, purchase_return, adjustment_in, adjustment_out, damage, expired, lost, internal_use, transfer_in, transfer_out, stock_take`, `quantity` (signed), `unit_cost`, `reference_type/uuid` (sale, GRN, adjustment doc…), reason, `batch_uuid`, `location_uuid`, `occurred_at`, `financial_period_id` (derived from `occurred_at`, not "active now") | Quantity on hand = Σ signed quantity. Immutable; corrections are new movements. |
| **Sale** (`sale_records`) | POS transaction | + `subtotal`, `discount_amount`, `discount_reason`, `tax_amount`, `total`, `amount_paid`, `change_given`, `balance`, `currency`, `customer_uuid`, `shift_uuid`, `device_id`, `provisional_number`, `receipt_number` (server-final), `status` enum `draft, completed, voided, refunded, partially_refunded`, `payment_status` enum `unpaid, partial, paid`, `voided_reason`, `voided_by` | Lines: `sale_items` (+ `company_id`, `discount_amount`, `tax_amount`, `unit_uuid`, `returned_quantity`, `cost_snapshot`). |
| **Payment** | Money received against a sale (or a customer account) | sale_uuid (nullable), customer_uuid, `method` enum `cash, mobile_money, card, bank, credit, cheque, other`, `provider` (MTN/Airtel/M-Pesa), `reference` (MoMo txn id), amount, currency, `received_at`, `received_by_uuid`, `shift_uuid` | Split payments = several rows. Credit sales = zero payments at checkout; later payments post to ledger *when received*. |
| **Customer** | Buyer with credit book | name, phone (E.164), email, address, `credit_limit`, `balance` (derived), tags, notes, `is_active` | Statement = sales − payments. Enables "debt book" — one of the most-used features of Ugandan shops. |
| **Supplier** | Vendor | name, phone, email, address, `payment_terms_days`, `balance` (derived), notes | |
| **PurchaseOrder** + **PurchaseOrderItem** | Ordering | supplier_uuid, status enum `draft, sent, partially_received, received, cancelled`, expected_at, lines (product, qty, unit_cost) | Replaces the JSON `items` column. |
| **GoodsReceipt (GRN)** + lines | Receiving into stock | po_uuid (nullable — direct purchase without PO), supplier_uuid, invoice_ref, lines (product, qty, unit_cost, batch/expiry) | Creates `purchase_receipt` movements + Expense/Purchase ledger posting (with `unpaid`/`paid` state and supplier payments). |
| **StockAdjustment** + lines | Stock take / corrections | reason, lines (product, counted_qty, system_qty, delta) | Creates `stock_take`/`adjustment_*` movements. |
| **StockTransfer** | Between locations | from/to location, lines | Phase 4+ (multi-branch). |
| **Location** | Branch/warehouse | name, address, is_default | Phase 4+. Single default location created at signup. |
| **Shift / CashUp** | Till session | opened_by, opened_at, opening_float, closed_at, expected_cash (derived), counted_cash, variance, notes | Daily reconciliation; ties payments to a cashier. |
| **Return / Refund** | Reversal | sale_uuid, lines (sale_item_uuid, qty, restock bool, reason), refund payment rows (negative) | Creates `sale_return` movements and reverses ledger proportionally. |
| **TaxRate** | e.g. VAT 18% | name, rate, inclusive bool | Optional per company; default none. |
| **PriceList / PriceRule** (later) | Wholesale/retail tiers, customer-group prices, quantity breaks | | Phase 4+. |
| **Batch** (optional) | Lot + expiry | product_uuid, batch_no, expiry_date, qty | Pharmacies/agro-vet shops; FEFO on sale. Phase 4+. |
| **DeviceRegistration** | Sync identity | device_id (uuid), company_id, user_id, name, platform, last_seen, `number_prefix`, `last_sync_seq` | Backs offline numbering & per-device audit. |
| **Ledger link** | `financial_records.source_type/source_uuid` | | Every auto-posted ledger row points at its source (sale, payment, GRN, refund, adjustment). Reversal = insert a contra row referencing the same source. |

### A2. Fix the core math (Phase 0 — before anything is built on it)

Each item has an acceptance test.

1. **Movements move stock.** Every `StockMovement.type` applies its signed quantity; `Stock In`/
   `purchase_receipt`/`adjustment_in`/`sale_return` increase; the rest decrease. Remove the "enough stock"
   check for inbound types. *Test:* `POST /stock-movements {type: purchase_receipt, qty: 50}` on an item
   with 3 → 53.
2. **Atomic, locked decrement.** `SaleService::checkout()` wraps header + lines + movements + payments +
   ledger in **one** `DB::transaction`, locks each product row `lockForUpdate()` in a deterministic order
   (by product id, to avoid deadlocks), re-checks availability inside the lock, and applies
   `UPDATE products SET current_quantity = current_quantity - ? WHERE id = ? AND (allow_negative_stock OR
   current_quantity >= ?)` (affected rows = 0 → insufficient stock). *Test:* 20 parallel checkouts of 1
   unit against stock 10 → exactly 10 succeed, quantity ends at 0 (use `php artisan test --parallel` or a
   dedicated concurrency test with `pcntl_fork`).
3. **Idempotency.** `Idempotency-Key` header (or body `client_uuid`) on every write; `(company_id,
   client_uuid)` unique on sales/payments/movements/GRNs; a replay returns the original response with
   `meta.replayed=true`. *Test:* same checkout twice → one sale, one deduction, identical response.
4. **Discounts & real prices flow to the ledger.** Movement `unit_price` = the line's actual price;
   `total_sales`/`profit` from actual; ledger Income = actual total. Header discount allocated to lines
   pro-rata. *Test:* item list 2,000 sold at 1,500 → ledger 1,500, profit 1,500 − cost.
5. **Ledger reflects money, not sales.** Income is posted per **Payment**, not per sale: cash sale →
   Income immediately; credit sale → an `Accounts Receivable` entry (or no income) until a payment arrives.
   `payment_method` on the ledger row = the payment's method. *Test:* credit sale → income 0, receivable
   total; later payment 4,500 MoMo → income 4,500, method mobile_money.
6. **Reversals are contra rows.** Void/refund/delete → contra movements + contra ledger rows referencing
   the same `source_uuid`; nothing is hard-deleted. *Test:* void a paid sale → stock restored, income
   reversed, sale status `voided`, both ledger rows present.
7. **Period from `occurred_at`.** `financial_period_id` derived from the business date; posting into a
   closed period returns 422 `period_closed` with the option to post to the open period (explicit flag).
8. **Numbering** per Appendix D — per-company sequence tables with `SELECT … FOR UPDATE`, unique index
   `(company_id, receipt_number)`, and device-prefixed provisional numbers for offline sales.
9. **`BusinessRuleException` everywhere** in shop models/services (insufficient stock, closed period,
   immutable field, duplicate SKU, inactive product) → 422 with a machine-readable `errors.code`
   (`insufficient_stock`, `period_closed`, …) so the mobile app can branch. *Test:* each case returns 422
   with the code.
10. **Money/quantity types unified:** `DECIMAL(20,2)` money, `DECIMAL(15,3)` quantities, `currency`
    column on sales/payments/movements/ledger. Migration with backfill; remove `decimal:2` casts over
    bigint.
11. **Rollups become cheap and correct:** category/sub-category totals computed by SQL `SUM` over
    movements/products on demand (or cached with explicit invalidation), not rescanned in write hooks;
    "inventory value" = Σ `current_quantity × cost_price`; low stock = `current_quantity <= min_stock`
    (per product, falling back to sub-category `reorder_level`, then a company default).
12. **Dashboard/report double count fixed:** income = ledger income only (payments-based), never `+ Σ
    amount_paid`.
13. **Fix `FinancialCategory`** (add `type` enum income/expense + `status`, unique `(company_id, name)`,
    duplicate → 422, aggregates via SQL), `FinancialPeriod` one-active-per-company DB constraint (partial
    unique index or trigger) + close action.
14. **Delete semantics:** soft deletes on products/categories/customers/suppliers; deleting a category
    with children → 422 `has_children`; products with movements can only be deactivated.

### A3. POS & sales workflow (mobile first, web parity)

**Cart screen** (the most-used screen in the app — design for one thumb, sunlight, and speed):
- Search (name/SKU/barcode, FTS over local DB), **barcode scan** (`mobile_scanner`), favourites grid,
  category chips, recent items.
- Line: qty stepper, per-line price override (permission-gated), per-line discount, unit selector (piece
  vs pack), stock badge (green/amber/red), "not enough stock" warning with *allow negative* if the company
  permits.
- Header: customer picker (or "walk-in"), header discount (amount or %), tax, notes.
- **Payment sheet:** Cash (with **change calculator**), Mobile Money (provider + reference; optional
  "request payment" via MoMo API later), Card, Bank, **Credit** (requires a customer; shows credit limit and
  current debt), **Split** (any combination), "Pay later".
- Completion: receipt preview → **Share to WhatsApp** (text + optional PDF image), print to Bluetooth
  thermal printer, SMS, "New sale". Receipt shows the **provisional number** offline and the final
  number once synced (see Appendix D).
- Hold/park a cart; resume; multiple open carts.
- Void (same day, permission) and **Return/Refund** flow from sale history with per-line quantities and
  restock toggle.
- Sale history: today/week/month, filter by cashier/customer/method/status, totals; tap → receipt.

**Shift / cash-up:** open shift with float → sales accumulate → close with counted cash → variance report;
owner gets the summary on WhatsApp. Optional but strongly recommended for shops with employees.

**Web admin POS:** rewrite `SaleRecordController` on top of `SaleService` (same code path as the API and
the sync push), fix the `saved`-hook partial-rollback risk, add barcode-input support (USB scanners type
into a focused field), keyboard-first flow, receipt print CSS, and currency from company settings.

**Endpoints (all under `/api/v1`):** `POST sales/checkout` (idempotent, full payload incl. payments),
`POST sales/{uuid}/payments`, `POST sales/{uuid}/void`, `POST sales/{uuid}/returns`,
`GET sales?filter[sale_date][gte]=…&filter[cashier]=…`, `GET sales/{uuid}/receipt.pdf`, `GET
sales/{uuid}/receipt.txt` (WhatsApp format), `POST sales/{uuid}/share` (server-side WhatsApp send, later),
`GET shifts`, `POST shifts/open|close`.

### A4. Inventory workflows

- **Stock In (restock/GRN):** from a supplier with cost, qty, batch/expiry; with or without a PO; creates
  movements + purchase ledger + supplier payable. Quick "Add stock" button on the product screen for
  micro-shops that don't do POs.
- **Adjustments:** reason-coded (damage, expired, lost, internal use, correction, gift), signed qty,
  optional photo, permission-gated; approval workflow optional (owner approves worker adjustments).
- **Stock take:** create a count session (all products or a category), enter counted quantities (scan-
  assisted), see variance, post → `stock_take` movements set on-hand to counted. Works offline;
  concurrent counts on two devices merge per product with the latest count winning *with a variance
  note* (Appendix E).
- **Transfers** between locations (Phase 4+).
- **Per-product `min_stock`** + low-stock list + WhatsApp/SMS alert digest.
- **Expiry** (Phase 4+): FEFO suggestion at sale, "expiring in 30 days" report.
- **Movement history** per product with running balance, filterable by date/type/user/device.
- **Bulk import** (CSV/Excel template; from the phone via share sheet) and export.
- **Product images:** compressed on device (≤ 200 KB), uploaded through the **upload queue** (Appendix C),
  referenced by `image_uuid`.

### A5. Purchasing & suppliers

Supplier CRUD, supplier statement (GRNs − payments), PO lifecycle (`draft → sent → partially_received →
received`), "send PO to supplier on WhatsApp" (text), receive against PO with partial receipts and cost
variances, supplier payments (Expense ledger), purchase returns. Reorder suggestions list (products under
`min_stock` with suggested qty = max(min_stock × 2 − on_hand, avg daily sales × lead_time)) — a *simple,
explainable* replacement for the broken EOQ engine (see A7).

### A6. Reports & analytics

Server-side `GET /reports/{name}?from&to&group_by` returning JSON (+ `?format=pdf|xlsx`), all computed
from movements/payments/ledger with the double-count fixed:

sales summary (by day/cashier/method/customer/category/product), profit & margin (cost snapshot at sale),
stock on hand & valuation (at cost), low stock, dead stock (no sales in N days), fast movers, expiry,
customer statements & aging (0–30/31–60/60+), supplier statements, cash-up variances, VAT summary,
purchase summary, movement audit. Mobile renders the same from the **local** DB when offline (today/this
week), and from the server when online (any range). Dashboard: today's sales, cash vs MoMo vs credit,
profit, low-stock count, debts due, sync state.

### A7. Forecasting & auto-reorder: fix or remove

Recommendation: **remove the current `AutoReorderService`/EOQ code and the `InventoryForecast` tables from
the UI in Phase 0** (they cannot execute and mislead users), keep the tables, and reintroduce in Phase 4 as
the *reorder suggestions* of A5 plus a nightly job computing per-product 7/30/90-day velocity into a small
`product_stats` table. Gate behind the plan flags and actually enforce them. Anything "AI" (Part E) builds on
`product_stats`, not on the EOQ code.

### A8. API completeness checklist (target `/api/v1`)

`products` (+ `by-barcode`, `bulk`, `import`, `images`), `units`, `product-barcodes`, `categories`,
`sub-categories`, `customers` (+ `statement`, `payments`), `suppliers` (+ `statement`, `payments`),
`stock-movements` (read + inbound/adjust writes), `stock-adjustments`, `stock-takes`, `goods-receipts`,
`purchase-orders` (+ `send`, `receive`, `cancel`), `sales` (+ `checkout`, `payments`, `void`, `returns`,
`receipt.pdf|txt`), `payments`, `shifts`, `returns`, `tax-rates`, `locations`, `reports/*`, `settings`
(receipt header/footer, numbering, tax, negative stock, rounding), `devices` (register, list, revoke),
**`sync/push`, `sync/pull`, `sync/bootstrap`** (Appendix A), `team` (invite/list/role/deactivate),
`auth/*` (+ `forgot`, `reset`, `otp/request`, `otp/verify`, `verify-email`, `profile`),
`billing/*` (+ `portal`, `cancel`, `change-plan` with proration preview), `notifications/preferences`.

Cross-cutting: `Idempotency-Key`, `If-Match`/`version` on updates (409 on stale), `updated_since` on
lists, soft deletes with `include_deleted`, consistent enums, OpenAPI 3.1 spec generated from FormRequests
and published at `/api/docs`, Postman collection in repo.

### A9. Admin panel polish (web)

Rename menu labels (Products, Categories, Stock, Sales/POS, Customers, Suppliers, Purchases, Reports,
Settings, Team, Billing); hide Poultry/Ping Pin/Billing-admin from shop tenants via `admin_role_menu`;
rebuild PurchaseOrder/GRN screens (not scaffold); product page with movement history, "add stock" and
"adjust" actions; customer page with statement; company settings page with currency (all 21), timezone,
fiscal year, tax, receipt template, numbering prefix, negative-stock policy, low-stock default; one
`money()` Blade helper using the company currency everywhere; fix `saved`-hook POS rollback; convert
`Yes/No` varchar flags to booleans in the UI; empty states with CTAs on every grid.

### A10. Quotas & feature gating

`EnforcePlanLimit` middleware/service: `max_products`, `max_users`, `max_sales_per_month`,
`max_locations`, `storage_mb`; features `forecasting`, `auto_reorder`, `multi_location`, `whatsapp_receipts`,
`api_access`. Enforced at write time (422 `plan_limit_reached` with upgrade CTA) and surfaced in `auth/me`.
Offline: the device caches the entitlement snapshot; limits are enforced at *sync* time with a grace
(never block a cashier from finishing a sale offline — flag it instead).

### A11. Tests (shop)

Unit: money/quantity math, discount allocation, numbering, conflict rules. Feature: every A2 acceptance
test, every endpoint's tenant isolation, 422 codes, idempotent replay, void/refund ledger symmetry,
period-close behaviour, quota enforcement. Concurrency: parallel checkout, parallel numbering, parallel
stock-take posts. Property-based: random sequences of movements never make Σ movements ≠ cached quantity.
Golden files for receipt text/PDF. Seeder `ShopDemoSeeder` used by tests and by demo mode.

---

## 4. Part B — Offline-first architecture

### B1. Goals & non-goals

**Goals:** (1) 100% of cashier/stock-keeper actions work offline; (2) two or more devices per shop; (3)
no data loss on app kill, reinstall (after a sync), or clock skew; (4) sync uses little data (delta only,
compressed, no images unless on Wi-Fi/opt-in); (5) owner can see near-real-time numbers on the web when
devices are online; (6) the device can be offline for **weeks** and still reconcile.

**Non-goals (v1):** real-time collaboration between devices while offline (no P2P/Bluetooth sync in v1 —
listed in Part E), offline registration of a brand-new company (first signup needs a network; everything
after that doesn't), offline payment *collection* through MoMo APIs (recording a MoMo reference offline is
fine).

### B2. One engine: generalise the poultry engine, retire A and B

Extract from `lib/poultry/` into `lib/sync/` (package-like, no poultry names):

- `SyncDb` — `ensureTable(name, columns)` with the standard sync columns, schema version + migrations
  (`onUpgrade`), `company_id` on every table, indexes on `(company_id, is_dirty)`, `(company_id, uuid)`,
  `(company_id, server_seq)`.
- `SyncStore<T>` — generic CRUD with **transactions** (`saveAll([...])` in one `db.transaction`),
  optimistic `version` bump, `markSynced(uuid, expectedVersion)` (fixes the in-flight-edit race: only clears
  `is_dirty` if `version` unchanged).
- `SyncRegistry` — table list with parents-first order, wire keys, `pullOnly`, `eventOnly` (append-only
  tables), `hasFiles`.
- `Outbox` — ordered queue of **operations** (not rows): `{op_uuid, kind: upsert|delete|event|file,
  table, uuid, payload, batch_uuid, created_at, attempts, last_error}`. A sale checkout enqueues one
  **batch** containing header + lines + movements + payments, pushed atomically.
- `SyncTransport` — `pushBatch(ops[]) → results[]`, `pull(table, sinceSeq, limit)`, `bootstrap()`,
  `uploadFile()`. HTTP implementation via `ApiService` (Sanctum). `NoopTransport` for tests.
- `SyncService` — orchestrates: push outbox (batches, parents-first, exponential backoff with jitter,
  circuit-breaker per table so one broken row can't stall everything), then pull each table with a
  **paging loop** until `has_more=false`, then apply, then upload files. Persists `last_sync_at`,
  per-table `cursor_seq`, and stats to `sync_meta`.
- `SyncTriggers` — `connectivity_plus` listener (sync on regain), debounce-after-local-write (5 s),
  periodic (15 min foreground), `workmanager` background (Android; iOS BGAppRefresh best-effort),
  app-resume, manual.
- `SyncStatus` — GetX controller (reuse `SyncStatusController`), AppBar badge, sync screen with
  per-table pending counts, last error, and the **conflict inbox**.
- `TenantGuard` — every query filtered by the logged-in `company_id`; logout wipes all tenant rows (or
  keeps them encrypted per tenant behind the PIN — Appendix C option).

Poultry, budget and shop all register into the same engine. The old `OfflineStore`/`SyncEngine`/
`SyncResources` and the 13 model caches are deleted.

### B3. Sync data model

Server (every tenant table):

```
uuid CHAR(26/36)  -- client-generated ULID; UNIQUE (company_id, uuid)
company_id BIGINT
server_seq BIGINT UNSIGNED  -- global monotonically increasing, assigned on every server write
                            -- (trigger or app-level sequence); INDEX (company_id, server_seq)
client_created_at BIGINT ms, client_updated_at BIGINT ms  -- device clocks, informational + LWW tiebreak
version INT UNSIGNED        -- bumped on every write; used for If-Match on direct API updates
is_deleted BOOL             -- tombstone (soft delete everywhere)
created_by_uuid CHAR(36), device_id CHAR(36)
```

`server_seq` comes from one counter (`sync_sequence` table with `SELECT … FOR UPDATE` + increment inside
the write transaction, or MySQL `AUTO_INCREMENT` on a `sync_log` table whose id is copied onto the row).
**The pull cursor is `server_seq`, never a timestamp** — this closes flaws 1 and 2 of §1.3.

Device (mirror + local-only columns): `synced_at`, `is_dirty`, `server_seq`, `sync_error`.

Reference/shared tables (units defaults, tax presets, product templates) are `pullOnly`.

### B4. Event-sourced stock

`stock_movements` is **append-only** (`eventOnly`). Devices create movements locally (a sale creates
`sale` movements; a restock creates `purchase_receipt` movements; a stock take creates `stock_take`
movements). Local on-hand = Σ local movements (cached in `products.local_quantity`, recomputed
incrementally). On push, the server **applies** each movement through `StockService::apply()` (locked,
atomic `UPDATE … current_quantity = current_quantity ± ?`), inserting it if absent by uuid (idempotent) and
**never updating an existing movement**. Corrections are new movements.

The server then returns the authoritative `current_quantity` for touched products in the push response;
the device overwrites its cache. If applying a sale movement would make quantity negative and the company
disallows negative stock, the server still records the sale (a real sale happened — money changed hands)
but flags it `stock_exception=true` and emits a **conflict-inbox item** ("Sold 3 of X but only 1 was in
stock — adjust stock or mark as counted"). This is the only sane policy for offline POS: **never reject a
completed sale** (Appendix E).

### B5. Offline numbering — see Appendix D

Short version: every device gets a `number_prefix` (e.g. `D7`) at registration; offline receipts are
`RCP-D7-000123` (per-device sequence stored locally, monotonic, never reused); on sync the server assigns
the company-wide final number `RCP-2026-000981` and both are printed on the receipt ("Ref D7-000123").
Customers/legal reporting use the final number; the provisional one guarantees uniqueness offline and
lets the cashier reference a sale before sync. Per-company unique index on the final number; unique
`(company_id, device_id, provisional_number)`.

### B6. Sync protocol v2 — see Appendix A

Push: `POST /sync/push` with an array of **batches**; each batch is applied in one DB transaction through
the domain services (never raw upserts); per-batch result `applied | replayed | rejected{code} |
conflict{server_row}`; response includes derived values (final numbers, current quantities, ledger ids).
Pull: `GET /sync/pull?table=&since_seq=&limit=` returns rows ordered by `server_seq` with `next_seq` and
`has_more`. Bootstrap: `POST /sync/bootstrap` returns a compressed snapshot of all tables for first
install/re-install (paged), so a new phone is ready in one call chain.

### B7. Conflict policy — see Appendix E

Per entity class: **master data** (products, customers, suppliers, categories, settings) → field-level
LWW using `client_updated_at` with server tiebreak, both versions kept in `sync_conflicts` for 30 days,
user-visible only when the *same field* changed on both sides; **events** (sales, payments, movements,
GRNs, returns) → insert-if-absent, never conflict; **derived** (quantities, balances, numbers) → server
wins, always; **documents with state machines** (PO, stock take, shift) → state transitions validated
server-side, invalid transition → conflict inbox with both states; **deletes** → tombstone wins over
edit only if the edit's `client_updated_at` < delete time, else the edit resurrects the row (and the
inbox shows it).

### B8. Client architecture details

- **Local DB:** one sqflite file per install, versioned migrations, WAL mode, FTS5 virtual table for
  product search, all writes through `SyncStore` transactions. Money as INTEGER minor units (cents) in
  SQLite to avoid float drift; quantities as REAL with 3-dp rounding helper. (Schema in Appendix C.)
- **Outbox first:** every user action = local write + outbox op in the **same transaction**. UI reads
  local only. Sync runs in an isolate/`compute` to keep the till responsive.
- **Files:** `file_queue(uuid, path, purpose, status)`; images compressed; uploaded on Wi-Fi or opt-in
  cellular; server returns `file_uuid`/URL; rows reference `image_uuid`, never local paths.
- **Auth offline:** Sanctum token in `flutter_secure_storage`; a **PIN** (per user, hashed with a device
  salt) unlocks the app offline; token refresh/validation when online; 401 → re-login without wiping
  pending outbox (outbox is bound to `company_id`+`user_uuid`, re-login of the same user resumes it).
- **Multi-user on one device:** cashier switch by PIN (shift-aware); each row carries `created_by_uuid`.
- **Tenant separation:** all tables keyed by `company_id`; logout = wipe tenant data *after* confirming the
  outbox is empty (or warn: "3 unsynced sales — sync first or discard"). Optional "keep data locked behind
  PIN" for the same user to re-login offline later.
- **Clock sanity:** record server time offset on every sync; warn if device clock is off by > 10 min;
  timestamps stored as UTC ms; business dates in company timezone.
- **Resilience:** every outbox op has `attempts`/`last_error`; after N permanent failures it goes to the
  conflict inbox rather than blocking the queue; batches are pushed in dependency order; push is resumable
  (batch-level acks); pull is resumable (cursor saved per page).
- **Data budget:** gzip request/response, delta pulls only, `fields=` minimal projections for lists, no
  images in pull (fetched lazily via CDN URL with local cache), sync size shown in the sync screen.

### B9. Offline UX

Global connectivity chip (Online / Offline / Syncing 12 / 3 need attention); pending badge on the sale
list; receipt shows "Ref D7-000123 — final number after sync"; "Last synced 2h ago" persisted; sync screen
with per-table counts, errors, retry, "Sync now on cellular" toggle; **conflict inbox** with plain-language
cards and one-tap resolutions ("Keep mine / Keep server / Adjust stock"); low-storage warning; first-run
bootstrap progress; graceful degraded mode when the entitlement cache says the plan expired ("You can keep
selling; sync will resume after renewal" for a grace window, then read-only after the grace ends — see
C8).

### B10. Migration path from today's app

1. Backend: add sync columns + `server_seq` + services (Phase 1) without touching legacy routes.
2. Backfill `uuid` for every existing shop row; `server_seq` for existing rows = a one-time ascending fill.
3. New app version: on first launch after upgrade, keep the legacy cache tables, run `bootstrap`, build the
   new local schema, and drop the old tables. Legacy `logged_in_user` token migrates to secure storage.
4. Feature-flag the new shop screens per tenant (`features.shop_v2`) for a staged rollout; the legacy
   `ApiController`/`MobileApiController` routes stay until 95% of devices are on the new version, then are
   removed with a forced-upgrade screen.

### B11. Testing the sync engine (must-have before rollout)

Backend `SyncTest`: idempotent replay, batch atomicity (one bad op rolls back the batch), `server_seq`
cursor never skips (insert 1,000 rows with identical client timestamps, pull with limit 200 → all
delivered), cross-tenant isolation, tombstone propagation, unknown parent → rejected op (not null FK),
LWW with skewed clocks, negative-stock policy, numbering assignment, entitlement grace. Client
(`sqflite_common_ffi`): outbox ordering, kill-mid-batch (simulate by throwing between ops), markSynced
race, pull paging, conflict application, logout wipe, two-device simulation (two DB files + one fake
server) with oversell reconciliation. A **soak test** script: two emulators selling the same 5 products
for an hour with network toggled randomly; assert Σ movements == server quantities and no duplicate
sales.

### B12. Edge-case catalogue (each becomes a test or a documented policy)

Sale created offline for a product deleted online · price changed online after offline sale (sale keeps
its price) · product created on two devices with the same barcode (merge suggestion in inbox) · customer
created twice offline (dedupe by phone in inbox) · period closed while device offline · plan expired while
offline · device clock years off · same cashier on two devices · reinstall with unsynced data (warn on
logout/uninstall; server-side "last seen" alerts owner) · storage full · very large catalogues (10k
products: FTS, paging, bootstrap in pages) · currency changed by owner while cashier offline (sales keep
their currency) · refund of a sale that isn't synced yet (allowed locally; pushed in order) · shift
closed on one device, sales still arriving from another (shift totals recomputed server-side) · GRN
against a PO cancelled online (inbox) · duplicate receipt number from a restored backup (device id
rotates on restore).

---

## 5. Part C — Onboarding, identity & team

### C1. One registration flow

`RegistrationService::register(dto)` used by API v1, web, legacy (adapter), and Ping Pin. Creates exactly
the same tenant every time: user (`'Active'`), company (currency, timezone, country, business_type,
fiscal-year start), owner membership + role (real per-company role, see C5), trial subscription (14 days
— *or* a configurable "free forever tier" if the business decides on freemium — Appendix H), default
location, default financial period aligned to the fiscal year, default units (piece, kg, litre, pack…),
default tax rates for the country (UG VAT 18% inactive by default), default categories for the chosen
business type, default settings (receipt header = company name/phone, numbering prefix, negative stock
off), device registration (mobile), welcome message. One transaction; one test that asserts all of it.
Validation shared via `RegisterRequest` for all three entry points (phone optional but normalised to
E.164 when present; password 8+; currency from the single 21-code list; terms accepted).

### C2. Guided setup wizard (mobile and web) — spec in Appendix F

Steps (each skippable, progress saved, resumable, works offline after step 1):
1. **Account** — phone (OTP) or email; name; password/PIN.
2. **Business** — name, type (retail shop, wholesale, pharmacy, agro-vet, hardware, restaurant/bar,
   salon, boutique, electronics, other), country → currency + timezone + tax presets, logo (optional).
3. **Your products** — pick a **template pack** for the business type (e.g. "Retail shop — 40 common
   items"), *or* import CSV/Excel, *or* scan barcodes to add from a public barcode DB, *or* add manually,
   *or* skip. Opening stock can be entered inline.
4. **Money** — how you sell: cash / MoMo (which providers) / credit customers; opening float; receipt
   preferences (WhatsApp, print, both).
5. **Team** — invite a cashier/stock-keeper by phone (WhatsApp/SMS link) with a role; can skip.
6. **First sale** — a guided sale on the real till (or on demo data) ending with a shared receipt.
   Success screen: "You're set. Everything works offline."

Web mirrors it as a `/setup` route shown until the checklist is 60% complete; afterwards a dismissible
dashboard "Getting started" card with the remaining items.

### C3. Demo mode & templates

"Try with demo shop" on the login screen creates a **local-only, never-synced** tenant (`company_id = 0`,
`is_demo = 1`, engine refuses to push) with realistic Ugandan retail data; a banner offers "Start my real
shop" which wipes demo data. Fix the poultry seeder that pushes demo rows to production. Template packs
are versioned server data (`product_templates` by business type + country), pulled as reference data and
editable by the platform admin.

### C4. First-run experience & empty states

Every list has an empty state with one CTA and a one-line "why". Dashboard shows a "Getting started"
checklist (add products, make a sale, invite staff, set up MoMo, enable WhatsApp receipts) with progress.
Contextual tips (max 3, dismissible, never modal). In-app help links to `docs/quickstart.md` content.
Menus renamed to plain language; modules the tenant hasn't enabled are hidden (module enablement per
tenant, chosen in the wizard, changeable in settings).

### C5. Team, roles & permissions (per company, enforced)

Replace global `admin_roles` usage for tenants with **per-company roles**: `owner`, `manager`, `cashier`,
`stock_keeper`, `accountant`, `viewer`; a permission matrix (sell, discount, void, refund, restock,
adjust, stock take, view cost prices, view profit, view reports, manage products, manage team, manage
settings, billing) stored per role with company overrides; enforced by Laravel policies on every API
endpoint *and* admin controller, plus `admin_role_menu` rows so the web menu matches. Owner-only:
billing, team, settings. The `settings_worker_can_*` flags become defaults for the `cashier` role.
Invite flow: owner enters phone/email + role → invite record with token → WhatsApp/SMS/email link →
invitee sets password/PIN → membership activated; re-send, revoke, deactivate member, transfer ownership.
Remove `bcrypt('admin')` fallback entirely; require an explicit password or invite. Team API + web screen +
mobile screen. Activity log per member (who sold/voided/adjusted what).

### C6. Authentication

Phone-first: OTP via SMS (Africa's Talking or Twilio; WhatsApp OTP as a cheaper second channel), email
optional; email verification when email is used; forgot/reset for both channels; PIN for offline unlock and
cashier switching; device list with revoke; token expiry + refresh (Sanctum expiration set, refresh on
login); rate limits already present; login by phone or email everywhere (fix the `username` overwrite
bug); profile update endpoint; optional Google sign-in later.

### C7. Notifications

Enable mail (a transactional provider — SES/Postmark/Brevo — under the product's own domain, not the
third-party domain in `.env`), queue = `database` with a supervised worker, scheduler wired: welcome
(WhatsApp/SMS/email), OTPs, invites, password reset, trial ending (3 days, 1 day), payment received,
subscription expiring/expired/renewed, **daily sales summary to the owner on WhatsApp** (opt-in, 8 pm
local), low-stock digest, unsynced-device alert ("Phone 'Counter 2' hasn't synced for 3 days"),
cash-up variance. In-app notification centre + per-user preferences. WhatsApp via Meta Cloud API
templates (receipts, summaries, reminders) — the single most valued channel for the target market.

### C8. Billing UX & lifecycle

Tenant billing page (web + mobile): plan, usage vs limits, invoices (PDF), upgrade/downgrade with
proration preview, cancel at period end, payment methods; `/payment/callback` route implemented
(handles success/failure/cancel, verifies server-side, deep-links back to the app); MoMo for KES/TZS via
Flutterwave M-Pesa / Airtel Money options; **lifecycle jobs**: nightly expire trialing/active past
`ends_at` → `past_due` → grace (7 days, read-only sales still allowed offline) → `expired`; dunning
messages; reactivation restores immediately. Web admin enforces access like the API (same
`hasActiveAccess`, with the grace policy). Owner-only checkout. Currency change requires confirmation and
doesn't silently switch billing region mid-cycle.

### C9. Security fixes (Phase 0)

Scope `admin_role_permissions` (`*` only for platform admins); add `admin_role_menu` rows; company filter +
role check on Subscriptions/Plans/Users/Roles admin screens; policies on every admin `detail/edit/destroy`
(kill IDOR); `EmployeesController` rewrite (no hash display, password/invite required, role required, company
check); fix `EnforceSaasIsolation` (admin guard, remove `user_type`); rotate the leaked `.env` secrets if
any were committed; `flutter_secure_storage` for tokens; wipe tenant data on logout; `company_id` on poultry
tables; secure random for UUIDs; CSRF/rate limits on web signup; audit log user FK fixed.

### C10. Localisation & regional fit

Company `timezone`, `country`, `locale`, `currency`, `number_format`; one `Money` value object + Blade/
Dart formatters (no more hardcoded UGX); dates via company timezone (replace `Africa/Nairobi` hardcode;
dashboard SQL converts with `CONVERT_TZ`); `sw` and `lg` translations for the mobile app strings
(externalise strings first); phone numbers E.164 with country default; MoMo providers per country; receipt
templates per country (VAT/TIN fields); public holidays/weekend config for reports.

### C11. Mobile onboarding screens

Replace the 4 slides with: value proposition (3 screens max, real screenshots, "works offline"), phone
entry → OTP, then the wizard (C2). Login: phone/email + password, OTP alternative, forgot password, "Try
demo shop", remembered account with PIN unlock. Post-login home: module cards only for enabled modules,
"Getting started" progress, sync chip. Permissions (camera for barcode, notifications) requested in
context, not up front. Accessibility: large tap targets, high contrast, works on 5" 720p phones, Android 8+.

### C12. Web admin onboarding

Unified `/register` using the same service and the same wizard (Blade + Alpine or Livewire), `/setup`
route, dashboard checklist, plain-language menu, hidden unused modules, company settings page, team page
with invites, billing page, help centre link, and the branding decided once (Appendix H).

---

## 6. Part D — Cross-cutting quality, security, operations

- **Schema integrity:** foreign keys everywhere (with `ON DELETE RESTRICT` for money-bearing links),
  unique constraints per company (sku, barcode, customer phone, supplier name, category name, receipt
  number), NOT NULL where the domain requires, booleans not `Yes/No` strings, enums for statuses. Repair
  `migrate:fresh` (the `return;`-stubbed budget migrations + a consolidated baseline migration guarded
  by `hasTable`) so CI and new environments work from zero.
- **Money & precision:** `DECIMAL(20,2)` money, `DECIMAL(15,3)` quantities, minor units on the device,
  rounding rules per currency (UGX has no cents — round to 1; configurable "round to nearest 50/100").
- **Errors:** `BusinessRuleException(code, message, meta)` across all modules; API error codes documented;
  mobile maps codes to friendly messages.
- **Performance:** cache `CompanyScope` column lookups; remove hook-time rescans; indexes on `(company_id,
  sale_date)`, `(company_id, server_seq)`, `(company_id, product_id, occurred_at)`; `product_stats`
  nightly; report queries reviewed with `EXPLAIN`; paginated `/options`.
- **Observability:** Sentry (backend + Flutter), structured logs with `company_id`/`device_id`/`request_id`,
  sync metrics (batches applied, conflicts, lag per device), Horizon or supervisor for queues, uptime
  checks, slow-query log.
- **Backups & data ownership:** nightly DB backups with restore drills; per-tenant export (all data as
  CSV/JSON zip) and delete-my-data; device-side encrypted backup of unsynced outbox to the user's Google
  Drive (optional).
- **CI/CD:** GitHub Actions running PHPUnit (with a MySQL service), Pint, PHPStan level 5+, Flutter
  analyze + tests, build APK/AAB; staging environment; deploy script with backup → migrate → cache clear →
  smoke test (formalising the manual protocol used in this project); feature flags per tenant.
- **Documentation:** OpenAPI + Postman, `docs/` user guides updated, ADRs in `DECISIONS.md` (this repo
  doesn't have one yet — create it, Appendix G seeds it), runbooks for sync incidents.
- **Legacy retirement plan:** `ApiController`/`MobileApiController`/`Utils::get_user` removed after the
  forced-upgrade window (Part B10 step 4), with server-side telemetry showing legacy call volume → 0.

---

## 7. Part E — Differentiating ideas (the creative backlog)

Ordered roughly by value ÷ effort for the East-African small-shop market. Each is a candidate for Phase 5;
several are cheap once Parts A–C exist.

**Selling & customers**
1. **WhatsApp-native receipts & statements** — one tap shares a formatted receipt (already prototyped in
   `reports/thanks.blade.php`); customers reply "paid" with a MoMo screenshot; owner marks paid. Later:
   Cloud API templates for automatic sending.
2. **Debt book ("Ebbanja / Deni")** — customer credit ledger with reminders on WhatsApp/SMS, due dates,
   partial payments, aging; the #1 pain in informal retail.
3. **MoMo request-to-pay** — cashier taps "Request 4,500 from 0772…", customer approves on their phone,
   sale auto-marks paid (MTN MoMo API / Airtel / Flutterwave collections).
4. **Bluetooth thermal receipt printing** (ESC/POS) with QR code linking to the online receipt.
5. **Offline barcode catalogue** — a bundled/downloadable dataset of common FMCG barcodes (Uganda/Kenya)
   so scanning a new soda auto-fills name/size; community-contributed corrections.
6. **Pack/piece selling from one stock** (units with factors) and **bundle products** (kits).
7. **Quick-sell tiles** for the 20 fastest movers with pictures; big-button "kiosk mode".
8. **Customer display / receipt QR** — customer scans to get a digital receipt and a loyalty stamp.
9. **Loyalty & promotions** — points, "buy 5 get 1", happy-hour prices, expiring-stock discounts.
10. **Voice/quick entry** — "3 sodas 2 bread" parsed into a cart (on-device, Luganda/Swahili/English).

**Stock & purchasing**
11. **Reorder suggestions that explain themselves** ("You sell ~6/day; 4 left; restock 30 by Friday").
12. **Supplier ordering on WhatsApp** — PO rendered as a message; supplier confirms; GRN pre-filled.
13. **Expiry & FEFO** for pharmacies/agro-vets; "expiring soon" push; automatic markdown suggestion.
14. **Dead-stock detector** and **shrinkage report** (stock-take variance trends by product/cashier).
15. **Photo stock-take** — snap the shelf, count assisted by on-device ML for packaged goods (later).
16. **Multi-branch** with transfers, per-branch dashboards, consolidated owner view.

**Money & insight**
17. **Daily "how did my shop do" WhatsApp summary** with 3 insights (best seller, margin drop, debt due).
18. **Profit coach** — margin per product, price-change simulator, "your top 10 make 80% of profit".
19. **Cash drawer reconciliation** with variance alerts; **owner remote view** of open shifts.
20. **Bookkeeping export** for accountants (URA-friendly VAT report, Excel, QuickBooks CSV).
21. **Micro-loan readiness** — opt-in verified sales history export lenders accept (partnerships).

**Platform & offline**
22. **Peer-to-peer sync over local Wi-Fi/Bluetooth** for shops with several tills and no internet for days.
23. **SMS-based sync fallback** — compressed daily totals over SMS when data is unavailable (exploratory).
24. **Progressive Web App** for the web POS with offline cache (service worker + IndexedDB via the same
    protocol), for laptops in the shop.
25. **Data-saver mode** and "sync only on Wi-Fi" defaults; sync cost shown in MB.
26. **Family/partner accounts** — a spouse's phone as a read-only viewer; "shop health score" widget.
27. **Marketplace/catalogue publishing** — one-tap public price list page + WhatsApp order intake.
28. **Templates marketplace** — business-type packs contributed by users (curated).
29. **Assistant** — natural-language questions over the local DB ("how much did I sell yesterday?") on
    device, with server-side model when online.
30. **Hardware kit** — cheap Android POS + printer + scanner bundle pre-provisioned with the app (channel
    idea, not code).

---

## 8. Part F — Phased roadmap & checkbox backlog

Estimates assume one full-time backend dev + one Flutter dev, and are deliberately conservative.
Each phase ends with a demo from the user's side and a green CI run.

### Phase 0 — Stop the bleeding (2 weeks) · **must ship first**

Backend
- [x] P0-1 Scope admin permissions: `*` only for platform admins; `admin_role_menu` rows; role+company checks on Subscriptions/Plans/Users/Roles screens (§1.4, C9)
- [x] P0-2 Policies on every admin `detail/edit/destroy` (kill IDOR); `EmployeesController` rewrite (password/invite required, role required, no hash display, company check)
- [x] P0-3 Fix `EnforceSaasIsolation` (admin guard; drop `user_type`); web admin enforces `hasActiveAccess` with grace
- [x] P0-4 Movements move stock for every type; remove "enough stock" check on inbound; add `POST /stock-movements` inbound + adjust (A2.1)
- [x] P0-5 Locked, atomic, single-transaction checkout via `SaleService`; deterministic lock order (A2.2)
- [x] P0-6 Idempotency-Key on checkout/payments/movements (A2.3)
- [x] P0-7 Discounts and real prices into movements + ledger; ledger per payment with real method; contra rows on void/delete (A2.4–6)
- [x] P0-8 Numbering: per-company sequences, `(company_id, number)` unique, fix cross-tenant collisions (Appendix D server part)
- [x] P0-9 `BusinessRuleException` + error codes across shop models → 422 (A2.9)
- [x] P0-10 Fix `FinancialCategory` (type/status columns, unique, 422), one-active-period constraint, period from `occurred_at`
- [x] P0-11 Remove dashboard/report income double count
- [x] P0-12 Hide/disable AutoReorder + Forecast UI and shadowed route until Phase 4 (A7)
- [x] P0-13 Unify signup into `RegistrationService` (web/API/legacy/Ping Pin) + fix `username` overwrite; one `RegisterRequest` (C1)
- [x] P0-14 Implement `/payment/callback`; owner-only checkout
- [x] P0-15 Tests for every P0 item (A11); CI pipeline running them

Mobile
- [x] P0-16 Tokens → `flutter_secure_storage`; logout wipes tenant data; `company_id` on poultry tables
- [x] P0-17 Fix cache wipe-outside-transaction, SQL-injection-prone search, string quantity sort, `int_parse` truncation, `first_name` field bug, loader try/finally, unhandled sync errors
- [x] P0-18 Poultry engine quick fixes: wire keys for the 4 unmapped tables (or exclude them), `markSynced` version check, demo seed never pushes, persist `lastSyncAt`

### Phase 1 — Offline foundation (4 weeks)

Backend
- [x] P1-1 Sync columns + `server_seq` + backfill on all shop/budget tables (Appendix B.0)
- [x] P1-2 `DeviceRegistration` + `POST /devices/register` (prefix assignment)
- [x] P1-3 `POST /sync/push` (batches, transactions, domain services, idempotent), `GET /sync/pull` (seq cursor, paging), `POST /sync/bootstrap` (Appendix A)
- [x] P1-4 `StockService::apply()` event-sourced movements with negative-stock policy + conflict items (B4)
- [x] P1-5 `sync_conflicts` table + `GET /sync/conflicts` + resolve endpoints
- [x] P1-6 Entitlement snapshot in `auth/me`; grace policy at sync time (A10/C8)
- [x] P1-7 `SyncTest` suite (B11) incl. seq-cursor and skew tests; poultry migrated onto v2 (keep v1 endpoints one release)

Mobile
- [x] P1-8 `lib/sync/` engine extracted & generalised (B2): `SyncDb` migrations, `SyncStore` transactions, `Outbox` batches, transport v2, paging pull, triggers (`connectivity_plus`, `workmanager`), status controller, conflict inbox screen, `TenantGuard`
- [x] P1-9 Local shop schema (Appendix C) + bootstrap on upgrade (B10) + FTS product search
- [x] P1-10 File upload queue with compression (B8)
- [x] P1-11 PIN unlock + offline auth + 401 handling (B8/C6)
- [x] P1-12 Retire engines A and B; shop screens read local only
- [x] P1-13 Engine tests incl. two-device simulation and kill-mid-batch (B11)

### Phase 2 — Real POS & inventory, offline (4 weeks)

- [ ] P2-1 Product model upgrades: units, barcodes table, `min_stock`, `track_stock`, `allow_negative_stock`, soft delete, images via queue
- [ ] P2-2 Cart, barcode scan (`mobile_scanner`), line/header discounts (permission-gated), unit selector, held carts
- [ ] P2-3 Payments: cash+change, MoMo reference, card, bank, credit (customer required), split; `payments` table & endpoints
- [ ] P2-4 Customers + debt book + statements; suppliers (basic)
- [ ] P2-5 Receipts: provisional/final numbers (Appendix D device part), WhatsApp share (text + image), Bluetooth ESC/POS print, PDF via API
- [ ] P2-6 Void & return/refund flows with ledger symmetry
- [ ] P2-7 Stock In (quick + GRN-lite), adjustments with reasons/photos, stock take sessions, movement history per product
- [ ] P2-8 Shifts/cash-up (open/close/variance)
- [ ] P2-9 Sales history, today's dashboard from local DB, low-stock list, sync chip everywhere (B9)
- [ ] P2-10 Web admin POS rewritten on `SaleService`; currency helper; renamed menus; product/customer pages with actions (A9)
- [ ] P2-11 Golden-file tests for receipts; feature tests for every new endpoint; soak test (B11)

### Phase 3 — Onboarding, team, notifications, billing (3 weeks)

- [ ] P3-1 Phone-first auth: OTP (SMS + WhatsApp), forgot/reset, email verification, profile, device list, token expiry (C6)
- [ ] P3-2 Setup wizard (Appendix F) mobile + web; template packs + CSV import; opening stock
- [ ] P3-3 Demo mode (never syncs) and "start my real shop" (C3)
- [ ] P3-4 Per-company roles + permission matrix + policies + `admin_role_menu`; invites via WhatsApp/SMS/email; team screens (C5)
- [ ] P3-5 Notifications: mail provider, `database` queue + worker, scheduler, welcome/OTP/invite/reset/trial/dunning/daily summary/low stock/unsynced device; preferences (C7)
- [ ] P3-6 Billing page (web+mobile), proration, cancel, invoices PDF, lifecycle jobs (trialing→past_due→grace→expired), M-Pesa/Airtel for KES/TZS (C8)
- [ ] P3-7 Quota & feature enforcement with upgrade CTAs (A10)
- [ ] P3-8 Empty states, getting-started checklist, contextual tips, plain-language menus, module enablement (C4)
- [ ] P3-9 Localisation: timezone/locale/currency per company, `Money` formatter everywhere, `sw`/`lg` strings, E.164 phones (C10)
- [ ] P3-10 Branding decision applied everywhere (Appendix H) + Play Store listing refresh

### Phase 4 — Purchasing, reports, analytics, hardening (4 weeks)

- [ ] P4-1 Suppliers full, PO lifecycle, GRN with partials/variances, supplier payments & statements, purchase returns, "send PO on WhatsApp" (A5)
- [ ] P4-2 Reports API + PDF/XLSX + mobile local reports; VAT summary; aging (A6)
- [ ] P4-3 `product_stats` nightly job; reorder suggestions; re-introduce forecasting behind plan flag or delete the EOQ code for good (A7)
- [ ] P4-4 Locations & transfers; batches/expiry/FEFO (optional per business type)
- [ ] P4-5 Schema integrity pass: FKs, per-company uniques, booleans, enums, `migrate:fresh` repair, `DatabaseSeeder` with plans/roles/units/templates (Part D)
- [ ] P4-6 Observability (Sentry, sync metrics, Horizon), backups + restore drill, tenant export/delete
- [ ] P4-7 OpenAPI + Postman + docs refresh; ADR log
- [ ] P4-8 Legacy retirement: telemetry, forced-upgrade screen, remove `ApiController`/`MobileApiController`/`Utils::get_user` (B10 step 4)

### Phase 5 — Differentiators (continuous)

Pick from Part E by measured demand; suggested first three: WhatsApp receipts via Cloud API (E1), debt book
reminders (E2), MoMo request-to-pay (E3).

### Definition of done (applies to every item)

Code + migration + tests + API doc + admin/mobile UI (where applicable) + feature flag (if risky) + a
one-line entry in `CHANGELOG.md` + **a human can do it in the app**.

---

## Appendix A — Sync protocol v2 specification

All under `/api/v1/sync`, `auth:sanctum` + `api.tenant` (+ subscription with grace policy). JSON, gzip.
`X-Device-Id` header required (registered device). Times are UTC epoch ms. Money in minor units? **No** —
money is decimal strings (`"4500.00"`) on the wire to match the DB; the device converts to minor units
locally.

### A.1 `POST /devices/register`
```json
{ "device_id": "01J9…", "name": "Counter 1", "platform": "android", "app_version": "2.0.0" }
→ { "device_id": "...", "number_prefix": "D7", "server_time": 1758700000000, "entitlements": {...} }
```
Re-registering the same `device_id` returns the same prefix. Owner can rename/revoke devices.

### A.2 `POST /sync/push`
Request:
```json
{
  "device_id": "01J9…",
  "device_time": 1758700000000,
  "batches": [
    {
      "batch_uuid": "01J9A…",            // idempotency key for the whole batch
      "kind": "sale",                    // sale | payment | movement | master | document | delete | generic
      "ops": [
        { "op_uuid": "…", "table": "sales", "uuid": "01J9B…", "action": "insert",
          "client_updated_at": 1758699000000, "version": 1,
          "data": { "provisional_number": "RCP-D7-000123", "customer_uuid": null,
                    "subtotal": "4500.00", "discount_amount": "0.00", "total": "4500.00",
                    "amount_paid": "4500.00", "currency": "UGX", "occurred_at": 1758698990000,
                    "shift_uuid": "…", "items": [ { "uuid": "…", "product_uuid": "…", "quantity": "3.000",
                    "unit_price": "1500.00", "discount_amount": "0.00", "unit_uuid": "…" } ] } },
        { "op_uuid": "…", "table": "payments", "uuid": "…", "action": "insert",
          "data": { "sale_uuid": "01J9B…", "method": "cash", "amount": "4500.00", "currency": "UGX",
                    "received_at": 1758698995000 } },
        { "op_uuid": "…", "table": "stock_movements", "uuid": "…", "action": "insert",
          "data": { "product_uuid": "…", "type": "sale", "quantity": "-3.000", "reference_type": "sale",
                    "reference_uuid": "01J9B…", "occurred_at": 1758698990000 } }
      ]
    },
    { "batch_uuid": "…", "kind": "master",
      "ops": [ { "op_uuid": "…", "table": "products", "uuid": "…", "action": "update",
                 "client_updated_at": 1758690000000, "version": 4,
                 "data": { "selling_price": "1600.00" }, "changed_fields": ["selling_price"] } ] }
  ]
}
```
Rules:
- Each batch is applied in **one DB transaction** through domain services (`SaleService::applySynced`,
  `StockService::apply`, `PaymentService::apply`, `MasterDataService::upsert`…). Any op failure rolls
  back the batch; the response says which op and why. Batches are independent.
- `batch_uuid` recorded in `sync_batches(company_id, batch_uuid UNIQUE, result JSON)`; a replay returns
  the stored result with `replayed: true` without re-applying.
- Event tables (`sales`, `sale_items`, `payments`, `stock_movements`, `returns`, `goods_receipt_items`…)
  are **insert-if-absent by `(company_id, uuid)`**; an existing uuid → `replayed`. Updates to events are
  rejected (`code: immutable_event`); state changes (void, refund) are their own ops.
- Master tables: field-level merge — `changed_fields` applied if `client_updated_at >= server.client_updated_at`
  for that field (server keeps per-field timestamps in `sync_field_versions` or, simpler, whole-row LWW +
  conflict record when the server row changed after the client's base `version`). If `version` in the op
  < server `version` and any overlapping field changed → `conflict` with `server_data`.
- Deletes: `action: delete` → tombstone (`is_deleted=1`); resurrect rule per Appendix E.
- Unknown parent uuid → op **rejected** (`code: missing_parent`, `parent: "customer_uuid"`), never a null
  FK; the client re-queues after the parent syncs (parents-first ordering makes this rare).
- Numbering: server assigns `receipt_number` etc. and returns them.
- Stock policy: applied per Appendix E; response includes `stock_exceptions[]`.

Response:
```json
{
  "server_time": 1758700001000,
  "results": [
    { "batch_uuid": "01J9A…", "status": "applied", "server_seq_max": 88213,
      "assigned": { "sales": { "01J9B…": { "receipt_number": "RCP-2026-000981", "id": 5123, "server_seq": 88210 } } },
      "derived": { "products": { "…": { "current_quantity": "12.000", "server_seq": 88211 } },
                   "customers": { "…": { "balance": "0.00" } } },
      "stock_exceptions": [] },
    { "batch_uuid": "…", "status": "conflict",
      "ops": [ { "op_uuid": "…", "status": "conflict", "code": "stale_version",
                 "server_data": { "...": "full server row" }, "conflict_id": 771 } ] }
  ]
}
```
Statuses: `applied | replayed | rejected | conflict`. Rejection codes: `missing_parent`, `immutable_event`,
`period_closed`, `plan_limit_reached`, `validation` (with `errors`), `unknown_table`, `device_revoked`.

### A.3 `GET /sync/pull?table=products&since_seq=88100&limit=500`
```json
{ "table": "products", "rows": [ { "uuid": "…", "server_seq": 88101, "is_deleted": 0, "version": 5, "...": "..." } ],
  "next_seq": 88213, "has_more": true, "server_time": 1758700002000 }
```
Ordered by `server_seq` ascending; `next_seq` = last row's seq; client loops while `has_more`. Rows
include all fields incl. server-derived ones (`current_quantity`, `receipt_number`, `balance`). Reference
tables use the same endpoint with `company_id` null. `?tables=a,b,c` variant returns several tables in one
call to cut round trips.

### A.4 `POST /sync/bootstrap`
Returns a paged snapshot `{ tables: { products: { rows, next_page } … }, seq: 88213 }` for first install;
subsequent pulls start from `seq`. Optional `since_seq` to do an incremental bootstrap.

### A.5 `GET /sync/conflicts`, `POST /sync/conflicts/{id}/resolve { choice: mine|server|merged, data }`

### A.6 `POST /files` (multipart, `purpose`, `uuid`) → `{ file_uuid, url, thumb_url }`; rows reference
`file_uuid`.

### A.7 Server internals
`sync_sequence` (single row, `FOR UPDATE`), trait `Syncable` (assigns `uuid` if missing, bumps
`server_seq`/`version` on every save incl. admin-panel edits so web changes flow to devices), `SyncBatchLog`,
`SyncConflict`, per-table wire key registry, `SyncApplier` dispatching to domain services, tests in
`tests/Feature/Api/SyncTest.php`.

---

## Appendix B — Target database schema changes

### B.0 Standard sync columns (every tenant table)
`uuid CHAR(36) NOT NULL`, `company_id BIGINT NOT NULL`, `server_seq BIGINT UNSIGNED NOT NULL`,
`client_created_at BIGINT`, `client_updated_at BIGINT`, `version INT UNSIGNED DEFAULT 1`,
`is_deleted TINYINT(1) DEFAULT 0`, `created_by_uuid CHAR(36)`, `device_id CHAR(36)`;
`UNIQUE (company_id, uuid)`, `INDEX (company_id, server_seq)`, `INDEX (company_id, is_deleted)`.

### B.1 New tables
`units`, `product_barcodes`, `customers`, `customer_payments` (or `payments` with nullable `sale_uuid`),
`suppliers`, `supplier_payments`, `payments`, `stock_movements` (replaces the semantics of `stock_records`;
keep `stock_records` as a view/alias for one release), `stock_adjustments`(+`_items`), `stock_takes`
(+`_items`), `stock_transfers`(+`_items`), `locations`, `goods_receipts`(+`_items`),
`purchase_order_items`, `returns`(+`_items`), `shifts`, `tax_rates`, `price_lists`(+`_items`),
`batches`, `devices`, `number_sequences (company_id, kind, year, last_value, UNIQUE(company_id,kind,year))`,
`sync_sequence`, `sync_batches`, `sync_conflicts`, `files`, `product_stats`, `company_roles`,
`company_role_permissions`, `company_members` (exists — add `role_uuid`, `pin_hash`, `status`),
`invites`, `notification_preferences`, `notifications`, `activity_log` (or fix `audit_logs` FK),
`product_templates`, `settings` (key/value per company with typed defaults).

### B.2 Altered tables
- `stock_items` → add `unit_id`, `min_stock DECIMAL(15,3)`, `track_stock`, `allow_negative_stock`,
  `tax_rate_id`, `is_active`, `image_uuid`, `parent_product_uuid`, `attributes JSON`, `deleted_at`;
  change prices to `DECIMAL(20,2)`, quantities to `DECIMAL(15,3)`; unique `(company_id, sku)`.
- `sale_records` → add `subtotal`, `discount_amount`, `discount_reason`, `tax_amount`, `change_given`,
  `currency`, `customer_uuid`, `shift_uuid`, `location_uuid`, `provisional_number`, `voided_at/by/reason`,
  `stock_exception`; enums for `status`/`payment_status`; unique `(company_id, receipt_number)`,
  `(company_id, device_id, provisional_number)`; drop global unique.
- `sale_record_items` → add `company_id`, `uuid`, `discount_amount`, `tax_amount`, `unit_uuid`,
  `returned_quantity`; FK to `sale_records` `ON DELETE RESTRICT`.
- `financial_records` → `amount DECIMAL(20,2) NOT NULL`, `currency`, `source_type`, `source_uuid`,
  `is_reversal`, `reverses_uuid`; index `(company_id, source_type, source_uuid)`.
- `financial_categories` → `type ENUM(income,expense)`, `status`, unique `(company_id, name)`.
- `financial_periods` → unique active per company (generated column `active_flag = IF(status='Active',1,NULL)`
  + unique `(company_id, active_flag)`), `closed_at`, `closed_by`.
- `purchase_orders` → `supplier_uuid`, `location_uuid`, status enum extended, drop `items` JSON after
  migration, unique `(company_id, po_number)`.
- `companies` → `timezone`, `country`, `locale`, `business_type`, `fiscal_year_start_month`,
  `rounding_rule`, `negative_stock_policy`, `receipt_header/footer`, `number_prefix`, `is_demo`,
  `onboarding_state JSON`; drop `settings_worker_can_*` after role migration.
- `admin_users` → `phone_e164`, `email_verified_at`, `phone_verified_at`, `locale`, `last_login_at`,
  `pin_hash` (per membership instead), unique `email` (nullable-unique), unique `phone_e164`.
- Poultry tables → `company_id`, `server_seq` (migrate cursor from ms to seq).

### B.3 Migration safety
All migrations additive first (new columns nullable + backfill jobs), then constraints in a later migration
once backfill verified; every migration guarded with `hasTable/hasColumn`; a consolidated baseline
migration for fresh installs; backup before each production migrate (existing protocol).

---

## Appendix C — Mobile local schema & outbox

SQLite (WAL), versioned via `sync_meta.schema_version` + ordered migration list.

Core tables mirror the server (same names, same columns as the wire format) plus local columns
`synced_at, is_dirty, sync_error, local_quantity` (products). Money stored as INTEGER minor units,
converted at the boundary; quantities REAL(3dp).

```
outbox(op_uuid TEXT PK, batch_uuid TEXT, seq INTEGER, table_name TEXT, row_uuid TEXT,
       action TEXT, payload TEXT, kind TEXT, created_at INTEGER, attempts INTEGER DEFAULT 0,
       last_error TEXT, state TEXT CHECK(state IN ('pending','inflight','failed','dead')))
file_queue(file_uuid TEXT PK, path TEXT, purpose TEXT, row_table TEXT, row_uuid TEXT, state TEXT, attempts INTEGER)
sync_meta(key TEXT PK, value TEXT)          -- cursor_<table>, last_sync_at, device_id, number_prefix,
                                             -- server_time_offset_ms, schema_version, company_id, entitlements
sync_conflicts(id INTEGER PK, table_name, row_uuid, local_json, server_json, code, state, created_at)
number_sequences(kind TEXT PK, last_value INTEGER)  -- provisional numbers, never decremented
sessions(user_uuid TEXT PK, pin_hash TEXT, role TEXT, last_login_at INTEGER)
products_fts (FTS5: name, sku, barcodes, category)   -- kept in sync by triggers
```

Invariants: a user action = one transaction writing rows + outbox ops; the outbox is drained in `seq`
order grouped by `batch_uuid`; `inflight` ops revert to `pending` on app start (crash recovery); `dead`
ops surface in the conflict inbox; pulling never overwrites a row that is `is_dirty=1` (it's queued as a
conflict instead); `local_quantity` = Σ local movements, reconciled to `current_quantity` from pull/push
responses; all tables filtered by `company_id` from `sync_meta`.

---

## Appendix D — Offline numbering scheme

**Problem:** receipt/invoice/PO numbers must be unique, sequential-looking, printable offline, and
cross-device safe. Today they are server-generated, globally unique, and prefix-collide across tenants.

**Scheme:**
- Server assigns each registered device a short **prefix** (`D1`…`D9`, `DA`…, per company; stored in
  `devices.number_prefix`). Web admin sessions use prefix `W`.
- Device keeps `number_sequences(kind, last_value)`; provisional number `RCP-D7-000123` is generated
  **inside the sale's local transaction** and never reused (restore/reinstall rotates the device id →
  new prefix, so an old backup can't collide).
- On push the server assigns the **final** company-wide number from `number_sequences(company_id, kind,
  year)` under `FOR UPDATE`: `RCP-2026-000981` (format configurable per company: prefix, year, padding).
  Both numbers are stored; the receipt footer prints "Ref D7-000123" so a customer's paper receipt (printed
  offline) can still be found after sync. Re-prints after sync show the final number.
- Uniques: `(company_id, receipt_number)`, `(company_id, device_id, provisional_number)`. No global
  unique.
- Legal/tax exports use the final number; gaps are acceptable and explained (voids keep their number with
  status `voided`).
- Same for invoices, POs, GRNs, adjustments, stock takes, shifts (`SHF-D7-000004`).

---

## Appendix E — Conflict resolution matrix

| Entity class | Examples | Rule | User sees |
|---|---|---|---|
| **Event (append-only)** | sale, sale item, payment, movement, return, GRN line | Insert-if-absent by uuid; never updated; replay = ok | Nothing |
| **Derived** | `current_quantity`, customer/supplier balance, shift totals, final numbers, ledger rows | Server always wins; devices overwrite cache from responses/pulls | Nothing (numbers update quietly) |
| **Master data** | product, customer, supplier, category, unit, tax, settings | Field-level LWW on `client_updated_at` with server tiebreak; if both sides changed the *same field* since the client's base version → conflict card, default = server, one tap to keep mine | Card only for same-field edits |
| **Document with state** | PO, stock take, shift, adjustment | Server validates the transition; invalid → conflict card showing both states | Card |
| **Delete vs edit** | product deleted online, edited offline | Tombstone wins if delete time > edit time; else edit resurrects and card shown to the deleter | Card |
| **Stock policy** | offline sale exceeds server stock | Sale is **always accepted** (money changed hands); movement applied (quantity may go negative even if policy forbids); `stock_exception=1`; card: "Adjust stock / Mark counted / Ignore" | Card to owner/stock-keeper |
| **Duplicate master** | same barcode/phone created on two devices | Both kept; merge suggestion card ("These look like the same product — merge?") merges movements to one uuid | Card |
| **Price changed after offline sale** | — | Sale keeps its own prices; no conflict | Nothing |
| **Period closed** | sale dated in a closed period | Movement/ledger posted to the next open period with `period_override=1`; card for the accountant | Card (accountant role) |
| **Plan expired** | device offline past grace | Ops accepted into a quarantine (`sync_batches.status=held`), applied on renewal; device shows read-only banner after grace | Banner |
| **Revoked device** | — | Push rejected `device_revoked`; data stays local; owner can re-authorise | Full-screen notice |

Conflict records expire after 30 days if unresolved (server default applied). All resolutions are
themselves synced events (`conflict_resolutions`) so every device converges.

---

## Appendix F — Onboarding wizard spec

**Entry:** after OTP/email verification (mobile) or after web signup. **State:** `companies.onboarding_state`
`{ step, completed_steps[], skipped_steps[], template_pack, started_at, completed_at }` synced to the
device so it resumes anywhere.

| Step | Fields | Defaults & smarts | Offline? |
|---|---|---|---|
| 1 Account | phone (E.164 with country picker, default from SIM), name, password, PIN (4–6 digits) | OTP via SMS; WhatsApp OTP fallback; password strength meter | No (needs OTP) |
| 2 Business | name, type, country, currency, timezone, logo, address | type → template pack, tax presets, units; country → currency/tz; logo optional | Yes (queued) |
| 3 Products | template pack (preview + tick/untick), CSV/Excel import, barcode scan-to-add, manual add, opening stock & cost | packs versioned server data (pulled at step 2); import validates & previews; opening stock creates `opening` movements | Yes |
| 4 Money | payment methods (cash/MoMo providers/card/bank/credit), opening float, receipt prefs (WhatsApp/print/both), tax on/off, negative stock policy, rounding | sensible defaults per country | Yes |
| 5 Team | invite (phone/email + role), can skip | invites queued and sent on sync | Yes |
| 6 First sale | guided cart with coach marks → payment → share receipt | on real data or demo | Yes |
| Done | checklist summary, "works offline" reassurance, links: add more products, set up WhatsApp receipts, see reports | | |

Metrics to log: time to complete each step, drop-off step, time to first *real* sale, template pack
chosen, % using import, % inviting staff. Target: median < 10 min to first sale.

---

## Appendix G — Decision log (seed for `DECISIONS.md` in this repo)

- **G1. One sync engine, generalised from poultry; engines A/B retired.** Because two competing designs
  guarantee drift; the uuid/event model fits POS; the poultry engine is proven in production.
- **G2. Server-assigned `server_seq` is the pull cursor; client timestamps are only for LWW.** Because
  device clocks are unreliable and caused the row-loss flaw.
- **G3. Stock is event-sourced; `current_quantity` is a server-maintained cache.** Because counters can't
  be merged and the poultry module already proves the derived model.
- **G4. Completed offline sales are never rejected at sync.** Because money changed hands; stock
  exceptions are surfaced, not refused.
- **G5. Ledger income is posted per payment, not per sale.** Because credit sales are common and the
  ledger must reflect cash reality.
- **G6. Per-company roles replace global admin roles for tenants; platform admins keep `*`.** Because
  every role currently has `*` and that is a security incident waiting to happen.
- **G7. Phone-first identity with OTP; email optional.** Because the market is phone-first; email
  deliverability is poor.
- **G8. Legacy mobile routes stay until forced upgrade; new work is additive.** Because a shipped app
  depends on them (documented in `routes/api.php`).
- **G9. WhatsApp is the primary customer/owner channel; SMS is the fallback; email is tertiary.**
- **G10. Remove the EOQ auto-reorder code; rebuild as explainable suggestions on `product_stats`.**
- **G11. Money `DECIMAL(20,2)` / quantity `DECIMAL(15,3)` server-side; minor units on device.**
- **G12. Demo data is a local-only tenant that can never sync.**

---

## Appendix H — Open questions (decisions the product owner must make)

1. **Product name & branding:** "Budget Pro", "Budget Dynamics", "InvetoTrack" or a new name for the shop
   product? (Affects app store, receipts, emails, wizard.)
2. **Pricing model:** keep the 14-day trial only, or add a free-forever tier (e.g. 1 device, 100 products)
   for the informal-retail segment with paid tiers for multi-device/multi-branch/WhatsApp automation?
3. **Which countries first** (UG, then KE/TZ/RW?) — drives MoMo providers, SMS gateway, tax presets,
   languages.
4. **SMS/WhatsApp provider:** Africa's Talking (UG/KE coverage) vs Twilio vs Meta Cloud API direct.
5. **Grace policy:** how long can an expired tenant keep selling offline (proposal: 7 days), and does the
   web go read-only or fully locked?
6. **Multi-branch and price lists** — Phase 4 or later? (Depends on the target customer size.)
7. **Should the budget module (church pledges) get the same offline treatment in Phase 1 or Phase 4?**
8. **Hardware:** commit to ESC/POS Bluetooth printers only, or also Sunmi/PAX built-in printers?
9. **Data residency/backups:** keep the shared host or move to a VPS (Hetzner/DO) with managed MySQL for
   the sync workload and queue workers?
10. **Who resolves stock conflicts** — owner only, or any stock-keeper?

---

*Related documents:* `BACKEND_API_MASTER_TASKS.md` (older backend backlog — superseded where it conflicts),
`POULTRY_MODULE_MASTER_PLAN.md` and `poultry/ARCHITECTURE_NOTES.md` in the mobile repo (source of the sync
engine being generalised), `API_DOCUMENTATION.md`, `DEPLOYMENT_GUIDE.md`.
