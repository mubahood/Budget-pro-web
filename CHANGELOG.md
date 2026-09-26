# Changelog

All notable changes to Budget Pro will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased] — Master plan execution (SHOP_ONBOARDING_OFFLINE_MASTER_PLAN.md)

### International (2026-09-26)
- **Countries:** 30 in setup and sign-up (plus "Another country"), each with its currency, time zone and VAT rate, and mobile-money networks where the country has them. The getting-started list leaves out mobile money where there is none. `Phone` knows 28 national number formats and never reads an unknown country's local number as Ugandan. More currencies: BIF, CDF, MWK, ZMW, SSP.
- **Template packs v4:** products marked East-African (local brands, dishes, boda parts, airtime in shilling amounts) are shown only to shops in the region; 933 of 1,028 products are offered everywhere. Starting prices exist in 24 currencies, with cents where shelves use them.

### Onboarding variety (2026-09-26)
- 22 business types in groups, each with an icon and a line of example products. New types: supermarket, kiosk/duka, fresh produce, butchery, bakery, liquor store, cosmetics, furniture, spare parts (cars & boda) and stationery/printing.
- Template packs v3: 1,016 products across 21 packs (35–67 each, 6–11 categories), with real brands and shelf sizes, most common first. The seeder switches off pack rows that were dropped instead of deleting them. The legacy `restaurant` type uses the `restaurant_bar` pack. `template_pack` records every pack the picks came from.

### Plan prices (2026-09-26)
- Starter UGX 50,000, Business UGX 100,000 and Enterprise UGX 150,000 a month (were 70,000 / 185,000 / 560,000). Yearly stays 10 × monthly ("2 months free"). The USD card prices are now 14 / 27 / 40. Migration `2026_10_02_100001_plan_prices_50_100_150` updates existing rows, and `PlanSeeder` has the same figures for fresh installs. Running subscriptions keep what they paid; the new price applies from their next renewal.

### Phase 0 — Stop the bleeding

#### Security
- **P0-1** Tenant admin roles no longer hold the `*` permission: an explicit `tenant.workspace` allow-list replaces it, Billing/Admin/Ping Pin-Plans menus are pinned to platform admins, and a `PlatformAdminOnly` middleware hard-denies `/subscriptions`, `/plans`, `/companies`, `/auth/users|roles|permissions|menu|logs` and the code generators for non-platform users. (`AdminRolesSeeder`, migration `scope_tenant_admin_permissions`)
- **P0-2** `CompanyScope` is now live under the `admin` guard (it only ever looked at the unused `web` guard), so every admin `findOrFail()` is tenant-safe; `TenantAdminController` turns cross-tenant deletes into clean 404s; `DataExport` gained the scope; the `bcrypt('admin')` default password is gone (a user without a password is a `BusinessRuleException`); the Team (employees) screen now requires a password and a role, never shows credentials, and checks the company on every read/write.

- **P0-3** Subscription grace period (DECISIONS H5): a lapsed tenant keeps 7 days of read-only web access (`EnsureWebAccess`, `/subscription-expired`), the API answers with `X-Subscription-State: grace` instead of 402, and `BusinessRuleException` carries a machine-readable `errors.code` on every 422.
- **P0-14** `/payment/callback` exists: the browser return URL re-verifies the Flutterwave transaction and activates the subscription through the shared `SubscriptionFulfillment` service (also used by the webhook and the verify endpoint); subscription checkout is owner-only (403 otherwise).

