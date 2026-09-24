# Changelog

All notable changes to Budget Pro will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased] — Master plan execution (SHOP_ONBOARDING_OFFLINE_MASTER_PLAN.md)

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
