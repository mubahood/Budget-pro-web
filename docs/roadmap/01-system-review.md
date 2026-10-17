# 01. System review

Snapshot of 17 October 2026.

- `kernel/` = `htdocs/budget-pro`
- `portal/` = `htdocs/budget-pro-new`
- `mobo/` = `Desktop/github/budget-pro-mobo`

## 1. Size and shape

| | Kernel | Portal | Apps |
|---|---|---|---|
| Framework | Laravel 10, PHP 8.1+, Encore admin (66 controllers, about 14,900 lines) | Laravel 12, Livewire 3.8, Alpine via Livewire, Vite 7 | Flutter, GetX, Dio, sqflite, workmanager |
| Code | 45 shop services, 15 poultry models, chat, billing, fiscal, Ping Pin | 82 Livewire classes, 95 views, 26 UI and 9 dashboard components | pos 63 files, poultry 65, shop 55, sync 15, account 16, legacy screens 35 |
| Tests | 136 files, 669 methods | 71 files, about 430 methods, plus 7 Vitest tests | 79 files, about 442 tests |
| Git | 130 commits, remote on GitHub | 44 commits | 22 commits, **no remote** |
| CI | migrate, seed, `schema:integrity`, Pint, PHPStan, tests in parallel (its mobile job never runs) | MySQL 8, secrets scan, Pint, larastan, tests (no `npm build`, no Vitest) | none |
| Hosting | cPanel `schooics`, `budget-pro.schooldynamics.ug` | `budget-dynamics.schooldynamics.ug`, deployed in lockstep by `deploy/deploy.sh` | One flavor on Play (`com.budget.dynamics`) |

## 2. Strong (build on it)

- **All twelve master-plan findings fixed:**
  - per-company roles (`app/Support/AdminAccess.php`, `config/permissions.php`);
  - event-sourced stock (`StockService`, ADR 0002);
  - row locks in about 20 services;
  - idempotency middleware;
  - discounts and credit sales correct in the ledger (`SaleService::finalize`, `PaymentService::postIncome`);
  - voids write contra rows;
  - numbering per shop (`NumberSequencer`);
  - business errors as `BusinessRuleException`;
  - one registration service;
  - secure phone tokens.
- **The supermarket tier:** PriceBook, PriceLevel, PromotionEngine, GiftCard, Loyalty, Markdown, ShelfLabel, Tender, Consignment, Deposit, Approval, StockRequest, ShortDated, Shrink, SmartReorder and LandedCost.
- **Messaging and payments:**
  - Meta WhatsApp Cloud API (`MetaWhatsApp.php`) and Africa's Talking SMS;
  - Flutterwave for cards and mobile money in five countries, with request-to-pay through subaccounts.
- **Operations:**
  - backups with a weekly restore drill (`app/Services/Ops/BackupService.php`);
  - an error tracker table on the System health page;
  - `shop:check` gating deploys;
  - a scheduler with prices, fiscal, billing, demo and backups.
- **Portal UX:**
  - command palette (Ctrl/⌘K, including "sell 2 rice") and list shortcuts;
  - toasts with undo, a focus-trapped modal, a filter bar, skeletons with `#[Lazy]`;
  - a phone tab bar under 900 px;
  - the offline web till (service worker on `/sell`, IndexedDB);
  - query budgets per screen in `PerformanceBudgetTest`;
  - real screenshots on the public site.
- **The family:**
  - `config/apps.php` with four app pages;
  - modules chosen at sign-up;
  - `EnsureModule` with a friendly 403 and `POST /modules/{module}`;
  - poultry and budget summary cards;
  - four Flutter flavors (`mobo/lib/core/app_flavor.dart`);
  - English, Swahili and Luganda strings in the app.

## 3. Gaps and risks

| Area | Gap | Where | Tag |
|---|---|---|---|
| Config | `.env.example` lacks `SAAS_*`, `WHATSAPP_*`, `SMS_*`, `MESSAGING_*`, `APP_EDITION`, `SITE_*`; queue `sync`; mail off by default (`config/saas.php:50`); messaging drivers default to `log` (`config/messaging.php`) | kernel and portal `.env.example` | P0 |
| Sign-in | No attempt limit on the classic sign-in, where `MasterPassword` works (plaintext env, `hash_equals`, file log only, no strength rule) | `kernel/app/Admin/Controllers/AuthController.php:41-43`, `app/Support/MasterPassword.php` | P0 |
| Sign-in | Minimum 6 at sign-up and password change, 8 for staff; no 2FA | `RegistrationService.php:86`, `Api/V1/AuthController.php:353` | P0 |
| Modules | API routes never check modules (`api.tenant`, `api.subscription`, `api.perm`, `api.idempotent` only) | `kernel/routes/api.php` | P0 |
| Tenancy | `CompanyScope` applies only to `web` and `admin` guards; the API filters by hand; HeldCart, PriceChange, LabelQueueItem, FiscalSetting and FiscalSubmission have no scope; 4 isolation tests | `kernel/app/Scopes/CompanyScope.php`, `BaseCrudController` | P0 |
| Money | PHP floats with `round()`, no bcmath; 4 float columns remain; budget amounts `(int)` | `Money.php` (formatting only), budget models | P1 |
| Pledges | `paid_amount` overwritten in place: no payment history, no committee | `ContributionRecord.php` | P1 |
| Sync | The legacy v1 `/poultry/sync/*` (phone-clock cursor) and the pre-v1 API still live | `kernel/routes/api.php:435-436` | P1 |
| EFRIS | Client marked "UNCERTAIN, check on the sandbox"; T130, T106 and T108 not built; not certified | `app/Services/Fiscal/Efris/*` | P1 |
| Tests | `.phpunit.result.cache` lists 71 defects from renamed tests; the true pass rate is unknown | kernel | P0 |
| Secrets | `LIVE_ACCOUNTS.local.md` (live shop accounts) in the repo root, ignored only by `.git/info/exclude`, inside a folder MAMP serves | kernel root | P0 |
| Docs | About 20 stale 2025 files (12,000 lines); `BACKEND_API_MASTER_TASKS.md` with 163 open boxes, superseded; `docs/STANDALONE_EDITION_PLAN.md` says "nothing built"; `GOOGLE_PLAY_SUBMISSION.md` says 1.0.0+1 | kernel | P1 |
| Portal | Poultry and pledges have no screens, only summary cards linking to classic | `portal/app/Livewire/Shop/Dashboard/Modules.php` | P1 |
| Portal | Font Awesome 1.1 MB (955 uses), admin CSS 227 KB built, no dark mode, no PWA manifest, `alpinejs` and `dexie` declared but unused | `portal/public/vendor/fa`, `resources/css/admin.css` | P1 |
| Portal | Translations in 8 of 190 views; bulk actions on 3 lists; components over 900 lines (Pos/Index 1127, Products/Index 1021, Sales/Index 920) | `portal/app/Livewire/*` | P1 |
| Portal | `robots.txt` sitemap points at the old address; `SITE_*` contact facts unset, so no WhatsApp on the site | `portal/public/robots.txt`, `config/site.php` | P0 |
| Apps | No remote or CI; no crash reports; no push; three app IDs unconfirmed; support hard-coded (`ContactUsScreen.dart:11-12`); diagnostics-to-WhatsApp dead because the server sends no `meta.support`; excellence plan C3 partly done, E6 payment reversal needs a server route | `mobo/` | P0 to P1 |
| Apps | `flutx` 0.2.0-rc.2 (abandoned pre-release) still a dependency | `mobo/pubspec.yaml` | P1 |