#### Shop maths (P0-4 … P0-11)
- Stock is event-sourced: every `StockRecord` carries a signed `quantity_delta`; the product's `current_quantity` is updated with an atomic, row-locked `UPDATE … + delta` inside the movement's transaction, so parallel checkouts can never oversell (20-parallel test in `tests/Feature/Shop/ConcurrentCheckoutTest`). Inbound types (Stock In, Purchase, Return, Adjustment In) now really add stock.
- Movements and sales are never edited or deleted: reversals (`POST stock-records/{id}/reverse`) and voids (`POST sales/{id}/void`) write contra rows; the admin delete buttons now void/reverse.
- `SaleService::checkout` is the single checkout path (API, admin POS, legacy): one transaction for header, lines, movements, payments and ledger; products locked in id order; idempotent on `client_uuid`; line + header discounts allocated pro-rata and reflected in movement revenue/profit; cash over-tender becomes `change_given`, never income.
- Money is posted to the ledger per **payment** (`payments` table, `financial_records.source_type/source_id`): credit sales post nothing until paid, partial payments post as they arrive, marking a sale "Paid" records a real payment, lowering `amount_paid` directly is refused.
- Receipt/invoice numbers are per-company yearly sequences (`RCP-2026-000001`, `number_sequences`, row-locked); the global UNIQUE indexes were replaced by per-company ones.
- The financial period is derived from the business date (`FinancialPeriod::resolveFor`), closed periods refuse writes, and the database guarantees a single Active period per company (generated `active_flag` + unique index).
- `FinancialCategory` duplicates are a 422 (`duplicate_category`) instead of a silent `return false`; categories gained `type`/`status`; `financial_records.amount` is a real DECIMAL.
- Dashboards/reports no longer double count: sales income is read from the ledger only (`HomeController`, `FinancialReportService` cash-basis summary), voided sales are excluded, and low stock uses the per-product `min_stock` threshold (`saas.low_stock_threshold` default). Quick sale saves the price actually charged.
- Quantities are `DECIMAL(15,3)` end-to-end (kg/litre sales); currency labels come from the company (`Money::symbol()`), no more hardcoded UGX.

#### Onboarding
- **P0-12** Inventory forecasting and auto-reorder rules are hidden behind `saas.features.inventory_automation` (default off) until their Phase 4 rebuild; their menu entries are removed and the shadowed `auto-reorder-rules/trigger` route is fixed.
- **P0-13** One `RegistrationService` behind the web form, `/api/v1/auth/register`, the legacy `/api/auth/register` and Ping Pin signup: owner + role, company, membership, trial subscription, default period and account categories in one transaction, with one shared rule set. Phone-only signups keep their phone as username (the `User` hook no longer clobbers it).

#### Mobile app (budget-pro-mobo, commit ece7b27)
- **P0-16** The API token lives in the platform keystore (`flutter_secure_storage`), never in sqlite or logs; logout wipes every cached tenant table and the token in one transaction (with an "N unsynced records" warning), and logging into a different company wipes the previous tenant's data first; poultry rows carry `company_id` and every local read is tenant-scoped.
- **P0-17** Cache refreshes validate the response and replace rows inside one transaction (13 models); search keywords are escaped for `LIKE`; quantity/sales sorts and totals are numeric and decimal-safe (`Utils.double_parse`); loaders close in `try/finally` on all 12 create screens; background syncs are guarded per table/row and the app runs under `runZonedGuarded` + `FlutterError.onError`.
- **P0-18** `markSynced` only cleans rows untouched since the push; tasks/task reports/price history/audit are explicit local-only tables (never pushed, never "pending"); demo farm data is marked clean and skipped by the pusher; last sync time persists in `poultry_meta`; pulled rows are filtered to known columns with tolerant clock parsing. 50 Flutter tests green.


### Phase 1 — Offline foundation

