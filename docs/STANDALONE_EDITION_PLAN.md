# Budget Pro as SaaS and as a one-off product

**What this is:** a plan for selling Budget Pro two ways from the same code, as True-Doctor already does.

- **SaaS**: shops sign up online and pay monthly or yearly. We host everything.
- **One-off ("standalone")**: a shop, supermarket or chain buys a copy once. It runs on **their own server** with **their own brand**, and they pay again only for updates after the included period.

**Sources**
- True-Doctor's design and build: `true-doctor/docs/editions.md`, `docs/proposals/standalone-edition.md`, `docs/proposals/standalone-execution-plan.md` and `docs/decisions.md` (the 2026-10-15 entries).
- True-Doctor's code: `app/Support/Edition.php`, `app/Services/Install/*`, `app/Services/Releases/*`, `app/Support/Licensing/*`, `public/install.php` and others.
- A full inventory of Budget Pro's SaaS-specific code: the kernel `budget-pro`, the web app `budget-pro-new` and the phone app `budget-pro-mobo`.

**Status:** proposal (16 Oct 2026). Nothing in it is built yet. Every file and class named as existing was checked to exist.

---

## Contents

1. [The answer in one page](#1-the-answer-in-one-page)
2. [How True-Doctor does it](#2-how-true-doctor-does-it)
3. [What is different about Budget Pro](#3-what-is-different-about-budget-pro)
4. [Rules the work must keep](#4-rules-the-work-must-keep)
5. [Part A: the edition switch](#5-part-a-the-edition-switch)
6. [Part B: exactly one business per installation](#6-part-b-exactly-one-business-per-installation)
7. [Part C: plans, limits and the licence](#7-part-c-plans-limits-and-the-licence)
8. [Part D: one address, two apps (the package layout)](#8-part-d-one-address-two-apps-the-package-layout)
9. [Part E: the installer](#9-part-e-the-installer)
10. [Part F: releases, built from what is live](#10-part-f-releases-built-from-what-is-live)
11. [Part G: selling a copy](#11-part-g-selling-a-copy)
12. [Part H: updates and backups on the buyer's server](#12-part-h-updates-and-backups-on-the-buyers-server)
13. [Part I: messaging, payments and fiscal on the buyer's own accounts](#13-part-i-messaging-payments-and-fiscal-on-the-buyers-own-accounts)
14. [Part J: the phone app](#14-part-j-the-phone-app)
15. [Part K: the buyer's brand](#15-part-k-the-buyers-brand)
16. [The scheduler, per edition](#16-the-scheduler-per-edition)
17. [Data model](#17-data-model)
18. [Testing](#18-testing)
19. [Delivery plan](#19-delivery-plan)
20. [Risks](#20-risks)
21. [Found while preparing this report](#21-found-while-preparing-this-report)
22. [Questions for the owner](#22-questions-for-the-owner)
23. [Appendix: SaaS surfaces and what each edition does with them](#23-appendix-saas-surfaces-and-what-each-edition-does-with-them)

---

## 1. The answer in one page

Budget Pro can do exactly what True-Doctor does. Most of the hard design work has already been proven there, on a real client.

| | SaaS (today) | One-off (new) |
|---|---|---|
| Who hosts it | We do (budget-dynamics.schooldynamics.ug and budget-pro.schooldynamics.ug) | The buyer, on their own hosting (cPanel shared hosting is enough) |
| How they pay | Starter, Business or Enterprise, monthly or yearly, after a 14-day trial | Once, for a copy plus a period of updates (for example 12 months) |
| Businesses per install | Many, each walled off by `company_id` | **Exactly one.** A chain is one business with several stores (locations), which Budget Pro already supports |
| Look | Budget Pro | The buyer's logo, colour and name, from the sign-in page to receipts, emails and PDFs |
| Updates | We deploy, everyone has it | The owner clicks **Back up and update** on their own screen while their update period lasts |
| Where the data lives | Our server | Theirs. We never see it |
| What they receive | An account | A signed ZIP and a signed `licence.json`, from their buyer page |
| Limits | Set by the plan | Set by the licence: update period and, optionally, team members, stores and phones. **Never products, sales or records** |
| Phone app | The same Play Store app | The same Play Store app, pointed at their server (new "Server address" option) |

**How it works:** the edition is one setting, `APP_EDITION=saas|standalone`. There are no branches, forks or copied folders.
- **A standalone install is the SaaS application with exactly one company in it.** `company_id`, `CompanyScope` and every shop rule stay as they are. Nothing about selling, stock or money ever says "if standalone".
- Only what *surrounds* the shop changes:
  - the public site, sign-up and trials;
  - plans, billing and the platform screens;
  - the demo shops;
  - the master password.

**What we build:**
- an installer that works on cPanel without a command line;
- signed licences checked offline;
- releases staged from the browser and proved by a clean install before they can be sold;
- a self-updater with automatic rollback;
- nightly backups;
- a buyer page with downloads;
- a "Server address" option in the phone app;
- the buyer's branding.

**What is genuinely new for Budget Pro, compared with True-Doctor:**
1. Budget Pro is **two Laravel apps sharing one database and one codebase** (the kernel and the new web interface). The package ships both, and a small front controller serves both from **one web address** (§8).
2. **Phones must be able to point at the buyer's server.** Today the app's address is hard-coded (§14).
3. **The buyer brings their own SMS, WhatsApp, email and Flutterwave accounts** (§13).
4. Two **demo** features create extra companies and must be off (§6).

**Size:** about nine phases (§19). Phases 1–4 give a product we can sell and install by hand; phases 5–8 make it self-service. True-Doctor's version took about two days with the design already written. Budget Pro's two-app shape and the phone-app change add roughly half again.

---

## 2. How True-Doctor does it

This section summarises what was built and proven at Divine Dental Clinic (October 2026), so the plan below can refer back to it.

| Piece | How True-Doctor does it | Where |
|---|---|---|
| Edition switch | `APP_EDITION` in `config/edition.php`. `Edition::isSaas()` / `isStandalone()` / `version()`, where version comes from `RELEASE.json` | `app/Support/Edition.php` |
| Route guard | `edition:saas` / `edition:standalone` middleware. The wrong edition gets **404**, as if the page did not exist. Public site, sign-up, subscription, super panel and payment callbacks are `edition:saas`; Licence & updates is `edition:standalone` | `app/Http/Middleware/RequireEdition.php`, `routes/web.php` |
| Menu | Menu entries can carry an `edition` key: the Platform section is SaaS only, Licence & updates is standalone only | `app/Support/Navigation.php` |
| One organisation | Model guard, a unique `hospitals.standalone_slot` column, and **database triggers** (insert/update force the slot to 1, delete is refused), installed by the installer and restored by backups | `app/Services/Install/SingleEnterpriseGuard.php` |
| Install marker | `installed.json`. A copy refuses to run if the marker's edition and the setting disagree, and a copy with no marker goes to the installer | `app/Http/Middleware/EnsureInstalled.php` |
| Licence | `licence.json` signed with Ed25519 (libsodium) and checked offline against the public keys inside the release. It limits updates and optionally staff, and **never** care. A bad licence only shows a banner to admins | `app/Support/Licensing/*`, `app/Services/Licensing/Signer.php` |
| Releases | Super › Standalone releases: one form (version and notes prefilled), then the build: `git archive main`, the deployed `vendor/`, the committed `public/build`, an allow-list, a secret scan, a signed `PACKAGE.json`, and a **clean install of the ZIP on an empty MySQL database**. Only then **Publish** | `app/Services/Releases/*`, `app/Jobs/BuildStandaloneRelease.php` |
| Selling | `standalone_products` / `_orders` / `_licences` / `_downloads`; a public page; Pesapal checkout; idempotent settlement; offline issuing; a buyer page with a 24-hour magic link; downloads; renewals; address change once every 30 days | `app/Services/StandaloneSalesService.php` |
| Installer | `install.php` that runs **before Laravel can boot** (no `.env` yet). It finds the app up to 4 folders above the web root, checks every file against the signed list, refuses a non-InnoDB server, migrates in short browser steps, writes `.env` safely, creates the organisation and owner, and sends a test email | `public/install.php`, `app/Services/Install/StandaloneInstaller.php` |
| Updates | Settings › Licence & updates: Check now, then Back up and update; or Upload and update offline. It runs **only from the scheduler** (CLI): back up, swap files by the signed list, migrate in a fresh process, health check, and **automatic rollback** of code and database on any failure | `app/Services/Install/StandaloneUpdater.php`, `SystemState.php` |
| Backups | Nightly at 02:15, seven kept. A PHP dump (it includes the triggers and works without mysqldump), plus `.env`, licence and uploaded files, with restore instructions. Downloadable | `app/Services/Install/DatabaseBackup.php`, `InstallationBackups.php` |
| Branding | Logo, wide logo, colour (≥4.5:1 contrast) and tagline, applied to sign-in, the back office, emails, PDFs and error/maintenance pages. The SaaS sign-in stays True-Doctor's | `app/Support/HospitalBrand.php`, `BrandColour.php`, `DocumentBrand.php` |

**Lessons True-Doctor paid for, and this plan builds in from the start:**
1. **The installer must not use anything from the framework before it boots it.** It once called `storage_path()` and refused every licence. A test now checks the whole file for such calls.
2. **Find the app from nested web folders.** cPanel addon domains sit inside `public_html`.
3. **A copy with no `.env` must open the installer, not a blank error.**
4. **Force InnoDB.** One host defaulted to MyISAM and the install died at migration 16.
5. **Set file modes in the ZIP explicitly.** Files 0644; only the writable folders 0777.
6. **Prove every release by installing the ZIP for real,** and check the commit inside the package before publishing (1.0.3 was published from an old build).
7. **Updates never run inside a web request.** A request can be cut off halfway through copying files.
8. **Key continuity.** An installed copy trusts only the keys its own release shipped, so old public keys are never dropped.
9. **Create magic links when the email is actually sent,** not when it is queued.
10. **Run the cron every minute.** A `*/19` cron silently skipped every job due at another minute. Budget Pro's live cron had the same fault until 27 Sep.
11. **Mail:** use the host's own server name on port 465, because `mail.<domain>` often carries the wrong certificate. Write `MAIL_FROM_NAME` as the organisation's name.
12. **Point PDFs and `public_path()` at the real web root.**

---

## 3. What is different about Budget Pro

| Budget Pro fact | Why it matters | Where it is handled |
|---|---|---|
| **Two apps.** The kernel `budget-pro` (Laravel 10: schema, services, `/api/v1` for phones, classic screens, the scheduler) and the web interface `budget-pro-new` (Laravel 12 + Livewire), which loads the kernel's `app/` through Composer and bridges its config | A release contains both. They must share a database, their media and **one encryption key**, and be served from one address | §8, §9 |
| `vendor/` is committed in the kernel (9,675 files, though git-ignored) but **not** in the web app. The web app's `public/build` is committed | The release builder takes the kernel's `vendor` from `git archive` and the web app's from what the live deploy installed | §10 |
| The phone app's server is hard-coded: `Utils.dart:237-241` → `budget-pro.schooldynamics.ug` | Phones cannot reach a buyer's server today | §14 |
| SMS (Africa's Talking), WhatsApp (Meta) and email are platform `.env` keys; mobile-money collections use **Flutterwave subaccounts under our account** with our fee | A buyer needs their own accounts, and our fee does not apply | §13 |
| OTP codes (sign-in, reset) travel by WhatsApp, SMS or email | Without at least one working channel, "forgot password" is dead on a buyer's server | §9, §13 |
| **Private demo shops** (`DemoShopService`) and the **public demo** (`PublicDemo`, **on by default**) create extra companies and delete old ones | They break "one business" and would publish a known login | §6 |
| The **master password** (`MASTER_PASSWORD`) opens any account | It must never exist on a buyer's server: their data is theirs | §5 |
| `companies.license_expire`, `plans.interval='lifetime'` and `Company::activateSubscription` already exist | One-off access can reuse them: a Standalone plan with lifetime interval keeps every quota check working with no shop-code changes | §7 |
| Each company has its own timezone (`LocalDate`) and currency | True-Doctor's `APP_TIMEZONE` fix isn't needed; the server stays on UTC | — |
| Owner role id 2 is hard-coded (`EnsureCompanyOwnerRole`, `User::ensureCompanyOwnerRole`) | A fresh install must seed the roles in the same order | §9 |
| `deploy.sh` runs `db:seed` (and so `PlanSeeder`, which gives plan-less companies a Business subscription) | Standalone uses its own reference seeder | §9 |
| `shop:check` (read-only health check of the pair) already exists | It becomes the updater's health check | §12 |

---

## 4. Rules the work must keep

These are the rules from True-Doctor's proposal, adapted. Each one has a check, and the work isn't done until every check passes.

- **R1 — One codebase.** One `main` for the kernel, one `master` for the web app, and the edition chosen by `APP_EDITION`. No fork, no edition branch, no copied project. *Check:* a fix merged once reaches both the SaaS and the next release.
- **R2 — Everything else stays the same.** Till, products, stock, customers, debts, suppliers, money, reports, supermarket features, offline phone sync, chat, poultry, and budgets & pledges all behave identically. *Check:* both test suites pass as SaaS, and the smoke walks (§18) pass as standalone.
- **R3 — One business per installation.** No screen, API, command or code can create a second company. *Check:* a test tries every creation path (§6) and every one is refused, including a raw SQL insert.
- **R4 — Installable without a developer.** From an uploaded ZIP to a working sign-in on cPanel, using only the installer and the guide. *Check:* a clean-room install by someone non-technical.
- **R5 — Releases from the browser.** One form and one button, visible progress, then Publish. *Check:* a release staged, built and published using only the browser.
- **R6 — The ZIP contains everything.** Both apps, both `vendor/`, the built assets, all migrations and seeders, the installer and the guide. *Check:* PHP and MySQL alone are enough.
- **R7 — Buyers start on the latest.** *Check:* a release can't be published until its ZIP has installed from an empty database.
- **R8 — Paying gives access at once.** *Check:* a sandbox purchase ends in a working download link with nobody acting at our end.
- **R9 — The guide is where the download is,** and inside every ZIP.
- **R10 — Managed from the platform screens.** Releases, products, prices and licences (including offline sales). *Check:* changing a price or publishing a release needs no deploy.
- **R11 — Simple.** Prefilled forms; the usual case is review and one click.

**Safety floor:**
- **S1 — No secret ever ships.** No `.env`, Flutterwave keys, master password, `ssh.txt`, database dumps or `storage/` contents. Enforced by an allow-list plus a scan that fails the build.
- **S2 — The SaaS never breaks.** Every phase leaves both apps deployable, and both suites stay green.
- **S3 — Licensing never stops a shop selling.** An expired, missing or mismatched licence may stop updates and show the owner a banner. It never stops a sale, a payment, a stock movement or anyone seeing their records.

---

## 5. Part A: the edition switch

### 5.1 Configuration
- **New file:** `budget-pro/config/edition.php`, shaped like True-Doctor's. Keys: `name`, `licence_path`, `installed_path`, `update_server` (default `https://budget-dynamics.schooldynamics.ug`), `public_keys` (from `RELEASE_KEYS.json`), `signing_key_id`, `signing_secret`, `release_sources`, `test_database`.
- **Both apps:** add `'edition'` to the bridged list in `budget-pro-new/config/budgetpro.php`, so both apps read one definition. Both `.env` files carry `APP_EDITION`; the bridge reads the web app's own `.env`.
- **One helper:** `App\Support\Edition` lives in the kernel (`budget-pro/app/Support/Edition.php`). Because the web app autoloads the kernel's `app/`, both apps share the exact same class.

### 5.2 Routes
- **Middleware:** `App\Http\Middleware\RequireEdition`, aliased `edition` in **both** apps: the kernel's `app/Http/Kernel.php` and the web app's `bootstrap/app.php`. The wrong edition gets a 404.
- **`edition:saas`, web app:**
  - the marketing site: `/`, `/features`, `/pricing`, `/security`, `/contact`, `/privacy`, `/terms`, `/get-app`, `/sitemap.xml`
  - `/register`, `/demo*`, `/demo/enter/{demo}`
  - `/plan`, `/plan/return`, `/plan/invoices/*`
  - the new `/standalone*` and `/my-licence*`
- **`edition:saas`, kernel:**
  - `auth/register`, `billing*`, `subscription-expired`
  - platform screens: companies, plans, subscriptions, pingpin-plans, product-templates, gens, gen
  - API `v1/plans`, `v1/subscription*`, `v1/auth/register`, all of PingPin, and the legacy `api/auth/register`
  - `payment/callback`
- **Standalone `/`:** the sign-in page, or the dashboard when signed in. This is `EditionHomeController`, as in True-Doctor.
- **`edition:standalone`:** Settings › Licence & updates (web app `/settings/system`), its upload and backup-download routes, and `GET /api/v1/system`.
- **Both editions:** sign-in, password reset (OTP), invites, public receipts `/r/*`, `/stop/*`, the API, and the **Flutterwave webhook**.
  - **The webhook is in both** because it also settles shops' mobile-money collections (`MOMO-` references, `MomoCollections::settle`). Split it: the subscription branch is SaaS only, and the MoMo branch runs in both.
  - True-Doctor learned the same lesson when a route needed by password reset was marked SaaS only.

### 5.3 Menus and screens
- **Web app (`App\Shop\Navigation`):** add an `edition` key. "My plan" is SaaS only; "Licence & updates" is standalone only, under Settings.
- **Web app layout:** hide the plan chip, the demo invite and banner, and "Chat with support" (or point it at `site.whatsapp`, configurable per install).
- **Web app sign-in page:** hide "Create your shop — free" and "Open the demo shop".
- **Classic:** `AdminAccess::PLATFORM_MENU_TITLES` stays SaaS only. A standalone install has **no platform admin**: the shop owner is the top of the tree.

### 5.4 Things that must be switched *off* on a buyer's server
- **Master password:** `MasterPassword::matches()` returns false whenever `Edition::isStandalone()`, whatever `.env` says. The installer never writes `MASTER_PASSWORD`, and the package's `.env.example` doesn't contain it.
- **Demos:** `PublicDemo::enabled()` and `DemoShopService::allowed()` return false. The demo's scheduled task isn't registered.
- **SaaS-only code paths:**
  - PingPin billing and sign-up
  - `LEGACY_API_ENABLED`
  - Lifecycle (trial to Free), `billing:reconcile`
  - `onboarding:purge-demos`, `ops drill`
  - OnboardingEvents funnel analytics (optional)
- **Purge:** `ops purge` / "Delete my shop" becomes **"Close and wipe this installation"** (owner and password, 30-day grace, as today), and it never runs on its own.

### 5.5 Install marker
`App\Http\Middleware\EnsureInstalled` goes first in the `web` and `api` groups of **both** apps:
- **No marker on standalone:** web requests redirect to `/install.php`; the API returns 503.
- **Marker's edition differs from `APP_EDITION`:** 503. A standalone database opened as SaaS would expose the platform screens.
- **Once installed:** `/install.php` answers 404 for ever.

### 5.6 API meta for phones
Add `edition`, `version`, `registration_open` and `brand` to:
- the public `GET /api/v1/app/version`, which the app can call before sign-in;
- `auth/me`.

`Entitlements::for()` returns state `active`, plus the licence limits, on standalone.

---

## 6. Part B: exactly one business per installation

**Chains still work:** several stores, transfers and store prices are locations inside one company (Business settings › Supermarket › stores), so one-business-per-install doesn't limit a supermarket chain.

**Three layers, as in True-Doctor:**
1. **Model:** `Company::booted()` → `creating`. On standalone, throw `DomainException('This installation is licensed for one business.')` if any company exists (including demo or soft-deleted ones). Otherwise set `standalone_slot = 1`.
2. **Column:** `companies.standalone_slot`, `unsignedTinyInteger`, nullable, **unique**. NULL on SaaS.
3. **Triggers:** `App\Services\Install\SingleBusinessGuard::install()`, run by the installer after migrations:
   - `BEFORE INSERT` and `BEFORE UPDATE` force `standalone_slot = 1`;
   - `BEFORE DELETE` raises `SIGNAL SQLSTATE '45000'`.

   Backups restore the triggers (§12.4).

**Every path that creates a company, and what standalone does with it:**

| # | Path | Standalone |
|---|---|---|
| 1 | Web app `POST /register` (`RegisteredShopController`) | 404 (`edition:saas`) |
| 2 | Classic `POST auth/register` | 404 |
| 3 | `POST /api/v1/auth/register` (the phone app's "Create your shop") | 404, and the app hides the button (`registration_open:false`) |
| 4 | Legacy `POST /api/auth/register` | 404 |
| 5 | PingPin `POST /api/v1/pingpin/auth/register` | 404 |
| 6 | Private demo shop (`DemoShopService::create`, wizard "Try a demo shop") | Off: button hidden, service refuses |
| 7 | Public demo (`PublicDemo`, `demo:public`) | Off: refuses, not scheduled, **forced off by the edition, whatever `DEMO_PUBLIC` says** |
| 8 | Platform `CompanyController` create form | 404 (platform screens are SaaS only) |
| 9 | Seeders and factories (`CompleteDemoSeeder`) | Never run by the installer or updater |

The **only** company is created by the installer (§9), through `RegistrationService` in a new `standalone` mode: no trial; the Standalone plan (§7); the onboarding state left at the start, so the owner meets the existing `/welcome` wizard.

`standalone:health` fails unless exactly one company exists.

---

## 7. Part C: plans, limits and the licence

### 7.1 Keep every quota check: the "Standalone" plan

Budget Pro's limits are read in about 25 places (`Quotas::assertCanAdd`, `Entitlements`, `PlanLimit`, `LimitReached`…). True-Doctor added an edition branch to its one `PlanLimit` class. Budget Pro has a cleaner option that changes **no shop code at all**:
- The standalone reference seeder creates one plan: `slug=standalone`, `interval=lifetime`, `price 0`, features all on, limits from the licence (§7.2).
- The installer gives the only company a subscription on it, using `Company::activateSubscription(..., 'lifetime')`, which already sets the end 100 years ahead.
- Whenever the licence changes (install, upload, update), `licence:apply` rewrites that plan's `limits`:
  - `max_users`, `max_locations`, `max_devices` from the licence;
  - everything else `null` (unlimited);
  - `max_products` and `max_sales_per_month` **always** `null`.
- `Company::accessState()` is then `active` by construction, and the read-only grace never triggers.
- `Entitlements::DEFAULT_LIMITS` (`max_locations=1`, `storage_mb=500`) no longer apply, because the plan sets every key.
- `PlanSeeder` is not run on standalone, so it can't add a Business subscription.
- `Lifecycle` is not scheduled, so nothing moves the shop to Free.

### 7.2 The licence

**Format** (same as True-Doctor's):
```json
{"licence":{"format":1,"product":"budget-pro-standalone","id":"<uuid>",
  "customer":"Fresh Mart Ltd","domains":["shop.freshmart.co.ug"],
  "updates_until":"2027-10-31",
  "limits":{"max_users":10,"max_locations":3,"max_devices":10},
  "white_label":false},
 "key_id":"2026-a","signature":"<Ed25519, base64>"}
```

**Code**
- `App\Support\Licensing\{Canonical, Verifier, InstalledLicence}` (kernel).
- `App\Services\Licensing\Signer` (SaaS only).
- `licensing:keygen --id=2026-a` (SaaS only). It refuses to overwrite a key; old public keys are kept for ever.
- The secret lives in the live kernel `.env` (`RELEASE_SIGNING_SECRET`) plus one offline copy. **If both are lost, no installed copy can ever verify another update.**

**What it gates**

| Situation | Behaviour |
|---|---|
| Valid | Normal |
| `updates_until` passed | **The shop keeps working for ever.** Newer releases refuse to install; the page offers a renewal |
| Address doesn't match | A banner for the owner only. Never a lockout |
| Missing or tampered | A banner for the owner. A second business is impossible anyway (§6) |
| `max_users` / `max_locations` / `max_devices` reached | Exactly what Budget Pro does on SaaS today: refuse the next one, with "Your licence allows N…" wording |

Never limited: products, sales, customers, stock, reports, or any record.

**Banners:** in the web app's layout and the classic navbar, owner only:
- licence invalid;
- wrong address;
- updates ended;
- scheduler not running (§16);
- last backup over 2 days old.

Each one says "Selling and your records are not affected."

---

## 8. Part D: one address, two apps (the package layout)

### 8.1 The ZIP

```
budget-pro-standalone-1.0.0.zip
├── budget_pro/          kernel: app, bootstrap, config, database, resources, routes, vendor (committed), artisan …
├── budget_pro_web/      web app: app, bootstrap, compat, config, resources, routes, vendor (from the live deploy), artisan …
├── web_root_files/      everything that goes in the address's web folder
│   ├── index.php        the front controller for both apps (§8.2)
│   ├── .htaccess        https, deny dotfiles, route everything to index.php
│   ├── install.php      the pre-Laravel installer (§9)
│   ├── build/           the web app's built assets
│   ├── vendor/livewire  Livewire's script (published)
│   ├── images/ …        logos, icons
│   ├── sw.js            the offline till's service worker (scope /sell)
│   └── storage/         EMPTY real folder: product photos, logos, receipts' logos land here
├── PACKAGE.json         signed list of every file with sha256 and size, plus both commits
└── INSTALL.md
```

On the buyer's hosting, `budget_pro/` and `budget_pro_web/` stay in the home folder (never public). The contents of `web_root_files/` go into the web folder of their address, for example `public_html/shop.freshmart.co.ug/`.

### 8.2 One front controller for both apps

`web_root_files/index.php`:
1. Finds `budget_pro/` and `budget_pro_web/`, looking up to four folders above itself.
2. Sends a copy with no `.env` to `install.php`.
3. Then dispatches by path:

| Path starts with | Served by | Why |
|---|---|---|
| `/api/` | kernel | The phone app's `/api/v1`, and the payment webhooks |
| `/classic/` | kernel | The classic screens, under the prefix `ADMIN_ROUTE_PREFIX=classic`, which `config/admin.php` already reads. Classic is still needed for budgets & pledges, poultry on the web, and device tracking |
| `/r/`, `/stop/`, `/payment/` | kernel | Public receipts, opt-out links, payment returns |
| `/storage/` | the web server directly | Files in the real `storage/` folder |
| everything else | web app | The shop: dashboard, till, products, settings, `/install` hand-off, `/welcome` … |

The phone app, receipts and invites then use **one** address (`https://shop.freshmart.co.ug`), and the buyer needs one domain, one SSL certificate and one web folder.

**Notes**
- Both apps call `usePublicPath(<web folder>)`. It exists in Laravel 10 (the kernel) and 12 (the web app), as True-Doctor does with `APP_PUBLIC_PATH`.
- Both the kernel's `admin` disk (`public_path('storage')`) and the web app's `media_root` then resolve to the same real `web_root/storage`. **No symlinks:** some shared hosts forbid them, and today's deploy depends on `ln -sfn`.
- Session cookies stay distinct (`bp_shop_session` for the web app, `bp_classic_session` for the kernel).
- The classic "Try the new Budget Pro" link and the web app's "Classic screens" button point at `/` and `/classic/`.

### 8.3 Shared settings both apps must agree on
Written once by the installer into both `.env` files, and checked by `standalone:health`:
- `APP_EDITION`
- **the same `APP_KEY`** (see §21: today they differ on live, and encrypted EFRIS settings depend on one key)
- `DB_*`
- `APP_URL` (same address)
- `BUDGETPRO_PATH`, `BUDGETPRO_URL`
- `BUDGETPRO_MEDIA_ROOT` / `_URL`
- `SAAS_PUBLIC_URL`, `SAAS_NEW_UI_URL`
- `ADMIN_ROUTE_PREFIX=classic`
- `SAAS_REDIRECT_TO_NEW_UI=true` (classic pages open the new screens)
- `MAIL_*`, `MAIL_FROM_NAME` (the business name)
- `CACHE_STORE=file` / `CACHE_DRIVER=file`, `QUEUE_CONNECTION=database`
- `DEMO_PUBLIC=false`, `LEGACY_API_ENABLED=false`

`config/database.php` in **both** apps sets `'engine' => 'InnoDB'` (today it is `null` in both). This is True-Doctor's lesson 4, and it is harmless on the live MySQL, which is already InnoDB.

---

## 9. Part E: the installer

### 9.1 `install.php` (pre-Laravel)
It is built on True-Doctor's, which is proven on real shared hosting. It runs when there is no `.env`, no `APP_KEY` and no database.
- **What it may use:** only Composer's autoloader and Dotenv. It must **never** call `config()`, `app()`, `env()`, `storage_path()` or `base_path()`. A test checks the whole file for those calls (True-Doctor's lesson 1).
- **Guards:**
  - CSRF and an install lock (`flock`);
  - an encrypted draft bound to the installing browser;
  - 404 once installed;
  - 404 when an `.env` says the copy is SaaS;
  - HTTPS required except on localhost.
- **Server checks:**
  - PHP ≥ 8.2 (the web app's Laravel 12 sets the floor);
  - extensions `bcmath ctype curl fileinfo gd intl mbstring openssl pdo_mysql sodium tokenizer xml zip`;
  - `storage/` and `bootstrap/cache` of both apps writable, plus `web_root/storage`;
  - InnoDB available (`information_schema.ENGINES`).
- **Package check:** verify `PACKAGE.json` (signature, then every file's sha256, in both app folders and in the web folder) **before** writing anything.

### 9.2 The steps

| Step | Asks | Does |
|---|---|---|
| ① Licence & address | Upload `licence.json`; the exact address | Verifies the signature; the address must be listed in the licence; the release must be older than `updates_until` |
| ② Database | Host, port, name, user, password | Connects; the database must be **empty**; InnoDB must be available |
| ③ Business | Business name, **kind of business** (the existing 22 types and their template packs), country (sets currency, timezone, VAT and mobile-money networks from `config/onboarding.php`) | — |
| ④ Owner | Name, email, phone, password (≥ 10 characters) | — |
| ⑤ Email | SMTP host, port (465 default), user, password, from address. The guide says to use the host's own server name | Sends a test email at the end. If skipped, a banner warns that "forgot password" needs a channel (§13) |
| ⑥ Install | Nothing | Writes **both** `.env` files atomically, round-trip checked (`EnvironmentWriter`, the same `APP_KEY` in both). Runs the kernel's migrations in short browser steps with a progress bar (True-Doctor: about 5 per request). Installs the triggers. Runs `StandaloneReferenceSeeder`. Creates the company and owner (§6), and the Standalone plan and subscription (§7). Runs `licence:apply`. Writes `installed.json` in `budget_pro/storage/app/private/`. Caches config, routes and views |
| ⑦ Scheduler | Nothing | Shows the exact cron line: `* * * * * cd ~/budget_pro && php artisan schedule:run >/dev/null 2>&1`, every minute (§16) |
| ⑧ Done | — | Sign-in. The owner lands in the existing `/welcome` setup wizard: business, products from a template, money, team, first sale, phone app |

**`StandaloneReferenceSeeder`:**
- roles and permissions in the **existing order**: platform admin role id 1 stays reserved and unused, the owner is id 2, as `EnsureCompanyOwnerRole` assumes;
- the classic menu;
- product templates (`ProductTemplateSeeder`);
- poultry reference tables;
- the Standalone plan.

No other plans, no platform admin, no demo.

**Lesson to keep:** the owner is saved with `forceFill`. True-Doctor's owner met "verify your email" because `email_verified_at` was silently dropped by mass assignment.

**CLI door:** a `standalone:install --config=file.json` command runs the same engine (`StandaloneInstaller`), for technicians and for the release proof (§10).

---

## 10. Part F: releases, built from what is live

**Where:** on the live SaaS server, in the classic platform screens. That is where platform admins already sign in (`PlatformAdminOnly`), and Encore's grid and form make these screens quick to build:
- **Platform › Standalone releases**
- **Platform › Standalone products**
- **Platform › Standalone licences**

**Stage a version:** one form.
- Version, prefilled with the next patch.
- Notes, prefilled with the commit subjects since the last release, from both repositories.
- A readiness list: signing key set, proof database set, both repositories clean, and both deployed commits equal to `origin`.

Click **Build**.

**The build** runs as a queued job on its own `release-builds` connection.
- **Separate cron line:** on the SaaS host only, `*/2 * * * * php artisan queue:work release-builds --stop-when-empty --max-time=1800`. The main scheduler runs tasks in-process (decision E52, the host's 25-process limit), and a 2–5 minute build shouldn't hold up the till's scheduled jobs.

**Steps:**
1. `git archive` of the **deployed** commit of `budget-pro` (`main`). This includes its committed `vendor/`.
2. `git archive` of the **deployed** commit of `budget-pro-new` (`master`), plus a copy of the live `vendor/` that `deploy.sh` installed. Only copied if `composer.lock` matches and it is `--no-dev`; otherwise `composer install --no-dev` into the stage. Plus the committed `public/build`.
3. Assemble the layout of §8.1, with `budget-pro-new/deploy/standalone-index.php` → `web_root_files/index.php`. Write `RELEASE.json` (version, both commits, `mobile_min_version`, `built_at`) and `RELEASE_KEYS.json`.
4. **Allow-list and secret scan.** The build **fails** on `.env*`, `ssh.txt`, `*.sql*`, `*.pem`, `*.key`, `id_rsa`, `auth.json`, `*.log`, any `storage/` content, `LIVE_ACCOUNTS*` or `divine-*`-style client folders. No `tests/`, `docs/`, `deploy/`, `node_modules/` or scratch scripts.
5. A signed `PACKAGE.json` (file list with sha256 and size). Fixed modes: files 0644, writable folders 0777.
6. **Proof:** extract the ZIP and run `standalone:install` **as a separate process inside the extracted copy**, against one dedicated empty proof database (for example `schooics_bpproof`; cPanel can't create databases over SQL). Assert one company, one owner, the Standalone plan, product templates, roles and `installed.json`, then run `shop:check`. The proof refuses to use the application's own database.
7. Status becomes **Ready to publish**. **Publish** re-verifies the hash and signature. **Withdraw** removes a release from sale and the update feed.

**Before publishing:** compare the two commits recorded in `PACKAGE.json` with live (True-Doctor lesson 6). The page shows both side by side.

**Release size:** the kernel's `vendor/` plus the web app's `vendor/` will make a larger ZIP than True-Doctor's (roughly 50–80 MB). That is fine for the buyer page. The updater checks there is 3× that free on disk.

---

## 11. Part G: selling a copy

**Tables** (kernel migrations, unused on standalone):
- `standalone_products`: name, description, price (UGX and USD), renewal price, `updates_months`, limits JSON, white-label flag, on sale.
- `standalone_orders`: `kind=purchase|renewal`, reference `BPL-…`, price and limits snapshot, status `pending|paid|failed|mismatch`.
- `standalone_licences`: uuid, customer, email, phone, domains, `updates_until`, limits, status `active|revoked`, `source=online|offline`, reference, `domain_changed_at`.
- `standalone_downloads`: licence, release, ip, time.
- `standalone_releases`: version, both commits, status, notes, progress, archive path, sha256, `proved_at`, `published_at`.

**Public page:** `/own` on budget-dynamics. "Prefer to own it?" is a band on `/pricing`.
- It lists the products, with a short SaaS-vs-own comparison (the table in §1).
- A form: business name, contact name, email, phone, address it will run on.
- It reads "Coming soon" until a release is published and the signing key is set.

**Checkout:**
- Flutterwave hosted checkout (card) or the mobile-money prompt, reusing `FlutterwaveService`.
- Settlement happens in **the existing Flutterwave webhook**: a branch on the `BPL-` reference prefix, beside the subscription and `MOMO-` branches. It is idempotent with `lockForUpdate` (a webhook and the return page both arrive), and refuses an underpayment or the wrong currency.
- Tests fake Flutterwave; **no real calls** in tests (standing rule).

**Offline sales** (bank transfer, contract, cash): **Platform › Standalone licences › Issue**, with a reference. This sends the same email.

**Buyer page:** `/my-licence` on budget-dynamics. It is passwordless.
- The magic link is valid 24 hours and is **created when the email is sent**.
- It offers: the latest entitled release, `licence.json`, the guide (also `INSTALL.md`), earlier releases, renewal, and an address change once every 30 days. Staff can change the address any time.
- A request for a link gives the same answer whether or not the email has a licence.

**Prerequisite:** the live SaaS has **no outgoing email configured yet** (`MAIL_*`; it is on the master-plan list). Configure SMTP before selling online. Until then, staff can issue offline and copy the buyer-page link from the licence screen.

**Platform › Standalone licences:** issue, send link, renew (extend by the product's months), change address, revoke and reinstate.
- **Revoke** stops downloads and the update feed. The buyer's copy keeps working.

---

## 12. Part H: updates and backups on the buyer's server

### 12.1 Settings › Licence & updates
In the web app (owner only, standalone only). It is never held behind the setup wizard. It shows:
- version and install date;
- licence facts and whether the address matches;
- **scheduler heartbeat**;
- queue depth, free disk and `upload_max_filesize`;
- backups, with Download;
- **a QR code of this server's address** for the phone app (§14).

It offers these actions:
- **Check now:** asks `GET <update server>/standalone/updates?licence=<id>&version=<v>`, sending only the licence id and version.
- **Back up and update.**
- **Upload and update:** for a server without internet; a plain form post (up to 500 MB) into `storage/app/private/updates/`.
- **Back up now.**

### 12.2 The updater
These rules are taken unchanged from True-Doctor, and they matter more here because there are two apps:
- **It never runs in a web request.** The page writes `system/request.json`. The scheduler's `standalone:system-requests` claims it with an atomic `rename()` and runs it as a CLI process.
- **Verify** with the keys of the **installed** release, then check that the version is newer and the release was built before `updates_until`.
- **Preflight:** free disk ≥ 3× the ZIP, plus writable paths.
- **Maintenance mode** in both apps (`down --render=errors::503 --refresh=30`). A self-contained, branded "Being updated" page.
- **Back up:**
  - the database (§12.4);
  - **both** `.env` files, `licence.json` and `installed.json`;
  - a copy of every code file it will replace in `budget_pro/`, `budget_pro_web/` and the web folder.
- **Swap by the signed list.** Each new file is written to a temp file and renamed into place, and removed files are deleted. It never touches `.env`, `storage/` or `web_root/storage/`. Compiled config, routes and views are cleared, and `opcache_reset()` runs.
- **In fresh PHP processes:** the kernel's `migrate --force`, `db:seed StandaloneReferenceSeeder` (idempotent), `licence:apply`, `config:cache` in both apps, then `standalone:health`. Health is the kernel checks plus the web app's existing **`shop:check`**.
- **Any failure after the swap:** restore the code and the database, bring both apps up on the old release, and show what failed in plain words.
- **Daily** `standalone:update --check` at 06:10.

### 12.3 What updates can carry
- **Patch** (1.0.1): fixes only.
- **Minor** (1.1.0): features plus additive migrations, the rule both repositories already follow.
- `RELEASE.json.mobile_min_version` tells the owner when phones need the newer app before updating.

### 12.4 Backups
- **What:** `standalone:backup` nightly at 02:15, keeping 7, with **Download** on the page and a warning when the newest is over 2 days old (they live on the same server). Each ZIP holds:
  - `database.sql.gz`;
  - both `.env` files, `licence.json` and `installed.json`;
  - all files under `web_root/storage` (product photos, logos, receipt photos, chat pictures);
  - `RESTORE.txt`.
- **Why a PHP dump:** it is a PHP dump (structure, rows in batches of 500, **and the triggers**; statements end in `;`), so it restores with `mysql` or phpMyAdmin too.
- **Not today's `BackupService`:** that needs `mysqldump` and `proc_open`, which buyers' cPanel hosts may not allow. It stays for the SaaS.
- **The guide says, in bold:** keep `APP_KEY`. EFRIS credentials and other encrypted settings can't be read without it.

---

## 13. Part I: messaging, payments and fiscal on the buyer's own accounts

On SaaS these are our accounts in `.env`. On a buyer's server they are the buyer's, entered on a new owner-only screen: **Settings › Connections** (standalone only).

| Connection | Fields | Used for |
|---|---|---|
| Email (SMTP) | host, port, encryption, user, password, from address and name | OTP for password reset, invites, receipts by email, the 8 pm summary. Also asked in the installer |
| SMS (Africa's Talking) | username, API key, sender id | OTP, receipts, debt reminders |
| WhatsApp (Meta Cloud API) | token, phone number id, template names (`sale_receipt`, OTP) | WhatsApp receipts, OTP. Templates must be approved on **their** WhatsApp Business account; the guide explains |
| Mobile money (Flutterwave) | their public key, secret key, encryption key, webhook hash | Customer mobile-money requests at the till. **No subaccount and no platform fee:** collections go straight to their Flutterwave balance (`FLW_MOMO_PLATFORM_FEE_PERCENT=0`, `MomoCollections` uses their keys directly). The screen shows the webhook address to paste into their Flutterwave dashboard: `https://<their address>/api/v1/webhooks/flutterwave` |
| Fiscal (EFRIS) | already per shop, under Business settings › Fiscal | Unchanged |

- **Storage:** an encrypted `install_settings` table, owned by the kernel, for example `Crypt` with the shared `APP_KEY`.
- **Reading it:** a small config overlay at boot in **both** apps (`App\Support\InstallSettings::overlay()`) fills `messaging.*`, `mail.*` and `flutterwave.*` from it.
- **Why not `.env`:** rewriting `.env` from a web request is fragile on shared hosting (a half-written file takes the whole site down). Settings in the database survive updates and are inside every backup.
- **Each row** has a **Send a test** button (an email, an SMS to the owner, a WhatsApp template test), like the installer's test email.
- **Until a channel works,** the owner sees one banner: "Password reset and receipts need email, SMS or WhatsApp. Set one up in Settings › Connections."

---

## 14. Part J: the phone app

The same app from the Play Store serves both editions. No per-client builds, unless we later sell a white-label app as a paid service.

**Changes in `budget-pro-mobo`:**
1. **Server address.**
   - `Utils.API_URL`, `BASE_URL` and `API_V1` become getters over a stored server, default `https://budget-pro.schooldynamics.ug/`. Today they are final constants (`Utils.dart:237-241`), used through `ApiService` and about 10 other places listed in the inventory.
   - Sign-in gets a quiet **"Using your own server?"** link that opens a "Server address" field, with **Scan QR** to read the code on Licence & updates. Settings › About shows the current server.
   - Changing the server is allowed only when nothing is waiting to sync. It wipes the local shop data (the same rule as logout).
2. **Connectivity check:** `Utils.dart:203` opens a socket to the server's host on 443. Take the port from the address's scheme, so `http://` LAN servers (a shop's own office PC) don't look offline.
3. **Edition-aware screens.**
   - When the server says `edition: standalone` (from `/app/version` before sign-in and from `auth/me` after), hide "Create your shop", "Try the demo shop", Plan & billing, and renew banners.
   - Read the licence limits from `entitlements`.
   - The 402 handling stays for SaaS.
4. **Brand:** take the business's colour and logo from `auth/me` (`brand`) for the shell header and receipts. Optional, after phase 8.

**Server side:** `GET /api/v1/app/version` is already public. It gains `edition`, `registration_open`, `name` and `brand`.

---

## 15. Part K: the buyer's brand

Budget Pro already has most of the pieces per company: logo, name, slogan, colour column, address, phone, receipt header and footer. Receipts and invoices already carry **the shop's** details, not ours.

**To add** (as True-Doctor did, opt-in):
- **Brand colour,** checked for ≥ 4.5:1 contrast with white, normalised to `#RRGGBB` so it can never break the `<style>` tag it is printed into. Plus a **wide logo**. Both are set in Business settings › Business profile.
- **Where they apply:**
  - the web app's CSS variables (`--br`, `--br-d`, `--br-soft`…);
  - the sign-in page, **standalone only**; the SaaS sign-in is shared and stays Budget Pro's;
  - emails (header and footer, subject in their name);
  - PDFs (logo as a data URI, colour as the accent);
  - the browser tab title;
  - error and maintenance pages;
  - the classic header.
- **Hard-coded "Budget Pro" text** in about 25 views and messages: the inventory lists them, for example `layouts/admin.blade.php`, `auth/*`, `OtpService`, `TeamService` invites and `Notifier`. On standalone it becomes the business name or `config('app.name')`, with **"Powered by Budget Pro"** kept small in footers. That line goes only with the licence's `white_label` flag (a higher-priced product).

---

## 16. The scheduler, per edition

Cron must run **every minute** on both editions. The installer, the guide and the health page all say so: a `*/18` cron silently skipped Budget Pro's own jobs until 27 Sep, and `*/19` did the same to True-Doctor.

| Task | SaaS | Standalone |
|---|---|---|
| `fiscal:submit-due`, `prices:apply-due`, `customers:adopt-named`, `shop:product-stats`, `queue:work` | ✓ | ✓ |
| `saas:hourly` | ✓ all | Only `ScheduledNotifications` + `DebtReminders` + heartbeat. **Not** `Lifecycle` |
| `billing:reconcile`, `onboarding:purge-demos`, `demo:public`, `ops drill`, `tracking:backfill-location-names` (PingPin) | ✓ | ✗ |
| `ops backup` (mysqldump), `ops purge` | ✓ | ✗ (replaced by `standalone:backup`; no automatic purge) |
| `standalone:heartbeat` (cache key, every minute), `standalone:system-requests` (every minute), `standalone:backup` (02:15), `standalone:update --check` (06:10) | ✗ | ✓ |
| `queue:work release-builds` (separate cron line) | ✓ | ✗ |

The heartbeat feeds the owner's banner (§7.2) and the Licence & updates page. It reuses the existing `HealthService` idea (`heartbeat:saas_hourly`) at minute granularity.

---

## 17. Data model

All migrations live in the kernel, as today. Both editions run every migration, so the schema is identical everywhere. That is what keeps a future "move my shop to my own server" possible.

| Migration (suggested) | Adds |
|---|---|
| `…_companies_standalone_slot` | `companies.standalone_slot` (nullable, unique) |
| `…_create_standalone_releases` | `standalone_releases` |
| `…_create_standalone_sales` | `standalone_products`, `standalone_orders`, `standalone_licences`, `standalone_downloads` |
| `…_create_install_settings` | `install_settings` (key, encrypted value, updated_by, timestamps) |

There is no table for updates. State is in files (`system/request.json`, `state.json`) so the page can show progress while the site is in maintenance mode, as True-Doctor does.

**New code (suggested names):**
```
budget-pro/app/Support/Edition.php
budget-pro/app/Support/Licensing/{Canonical,Verifier,InstalledLicence}.php
budget-pro/app/Support/InstallSettings.php
budget-pro/app/Services/Licensing/Signer.php
budget-pro/app/Services/Install/{StandaloneInstaller,SingleBusinessGuard,EnvironmentWriter,InstallDraft,
                                 StandaloneUpdater,SystemState,UpdateChannel,DatabaseBackup,InstallationBackups,ServerHealth}.php
budget-pro/app/Services/Releases/{StageStandaloneRelease,StandaloneArchive,CleanInstallProof}.php
budget-pro/app/Services/StandaloneSalesService.php
budget-pro/app/Http/Middleware/{RequireEdition,EnsureInstalled}.php
budget-pro/app/Console/Commands/{LicensingKeygen,LicenceApply,StandaloneInstall,StandaloneUpdate,StandaloneBackup,
                                 StandaloneSystemRequests,StandaloneHealth}.php
budget-pro/app/Admin/Controllers/Platform/{StandaloneReleaseController,StandaloneProductController,StandaloneLicenceController}.php
budget-pro/database/seeders/StandaloneReferenceSeeder.php
budget-pro/resources/standalone/INSTALL.md
budget-pro-new/deploy/{standalone-index.php,standalone-htaccess,install.php}
budget-pro-new/app/Livewire/Shop/Settings/{System,Connections}.php
budget-pro-new/app/Http/Controllers/{StandalonePurchaseController,StandaloneUpdateFeedController,EditionHomeController}.php
budget-pro-new/resources/views/marketing/{own,my-licence}.blade.php
```

---

## 18. Testing

Follow the owner's standing rule: test lean, and run each long suite once per phase.

- **`RunsAsStandalone` trait** in both apps' tests: `config(['edition.name' => 'standalone'])` plus a temporary `installed.json`.
- **Smoke walk as standalone** (web app): every shop route and every role's menu renders; no menu entry points at an `edition:saas` route; SaaS-only routes answer 404. The same for the kernel's API (`auth/me`, sync pull and push, sales) and the classic `/classic` prefix.
- **Edition boundary:** every row of §23 as one assertion. A second company is refused by every path in §6, including a raw `DB::insert`. The master password and demos are off. The Flutterwave webhook still settles `MOMO-` collections on standalone.
- **Licence:** valid, altered byte, wrong key id, expired updates (still sells), wrong address (banner only), limits enforced, and "a shop with no licence file can still sell, take payments and open reports".
- **Installer:**
  - `install.php` contains none of `config(`, `app(`, `env(`, `storage_path(`, `base_path(`, `/config/`;
  - the front controller with no `.env` redirects;
  - migrations advance in batches on a temporary database;
  - both `.env` files share one `APP_KEY` and round-trip;
  - the owner is verified.
- **Archive:** a planted `.env` fails the build; modes are 0644/0777; `PACKAGE.json` verifies; a tampered file fails.
- **Updater:** a fixture "1.0.0" updates to "1.0.1"; a "1.0.2" with a failing migration rolls back code and database with data intact; a package signed by an unknown key is refused.
- **Selling:** webhook plus return page issue exactly one licence; underpayment issues nothing; a renewal extends; the magic link works; offline issue sends an email. **All with a faked Flutterwave.**
- **Phone app:** the server-address change is refused with pending sync, and wipes and re-logs in otherwise; the standalone meta hides sign-up, demo and billing; the connectivity check uses the port from the address.
- **Clean-room drill** before the first sale (phase 9): a fresh cPanel account, the real ZIP from live, the web installer over HTTPS from a nested web folder, sign-in, the wizard, a sale, a phone pointed at it, an update 1.0.0 → 1.0.1, and a backup restored with plain `mysql`.

---

## 19. Delivery plan

Each phase ships on its own and leaves the SaaS deployable with both suites green.

| Phase | Scope | Done when |
|---|---|---|
| **0. Prerequisites** | Fix the `APP_KEY` mismatch on live (§21). Configure SMTP on live. Record a baseline of both suites | One key in both apps; a test email from live |
| **1. Edition switch** | `config/edition.php`, `Edition`, `RequireEdition` in both apps, every row of §23, the master password and demos off, menus, `EnsureInstalled`, API meta | As standalone: the smoke walk passes; `/`, `/register`, `/pricing`, `/plan` and the platform screens are 404 or redirect |
| **2. One business + Standalone plan** | Slot column, model guard, triggers, `RegistrationService` standalone mode, `StandaloneReferenceSeeder`, `licence:apply` | Every creation path refused; a standalone company is `active` with unlimited products and sales |
| **3. Licensing** | `Canonical`, `Verifier`, `InstalledLicence`, `Signer`, keygen, banners | A signed licence verifies, one changed byte fails, and expiry changes only updates |
| **4. Package + installer** | The §8 layout, `standalone-index.php` (dispatch, nested-folder search), `install.php`, `StandaloneInstaller`, the CLI door, InnoDB in both apps, `usePublicPath` | From a hand-made ZIP, an install on MAMP from an empty database reaches `/welcome`; the phone API answers on the same address |
| **5. Releases** | Platform screens (releases), builder, allow-list, signing, proof database, publish and withdraw | 1.0.0 staged from the browser on live, proved and published |
| **6. Updates + backups** | Licence & updates page, update feed, `SystemState`, updater with rollback, PHP backups, upload path | 1.0.0 → 1.0.1 succeeds; a broken 1.0.2 rolls back with data intact; a backup restores with `mysql` |
| **7. Selling** | Products and licences screens, `/own`, Flutterwave checkout and webhook branch, buyer page, magic links, renewals, address change, offline issue | A sandbox purchase ends in a working download with nobody at our end acting |
| **8. Connections + phone app + brand** | Settings › Connections (SMTP, SMS, WhatsApp, Flutterwave) with tests; the phone app's server address, QR and edition-aware screens; brand colour and wide logo; "Budget Pro" literals | A phone signs in to a standalone server by scanning its QR code; receipts, emails and PDFs carry the business's brand |
| **9. First client drill + docs** | The clean-room drill of §18 on a real cPanel account; `docs/editions.md` (the Budget Pro equivalent of True-Doctor's); the buyer's guide with screenshots | A non-technical person installs from the ZIP using only the guide |

**Later (not in v1):** **"Move my shop to my own server"**, export from SaaS and import into a standalone install. The schema is identical, but today's export lacks an import, media, numbering sequences, some child tables and credentials (inventory §11). It would be a good selling point for shops that outgrow the SaaS.

---

## 20. Risks

| Risk | Handling |
|---|---|
| **The two apps drift** (a release with a kernel that doesn't match its web app) | One release always contains both, built from the two **deployed** commits, which `shop:check` already proves agree on live. Both commits are recorded in `PACKAGE.json`, and the proof install runs `shop:check` |
| **The signing secret leaks** | Only in the live `.env` plus one offline copy; the allow-list can't ship `.env`; `key_id` allows rotation |
| **Source code is copied and resold** | PHP can't be meaningfully protected (encoders break on shared hosts and PHP upgrades). Rely on the licence agreement, the licensee name in the footer, update entitlement (pirated copies never update) and support only for licensed installs |
| **Licence checks block selling** | Impossible by design (S3). A test opens the till, a sale and reports with no licence file at all |
| **Buyer's host lacks something** (no `proc_open`, no `mysqldump`, no symlinks, MyISAM, wrong mail certificate) | The installer checks rather than assumes. PHP backups, real folders instead of symlinks, InnoDB forced, the mail guidance in the guide |
| **A buyer edits core files** | `PACKAGE.json` lets the updater list hand-edited files and ask before overwriting. Customisation belongs in settings |
| **Phones of a standalone shop point at our SaaS by mistake** | The sign-in screen shows which server it uses; standalone installs show their QR code; a phone can't switch server with unsynced work |
| **Losing `APP_KEY` breaks encrypted settings** (EFRIS, Connections) | Every backup includes both `.env` files; the guide says it in bold; the installer gives both apps the same key |
| **The buyer has no SMS, WhatsApp or email** | The installer asks for SMTP and sends a test; a banner persists until one channel works; the owner can still reset staff passwords from Team |
| **Standalone code paths rot because we only run SaaS** | The smoke walk and boundary tests run as standalone in every suite; the edition touches only the edges |

---

## 21. Found while preparing this report

**The two live apps use different `APP_KEY`s.** The sha256 of each line differs: kernel `9ebcf05c93d7…` vs web app `69dae9d586b4…`. `FiscalSetting::config` is encrypted with `Crypt`, so:
- EFRIS credentials saved from the **new web interface** (Business settings › Fiscal) are encrypted with the web app's key;
- they are read by `fiscal:submit-due`, which runs in the **kernel's** scheduler with the kernel's key;
- the job can't decrypt them, so fiscal receipts from such a shop would never be sent.

The same applies to anything else either app encrypts for the other.

**Fix in phase 0** (it needs care, so it was not changed while writing this report):
- **Option A:** give the web app the kernel's key. Users will be signed out once. Check first whether anything is stored encrypted with the web app's key: fiscal settings; session and remember-me cookies, which only cause a sign-out.
- **Option B:** have `FiscalSetting` encrypt with a dedicated key that both apps share.

No shop is fiscalising on live yet, so this is safe to fix now.

---

## 22. Questions for the owner

Each has a recommended default, so the work need not wait.

1. **Perpetual use with time-limited updates?**
   *Recommended:* yes, with 12 months of updates included, renewable.
2. **Products and prices.** These are entered in Platform › Standalone products, no code needed. As a starting point:
   *Shop* (1 store, 5 users, 5 phones): one price.
   *Supermarket / chain* (unlimited): about 2.5× that.
   *Updates renewal:* about 20–25% a year.
   *Installation service:* an add-on, which we install on their hosting.
3. **Limits.** *Recommended:* only team members, stores and phones. Never products or sales.
4. **White-label** (removing "Powered by Budget Pro", our name in emails): *recommended* as a higher-priced product flag.
5. **Classic screens** in the package. *Recommended:* yes, under `/classic`, because budgets & pledges, poultry on the web and device tracking live there.
6. **Who installs for buyers who pay for it, and how fast?** *Recommended:* the platform licences screen lists paid-but-not-installed orders.
7. **Refund window and terms,** shown at checkout and in the licence email.
8. **Should a SaaS shop be able to move to its own server later** (the export/import in §19)? *Recommended:* yes, as a follow-up project.

---

## 23. Appendix: SaaS surfaces and what each edition does with them

| Surface | Where | SaaS | Standalone |
|---|---|---|---|
| Marketing site | `budget-pro-new` `MarketingController`, `resources/views/marketing/*`, `layouts/marketing` | ✓ | 404; `/` → sign-in |
| Operator facts (`config/site.php`) | `SITE_*` | ✓ | Blank, or the buyer's support line |
| Sign-up (web, classic, API, legacy, PingPin) | `RegisteredShopController`, `AuthController::postRegister`, `Api/V1/AuthController@register`, `ApiController`, PingPin | ✓ | 404 (installer creates the only business) |
| Public demo | `PublicDemo`, `DemoController`, `demo:public` | ✓ | Off (forced) |
| Private demo shops | `DemoShopService`, `StartsDemo`, `EnterDemo`, `DemoBanner`, `FillDemoShop` | ✓ | Off |
| Trial → Free, renewals, dunning | `Billing/Lifecycle` | ✓ | Not scheduled |
| Plans, subscriptions, checkout, invoices | `BillingService`, `Plan`, `Checkout`, `PaymentReturn`, `PlanInvoiceController`, plan chip, API `subscription*`, classic `BillingController` | ✓ | 404 / hidden; Standalone plan instead |
| Quotas / entitlements | `Quotas`, `Entitlements`, `PlanLimit`, `LimitReached` | Plan limits | Licence limits via the Standalone plan (no code change) |
| Read-only grace | `Company::accessState`, `EnsureActiveSubscription`, `EnsureWebAccess`, `EnsureShopAccess`, `ShopContext::canWrite` | ✓ | Always `active` |
| Flutterwave webhook | `api/v1/webhooks/flutterwave` | Subscriptions + MoMo + licence sales | MoMo only, with the buyer's keys |
| MoMo subaccounts and platform fee | `MomoCollections`, `FlutterwaveService` | ✓ | Buyer's own account, fee 0 |
| SMS / WhatsApp / email | `config/messaging.php`, `Messenger`, `OtpService` | Our accounts | Settings › Connections |
| Platform admin and screens | `PlatformAdminOnly`, `CompanyController`, `PlanController`, `SubscriptionController`, `ProductTemplateController`, `SystemHealthController`, `AdminBilling` actions | ✓ (+ standalone releases, products, licences) | None |
| Master password | `MasterPassword` | As configured | **Always off** |
| Backups | `BackupService` (mysqldump), `ops backup/drill` | ✓ | `standalone:backup` (PHP dump with files) |
| Delete my shop / purge | `TenantDataService::requestDeletion`, `ops purge` | ✓ | "Close and wipe this installation", by hand only |
| Export | `TenantDataService::export` | ✓ | ✓ |
| Funnel analytics | `OnboardingEvents` | ✓ | Optional |
| PingPin | `app/PingPin/**`, its plans and billing | ✓ | Off |
| Legacy API | `LegacyApi`, `ApiController` | Until retired | Off |
| Phone app server | `Utils.dart:237-241` | Default | Server address + QR |
| Phone app billing and sign-up | `billing_screen.dart`, "Create your shop", "Try demo", renew banners | ✓ | Hidden (from `edition`) |
| Branding | Hard-coded "Budget Pro" in about 25 views and messages | ✓ | Business name, "Powered by Budget Pro" small (removable with `white_label`) |
| Settings › Licence & updates | new | — | ✓ |
| Settings › Connections | new | — | ✓ |
| Installer, updater, heartbeat | new | — | ✓ |