#### Backend
- **P1-1** Standard sync columns (`uuid`, `server_seq`, `client_created_at/updated_at`, `version`, `is_deleted`, `created_by_uuid`, `device_id`) on all shop, finance and budget tables with backfill and `(company_id, uuid)` unique; poultry tables gain `server_seq`. One global `sync_sequence`; the `Syncable` trait bumps seq/version on every write incl. admin edits and turns deletes into tombstones.
- **P1-2** `devices` + `POST /devices/register` (stable `D1…` prefix per device), device list and revoke.
- **P1-3** `POST /sync/push` (per-batch transactions through `SaleService`/`PaymentService`/`StockService`, idempotent by `batch_uuid`, insert-if-absent events, rejected batches roll back), `GET /sync/pull` (seq cursor, paging, multi-table), `POST /sync/bootstrap`.
- **P1-4** Offline stock policy: completed offline sales are never rejected — applied with `allow_negative`, flagged `stock_exception`, conflict card created; push returns authoritative `current_quantity` for touched products.
- **P1-5** `sync_conflicts` inbox: `GET /sync/conflicts`, resolve with mine / server / merged / counted / ignore.
- **P1-6** Entitlement snapshot in `auth/me` and device registration; sync stays open during grace, batches are `held` after it and applied automatically on renewal.
- **P1-7** `SyncTest` (16 tests): replay, batch atomicity, 1,000 same-timestamp rows paged with no skips, tenant isolation, tombstones, missing parent, skewed-clock LWW + inbox resolution, oversell flagging, hold/renew, grace, revoked device, poultry through v2 (v1 endpoints kept).
- `POST /files` (A.6): multipart upload keyed by client uuid (idempotent, tenant folder); `SyncMasterTablesTest` proves every offline-writable master table (catalogue, budget, pledges, finance) is accepted through `/sync/push`; sync-created rows default their user columns (treasurer, created/changed by) to the pushing user.

#### Mobile (budget-pro-mobo)
- **P1-8** New `lib/sync/` engine: versioned `SyncDb` schema, `SyncRepo` (every user action = local rows + outbox ops in one transaction; pending edits coalesce), ordered outbox batches with dead-lettering, `SyncEngine` (device registration, push → bootstrap/paged pull by `server_seq` → participants → conflict mirror), triggers (reconnect, resume, 15-min foreground, 5 s after writes, WorkManager background), sync chip + "Sync & data" screen with the conflict inbox (keep mine / keep server / count stock), tenant guard (company bound to outbox; different company wipes first).
- **P1-9** The 13 local tables keep the names/columns the screens already query and gain sync columns; first launch after upgrade rebuilds the caches and bootstraps; product search uses an FTS index (escaped LIKE fallback).
- **P1-10** Photo queue: images compressed to 1280 px JPEG, uploaded on Wi-Fi (mobile data opt-in), the resulting path saved on the row through the outbox.
- **P1-11** Offline PIN unlock (salted SHA-256, keystore salt, escalating lockout), 401 → re-login without wiping the outbox, tokens from older app versions migrated into secure storage.
- **P1-12** Engine A (OfflineStore/SyncEngine/SyncResources) deleted; the 13 legacy model caches now read local only and write through the repo; all 11 create screens work offline; stock records support Stock In; the poultry engine moved to protocol v2 (paged, seq cursor; v1 endpoints kept a release); farm expenses post to the local ledger; demo-farm expenses never reach the real ledger.
- **P1-13** Engine tests against an in-memory server: outbox ordering, crash between row and op, in-flight edit race, 1,900-row paging, dirty rows never overwritten, tombstones, two-device same-field conflict, two-device offline oversell reconciliation (Σ movements == server quantity on both phones), missing-parent retry, held batches, photo queue, provisional numbers, FTS + injection-safe search. 65 Flutter tests green; debug APK builds.


### Phase 2 — Real POS & inventory, offline

#### Backend (ca0b02d + follow-up)
- **P2-1** Units (sell by piece or crate from one stock), extra product barcodes (carton codes pick the unit), `track_stock`, `is_active`; tombstone deletes.
- **P2-3/P2-4** Payments per method incl. split and credit; customers with credit limits, debt book (oldest-first settlement, account credit, statements); suppliers with payables, payments, statements.
- **P2-5** Receipt text (WhatsApp) and PDF endpoints; server and phone share one layout (golden files on both sides).
- **P2-6** Returns/refunds: restock toggle per line, refund only what was over-paid, contra ledger rows, credit sales owe less, line profit shrinks.
- **P2-7** Goods receipts (cost follows, payables, purchase expense), stock count sessions, adjustment reasons + photos.
- **P2-8** Shifts with cash-up (expected = float + cash − refunds, variance).
- **P2-10** Web admin: customers (statement, record payment), suppliers (pay), units, shifts, receive stock, stock counts, product movement history with add-stock/adjust, shop settings (receipt header/footer, negative-stock policy, low-stock default, require shift), plain menu names.
- **P2-11** `PosInventoryTest` (12), `ShopAdminTest` (4), `sync:soak` command + CI soak test; registry wire-key uniqueness guard. Fixed along the way: poultry `customers` key shadowing shop customers, child client uuids overflowing CHAR(36), unit prices filled before scaling, return profit base, empty product gallery crash, missing `StockCategory` import in the stock-take sync handler.

#### Mobile (budget-pro-mobo)
- **P2-2** Sell screen: FTS search, camera barcode scan (carton barcodes sell cartons), quantity stepper, unit selector, price/discount per line and on the whole sale (owners/managers only), customer picker, hold & resume sales.
- **P2-3** Payment: cash with change, mobile money with transaction ID, card, bank, split, sell on credit (customer + credit limit checked on the phone).
- **P2-5** Receipt: provisional number offline, final number after sync (“Ref D1-…”), WhatsApp text, share as image, Bluetooth ESC/POS printing (58/80 mm).
- **P2-6** Returns per line with restock toggle; same-day void (permission-gated).
- **P2-4/P2-7/P2-8** Customers & debt book with statement and payments; suppliers; receive stock; stock counts with scanning; shift open/close with live cash-up; product stock history with running balance, “Add stock” and “Set counted qty”; adjustment reasons.
- **P2-9** Shop home: Sell button, Today (sales, cash, mobile money, profit, credit, what customers owe), low stock by per-product minimum, sync chip.
- Every POS action is one local transaction + outbox batch; provisional rows are replaced by the server’s on sync. 74 Flutter tests (incl. crate sale, credit, returns + void convergence, cash-up, GRN + count, held carts, receipt golden, ESC/POS bytes); debug APK builds (Kotlin Gradle plugin raised to 2.2.0 for the new plugins).

### Phase 3 — Onboarding, team, notifications, billing

#### Backend (1a9d9fa, d3c60f2 + close-out)
- **P3-1** Phone-first identity: E.164 phones (UG/KE/TZ/RW), one-time codes over WhatsApp (Meta Cloud API) with SMS fallback (Africa's Talking) and email, rate limits and lockout, register with a verified phone, login by phone or email, reset by code, profile, session list/revoke, 180-day tokens + refresh. Message log + database queue; `log` drivers until provider keys are set (config/messaging.php).
- **P3-2** Setup wizard API and web `/setup` (business → products → money → team → done) with step timings; country presets (currency, timezone, VAT, mobile-money providers); 9 template packs (185 products with Kampala prices) as platform-editable data; CSV import with preview.
- **P3-4** Per-company roles (owner, manager, cashier, stock keeper, accountant, viewer) with per-company overrides; one permission map for the API, per-op checks on sync push, discount/price-override and cost-price gating, web admin sections enforced (not just hidden menus) and the web role kept in step; invites by WhatsApp/SMS/email with API + web accept page, resend/revoke, deactivate (signs out everywhere), transfer ownership, member activity. Existing staff keep manager access.
- **P3-5** Notification centre + per-user preferences; `saas:hourly` sends the 8 pm daily summary (opt-in), low-stock digest and unsynced-phone alert in each shop's timezone, once per day; cash-up variance alert on shift close; payment-received and billing notices. Scheduler + `queue:work --stop-when-empty` run from cron (decision H9).
- **P3-6** Billing lifecycle: trial reminders (3 days, 1 day) → **Free plan** after the trial (H2); paid plans → `past_due` with reminders → Free after the 7 grace days; cancel at period end/resume; prorated plan changes (unused days credited, fully-covered changes applied at once); invoice numbers + PDF; local prices + M-Pesa/mobile money for KES/TZS/RWF; tenant billing page on the web.
- **P3-7** Plan limits enforced online (users, phones, products → 422 `plan_limit_reached` with upgrade link), usage in the entitlement snapshot; WhatsApp automation is a paid feature.
- **P3-8** Getting-started checklist (API + dashboard card), module enablement per company (hidden from the web menu and routes, from the app home/menu); existing shops keep every module and skip the wizard.
- **P3-9** Dashboard "today/this month" follows the shop's timezone (`CONVERT_TZ` + `@local_today`); hardcoded UGX removed from web screens and PDFs.
- **P3-10** "Budget Pro" everywhere on the web (admin title/logo, API manifest).
- Fix found on the production run: a plan that lapsed long ago moves straight to Free without catch-up reminders; only the latest due reminder is ever sent; cancelled messages are never delivered.
- Tests: TeamPermissionsTest, BillingLifecycleTest, OnboardingTest, PhoneAuthTest, TeamWebTest, SetupWizardTest — 255 green; phpstan clean.

#### Mobile (budget-pro-mobo 75f4641)
- New sign-in: phone or email + password, sign in with a code, forgot password, register with a verified phone; "Try the demo shop" (local-only tenant, never syncs; "Start my real shop" wipes it).
- Setup wizard with template products created offline through the outbox; team, notifications (bell + preferences), plan & billing (usage, prorated prices, MoMo/card checkout, invoices), settings (language, modules).
- Role-aware POS checks, shop menu, cost prices and product edits (cached permissions, offline); module-aware home; getting-started card.
- English, Kiswahili and Luganda; app renamed **Budget Pro** 2.0.0; 3 value slides; Play listing text (en, sw). 79 Flutter tests; release APK builds.

### Phase 4 — Purchasing, reports, analytics, hardening

#### Backend (cda8c7a … )
- **P4-1** Purchase orders with line items and a simple lifecycle (draft → sent → partially received → received / cancelled), sent as WhatsApp text or through the messaging provider, received against the order with partial deliveries and cost variances; returns to suppliers (stock out at cost, supplier balance and statement, cash refunds as income); supplier lead times; API and web screens.
- **P4-2** Thirteen reports from one service (sales by day/cashier/payment/customer/category/product, profit and margin, stock value, low and dead stock, best sellers, customer aging, supplier balances, cash-up, VAT, purchases, movement audit, expiry) as JSON, PDF and Excel (dependency-free writer); web Reports page; each report gated by role.
- **P4-3** Nightly `product_stats` (7/30/90-day sales, cover), explainable reorder list turned into draft purchase orders per supplier, stock-out forecast behind the plan's `forecasting` flag; the old EOQ forecasting/auto-reorder code and its empty tables removed.
- **P4-4** Locations with stock per location (existing stock backfilled into "Main shop"), transfers, phones assigned to a location, per-location checkout/sync/receiving; batch and expiry tracking with First-Expiry-First-Out picking, voids restoring the same batches, batches travelling with transfers, expiries in the morning digest. Multi-location is a Business-plan feature. Property test over random movement sequences.
- **P4-5** Fresh installs work (`migrate` from an empty database runs every migration; stubbed budget migrations guarded); 39 foreign keys and status checks added where the data already allows (`schema:integrity` reports the rest), statuses also guarded in the models; uniqueness on online creates plus a duplicate finder and merge for data created offline; `DatabaseSeeder` for fresh installs; default pack units per business type.
- **P4-6** Request ids in every response and log line; grouped error tracking (Sentry when configured); platform System health page (errors, queue, scheduler, sync, messages, backups, old-app share); nightly verified backups and a weekly restore drill; owners download all their data or schedule deletion with a 30-day grace.
- **P4-7** OpenAPI 3.1 generated from routes and validation rules at `/api/docs`, Postman collection, eight ADRs, owner quick start and runbooks (sync incidents, backups).
- **P4-8** Legacy retirement machinery: every pre-v1 call counted, `legacy:status` adoption report, `LEGACY_API_ENABLED` switch that answers "please update", minimum app version (426) for new apps; v1 `members` and `financial-reports` so the new app needs no legacy route.
- Fixes found on the way: team list showed every member as manager (partial select without company_id); concurrency test cleanup order (caught by the new foreign keys).

#### Mobile (budget-pro-mobo 0b805ec, 81df413)
- Reports on the phone: today/week/month from the local database (sales by day/payment/product, profit, what customers owe), any report and range from the server.
- No legacy calls left; `X-App-Version` on every request and a full-screen "Please update" on 426.

### Phase 5 — Differentiators (first three from Part E)
- **E1 WhatsApp receipts** — receipts go to the customer on WhatsApp (approved template, SMS fallback) with a link to the full receipt and PDF; automatic after a sale when the shop chose WhatsApp receipts; "Text it to the customer" on the phone and web. Paid-plan feature.
- **E2 Debt book reminders** — opt-in weekly reminders for overdue balances (credit terms per shop or customer), manual "Send a reminder" on the phone and web.
- **E3 Mobile-money request-to-pay** — the shop registers its payout number; cashiers request the balance from the customer's phone (MTN/Airtel/M-Pesa via Flutterwave), the phone waits for approval, and the payment is recorded on the sale exactly once (status check or webhook).
- Already delivered earlier and counted as Part E: thermal printing (E4), units (E6), self-explaining reorder list (E11), POs on WhatsApp (E12), expiry/FEFO (E13), dead stock (E14), multi-branch (E16), daily WhatsApp summary (E17), cash-up variance (E19), VAT/Excel exports (E20).

### Client fixes (2026-09-25)
- Web selling saved no items ("Please add at least one item") because the form's `_remove_` flag was treated as "removed" whenever it was present. Fixed, with a test that sells through the web form.
- The dashboard's sales overview, daily sales/profit chart, quick stats and top products now include old-app sales and leave out voided sales (E53).
- Returns: a "Return items" form on the web sale page (good → back to stock, faulty → not restocked; profit and refunds follow), and the returns report shows every return (E54).
- `stock:apply-old-writeoffs` applies old damage/expiry write-offs that never reduced stock (E55).

### Web admin review (2026-09-25)
- **New dashboard:** quick actions (new sale, receive stock, damage, return, expense, debt payment), a date-range switcher with comparison to the period before, sales / profit / money received / expenses / net / returns, a daily chart, money by payment method, best sellers, who owes you (receive / remind) and whom you owe, stock value and what to reorder, recent sales, and alerts (negative stock, products without a cost, expiring batches, shifts left open) (E56).
- **Correct numbers everywhere:** sales analytics, financial reports, shop reports, the WhatsApp daily summary and the API dashboard count every app's sales once, leave out voids, net returns, ignore deleted expenses and stop deducting stock purchases twice. Dates are the shop's local day (E56, E58, E61).
- **Selling on the web:** product search (name, SKU, barcode), prices fill in and totals update as you type, cash sales are paid by default, credit sales go into the customer's debt book, receive and reverse payments on the sale page, a clear sale page with receipt / WhatsApp / return / void, and read-only status (E60).
- **Stock screens:** category and product pickers work again, the stock-movement form can't record accidental zero-price sales, a delivery with a blank cost keeps the product's cost, stock counts no longer erase sales made while counting (E57), and movements that belong to a sale or delivery are undone on that document.
- **Security:** receipts, invoices and reports are no longer downloadable by guessing a link; every new financial report was being written to the same shared file (E59).
- **Navigation:** tidier menu with the most-used screens first, Ping Pin and poultry hidden for shops that don't use them, the Mobile money page opens for shop owners again, a global search for receipts, customers and products, and safe keyboard shortcuts (E62).
- Fixed pages that crashed: stock movement details, and new-record pages under device tracking.

---

## [2.0.0] - 2025-12-09

### 🎉 Initial Envato Market Release

This is the first public release of Budget Pro, prepared for CodeCanyon (Envato Market).

### Added

#### Core Features (36+)
- **Inventory Management System**
  - Complete stock control with categories and subcategories
  - SKU and barcode support
  - Stock level tracking with low stock alerts
  - Measuring units customization
  - Batch operations support
  - Import/Export functionality
  - Stock adjustment logs
  - Product images support

- **Sales Management**
  - Quick POS-style sale recording
  - Customer management
  - Multiple payment methods (Cash, Card, Mobile Money, Bank Transfer)
  - Payment status tracking (Paid, Partially Paid, Pending)
  - Automatic stock deduction
  - Sale receipts and invoices (PDF)
  - Customer purchase history

- **Financial Management**
  - Income and expense tracking
  - Account categories management
  - Financial periods/cycles
  - Payment method tracking
  - Transaction references
  - Approval workflows

- **Purchase Order System**
  - PO creation and management
  - Supplier management
  - Delivery tracking
  - Payment status
  - Automatic inventory updates
  - PO history and reports

- **Reporting & Analytics**
  - Financial reports (13 period types)
  - Inventory valuation reports
  - Sales analysis
  - Profit/loss statements
  - Stock movement reports
  - Customizable date ranges
  - PDF export capability
  - Visual charts and graphs

- **Multi-Tenant SaaS Architecture**
  - Multiple companies support
  - Complete data isolation
  - Company-specific settings
  - License management system
  - Role-based permissions per company
  - Custom branding per tenant

- **User Management**
  - Role-based access control (RBAC)
  - Granular permissions
  - User activity tracking
  - Audit logs
  - Password management
  - Profile customization

- **Security Features**
  - Role-based permissions
  - Data encryption
  - Audit logging
  - Session management
  - CSRF protection
  - XSS prevention
  - SQL injection protection

- **Advanced Features**
  - REST API with authentication
  - Advanced search and filtering
  - Bulk operations
  - Data export (Excel, CSV, PDF)
  - Email notifications
  - Backup and restore
  - Multi-language support structure
  - Responsive mobile interface

#### Documentation
- Comprehensive README with 36+ features
- API Documentation (18KB)
- Installation Guide (complete)
- Postman API Collection
- Quick Reference Guide
- Envato Market Preparation Plan

#### Developer Tools
- Complete demo data seeder (3 companies, 600+ transactions)
- Database migrations (51 files)
- PSR-12 compliant codebase
- Clean architecture
- RESTful API endpoints
- Comprehensive routing
- Service layer pattern

### Changed
- Updated branding from "inveto-track-web" to "Budget Pro"
- Optimized database queries for better performance
- Improved UI/UX across all modules
- Enhanced error handling
- Streamlined workflows

### Technical Details
- **Framework:** Laravel 10.x
- **PHP Version:** 8.1+ required, 8.3+ recommended
- **Database:** MySQL 5.7+ / MariaDB 10.3+
- **Frontend:** Blade templates, JavaScript, Bootstrap
- **Admin Panel:** Laravel-Admin (Encore Admin)
- **PDF Generation:** DomPDF
- **Code Quality:** PSR-12 compliant (237 files formatted)

### Infrastructure
- 20,164+ lines of production PHP code
- 16 Eloquent models
- 26 admin controllers
- 7 service classes
- 7 custom traits
- 51 database migrations
- Comprehensive test coverage structure

### Security
- Environment-based configuration
- Secure password hashing (bcrypt)
- CSRF protection enabled
- XSS prevention
- SQL injection protection
- Rate limiting on API endpoints
- Session security
- File upload validation

---

## [Unreleased]

### Planned for v2.1.0 (Q1 2026)

#### New Features
- One-click installation wizard
- Auto-update system
- License key verification
- Enhanced dashboard analytics
- Advanced reporting widgets
- Email template customization
- SMS notification integration
- WhatsApp integration
- Multi-currency support (real-time conversion)
- Tax calculation automation

#### Improvements
- Performance optimizations
- Enhanced mobile responsiveness
- Better search functionality
- Improved import/export
- Enhanced API documentation
- Additional payment gateways
- Barcode scanning app integration

#### Bug Fixes
- Minor UI inconsistencies
- Edge case handling improvements
- Performance enhancements

---

## [Planned] - Future Releases

### v2.2.0 - Advanced Analytics (Q2 2026)
- AI-powered sales predictions
- Inventory optimization suggestions
- Advanced business intelligence
- Custom dashboard builder
- Real-time analytics

### v2.3.0 - E-commerce Integration (Q2 2026)
- Online store module
- Shopping cart functionality
- Payment gateway integrations
- Customer portal
- Order tracking

### v2.4.0 - Mobile Apps (Q3 2026)
- iOS native app
- Android native app
- Barcode scanner integration
- Offline mode support
- Real-time sync

### v2.5.0 - Advanced Features (Q3 2026)
- Subscription billing
- Recurring invoices
- Project management
- Time tracking
- Employee management

### v3.0.0 - Enterprise Features (Q4 2026)
- Multi-location support
- Warehouse management
- Manufacturing module
- Advanced logistics
- Custom workflows

---

## Version History

### Version 2.0.0 - December 9, 2025
- Initial public release
- 36+ core features
- Multi-tenant SaaS architecture
- Complete documentation
- Demo data system
- PSR-12 compliant code

---

## Upgrade Guides

### Upgrading to 2.0.0 from Beta
If you were using a beta version, please contact support for migration assistance.

**Important Notes:**
1. Always backup your database before upgrading
2. Test upgrades on staging environment first
3. Review breaking changes in documentation
4. Run `php artisan migrate` after updating
5. Clear all caches after upgrade

---

## Support & Feedback

### Reporting Issues
Please report bugs and issues through:
- **Email:** support@budgetpro.com
- **Support Portal:** https://support.budgetpro.com

### Feature Requests
We welcome feature requests! Submit via:
- **Email:** features@budgetpro.com
- **Community Forum:** https://community.budgetpro.com

### Contributing
Budget Pro is a commercial product. For partnership or contribution inquiries:
- **Email:** partners@budgetpro.com

---

## License

Budget Pro is commercial software. See [LICENSE.md](LICENSE.md) for details.

**Purchase Options:**
- Regular License: Single-use application
- Extended License: SaaS/resale applications

For licensing questions: licensing@budgetpro.com

---

## Credits

**Developed by:** Budget Pro Team  
**Framework:** Laravel Framework  
**Admin Panel:** Encore Admin (Laravel-Admin)  
**PDF Generation:** DomPDF  
**UI Framework:** Bootstrap 5

---

## Stay Updated

- **Website:** https://budgetpro.com
- **Documentation:** https://docs.budgetpro.com
- **Blog:** https://blog.budgetpro.com
- **Twitter:** @BudgetProApp
- **YouTube:** Budget Pro Tutorials

---

**Note:** This changelog follows [Semantic Versioning](https://semver.org/). Version numbers use the format MAJOR.MINOR.PATCH.

- **MAJOR:** Incompatible API changes
- **MINOR:** Backwards-compatible new features
- **PATCH:** Backwards-compatible bug fixes

Last Updated: December 9, 2025
