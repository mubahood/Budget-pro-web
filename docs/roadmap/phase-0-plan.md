# Phase 0 plan: stabilise and lock

Written 17 October 2026, after reading the roadmap, the ADRs, the runbooks, the portal conventions, the True-Doctor patterns, and the code itself. The code inventories found more than the roadmap expected, so this plan has two extra P0 items, flagged **(found)**.

Nothing is pushed, deployed or released in this phase. The one exception is the app's private remote, which 05 §1 asks for, and it is created only after a clean secrets scan.

## What the code showed that the roadmap did not

| Finding | Where | Effect |
|---|---|---|
| **The kernel repo `mubahood/Budget-pro-web` is public, and a real `.env` is in its history** (2024-06 to 2025-01) | git history | Some leaked values may still be in use; checked by hash only, no value printed. The findings and the rotation steps are in the private portal repo (`budget-pro-new/docs/runbooks/key-rotation.md`), not here. Code no longer lets the app key alone forge reset or registration tokens (P0-2) |
| The classic operation log stores plaintext passwords | Encore `LogOperation` writes `json_encode($request->input())` for `PUT auth/setting` and the Employees form | Passwords sit in `admin_operation_log` |
| Both CI workflows never ran | kernel: `hashFiles` in a job-level `if` makes the file invalid (47 runs, 0 jobs); `--parallel` needs paratest, which is not installed. Portal: every run is `startup_failure` | "Green" was never measured |
| The master password opens platform-admin accounts on the classic sign-in | `Admin/Controllers/AuthController.php:36-51` | Escalation from one shared secret |
| The kernel queue defaults to `sync` | `config/queue.php:16`, `.env.example:21` | Jobs the portal queues are never processed unless `.env` says `database` |
| The portal loads 9 kernel config files into its own process | `config/budgetpro.php:12`, `BudgetProServiceProvider` | Kernel keys must also be in the portal's `.env`; its `.env.example` lacks `SAAS_DATA_KEY` and the rest |
| Nothing detects Flutterwave test keys | `config/flutterwave.php` | A production copy can run on test keys without anyone knowing |

## Items

Each item names what it extends. Tests run on separate copies of the test schema, so work can go in parallel.

### P0-1 Config that cannot silently switch things off (02 §1, §11)

- **`.env.example`, rewritten in both repos.** Every key the code reads, grouped and commented: App/edition, DB, mail, queue, SaaS, messaging, payments, fiscal, site facts, support, master password, owner alerts, mobile, backups, demo, releases.
  - The portal's file also lists the bridged kernel keys, `SAAS_DATA_KEY` first.
  - Fix `FLW_REDIRECT_URL`, which is missing a `/`.
  - Remove keys nothing reads: `APP_TIMEZONE`, `MAIL_ENCRYPTION`.
- **The queue is `database`** in both defaults and both examples (ADR 0008).
- **`App\Support\ConfigDoctor`** (new, in the kernel, so both apps run the same checks):
  - Production on SaaS **fails** on:
    - mail off (`MAIL_MAILER` set to log or array; `SAAS_MAIL_ENABLED` off; `MESSAGING_MAIL_DRIVER=log`);
    - queue `sync`;
    - SMS or WhatsApp driver `log`;
    - Flutterwave keys missing, or in test mode (`FLWSECK_TEST`), or no `FLW_SECRET_HASH`;
    - `SAAS_DATA_KEY` empty;
    - the support WhatsApp empty;
    - `APP_DEBUG` on;
    - a plaintext `MASTER_PASSWORD` set.
  - It **warns** on site facts that are not set.
  - On standalone, messaging and payment become warnings, because a buyer may not have set them up yet and their updates must never be blocked.
  - Locally, everything is a warning.
- **Where it runs:**
  - the kernel `config:doctor` command (`--json`);
  - the portal `shop:check` (which it extends);
  - `deploy/deploy.sh` runs both, so a production deploy fails;
  - classic System health shows the list (`HealthService` and its view).
- **A test reads every `env()` in `config/` and fails when a key is missing from `.env.example`.** Stock framework keys are allow-listed. The kernel CI already copies `.env.example`, so a missing key fails CI.

### P0-2 Sign-in (02 §2)

- **Classic sign-in throttle,** per login+IP and per IP, with the API's numbers. It shows a lockout message with the support WhatsApp link, and refuses inactive users the way the portal does.
- **API:** a named `login` limiter per account and IP, on login, OTP and reset, on top of the existing per-IP limit.
- **The master password:**
  - **Storage:** `MASTER_PASSWORD_HASH` (bcrypt), set with `php artisan master-password:set`, which refuses fewer than 20 characters. A plaintext `MASTER_PASSWORD` is ignored and `config:doctor` flags it.
  - **On the sign-in forms** (classic and portal) it works only from `MASTER_PASSWORD_ALLOWED_IPS` (IP or CIDR list). It never opens a platform-admin account or an inactive one.
  - **"Sign in as" for super admins:** a signed-in platform admin on the classic Companies screen gives a reason and the master password, from an allowed IP. They land in the portal through the existing single-use `WebLink`, so "who" is known.
  - **Audit and alert:** every use, allowed or refused, is written to a new `master_password_uses` table (who, which account, why, IP, user agent, where). The owner gets an email and a WhatsApp alert through `Messenger` (`OWNER_ALERT_EMAIL`, `OWNER_ALERT_WHATSAPP`, falling back to the support contact).
  - **The session it opens** is marked and ends after `MASTER_PASSWORD_SESSION_MINUTES` (default 60), with a banner in the portal and the classic header.
  - Still off on standalone.
- **One password rule:** `App\Support\Rules\PasswordPolicy` (pattern from True-Doctor), at least 8 characters with letters and numbers, plus the breach check (k-anonymity, fails open).
  - It applies at every place a password is set: sign-up (web, API, classic, legacy, Ping Pin), API change and reset, portal reset and profile, team members, invites (three places), classic Employees and `auth/setting`.
  - The portal's `Password::defaults()` uses it.
  - The app's validators match it, and every screen shows the same hint.
  - Tests fake the breach API.
- **(found) Tokens a leaked `APP_KEY` cannot forge:** the API reset token and the registration token become random, single-use and stored hashed server-side.
- **(found) Revocation gaps:** setting a password in the portal profile, the classic `auth/setting` and the classic Employees form revokes that person's API tokens, as the API and Team paths already do.
- **(found) No passwords in the operation log:** password, PIN and token fields are redacted before Encore writes `admin_operation_log`, and a migration scrubs the rows already there.

### P0-3 Module gating in the API (02 §3)

- **`api.module:{module}` middleware** (`EnsureApiModule`) after `api.tenant`.
  - It refuses with `403 {code:0, message:"Shop is not on your account.", data:null, errors:{code:"module_off", module, add:"PUT /api/v1/company/modules"}}`. This keeps the kernel envelope; the words match the portal's `EnsureModule`.
  - `pingpin` uses `AdminMenu::modulesOn`.
- **Every authenticated v1 route is marked:** shop, finance, budget, poultry, pingpin, or `api.module:core`. Core covers auth, meta, account, team, billing, onboarding, devices, sync, files, chat and notifications.
  - The sync endpoints stay core on purpose: one engine carries every module (ADR 0001), and a refused op would roll back a whole batch. Hidden modules keep their data.
- **A test walks every route** under `api/v1` behind `auth:sanctum` and fails when one has no `api.module:*` mark.

### P0-4 Tenancy that cannot be forgotten (02 §4)

- **`CompanyScope` also applies inside API requests.**
  - `EnsureApiTenant` sets the resolved company for the length of the request and clears it afterwards. `CompanyScope::currentCompanyId()` reads it after the `web`/`admin` guards.
  - The hand-written filters stay, as a second wall.
  - Ping Pin's multi-organisation routes do not use `api.tenant`, so they are untouched.
- **Scope the unscoped tenant models:** HeldCart, PriceChange, LabelQueueItem, FiscalSetting, FiscalSubmission, PurchaseOrderItem, PurchaseReturnItem, ChatParticipant, SyncBatch.
  - Scheduler sweeps (PriceBook `applyDue`, Fiscal `processDue`) run without a user, so the scope does not narrow them.
  - The global-on-purpose models (CompanyMember, User, billing, Ping Pin billing) are listed with their reason in one place.
- **Tests:**
  - every model with a `company_id` column is scoped or on the list;
  - company A cannot read, list, update or delete company B's rows through each `apiCrud` resource and the custom `{id}` routes. This builds on `TenantIsolationTest`, which has 4 tests today.

### P0-5 Tests we trust and CI (02 §8)

- Remove the tracked `.phpunit.result.cache` from the kernel. Run all three suites fresh, and write the true counts in each README.
- **Kernel CI:**
  - remove the invalid mobile job, because the app gets its own CI;
  - run the tests without `--parallel` (paratest is not installed);
  - CI keeps using `.env.example`.
- **Portal CI:** add Node, `npm ci`, `npm run build`, `npm run test:js` (Vitest), and the support-number scan. Find the reason behind `startup_failure`.
- **App CI** (new `.github/workflows/ci.yml`): `flutter analyze`, `flutter test`, and debug builds of the four flavors. Release signing comes later from CI secrets, never the repo.

### P0-6 App remote (05 §1)

- Run gitleaks over the app's history. If it is clean, create the private `mubahood/budget-pro-mobo` (gh is signed in as mubahood) and push `main`.
- The keystore, `key.properties` and `credentials.txt` stay ignored, as they are today.

### P0-7 Secrets and housekeeping (02 §9)

- Move `LIVE_ACCOUNTS.local.md` out of htdocs to `~/BudgetPro-private/` (mode 600), and add `LIVE_ACCOUNTS*` to `.gitignore` in all three repos.
- Install gitleaks and scan every repo's full history. Write the findings, by key name only, with the rotation steps in the private portal repo (`docs/runbooks/key-rotation.md`).
- **The kernel's root `.htaccess`** gets explicit denies for dotfiles, docs, JSON and the app tree, like the portal's. Today it only protects by accident.
- **Dev packages in the tracked `vendor/`** (phpunit, mockery, faker, collision, pint): ADR 0008 says there should be none. This is recorded with the risk. They are not reachable from the web on production (checked).
  - Removing them changes the tracked autoload files, so it waits for the Laravel upgrade (02 §11).

### P0-8 The support number everywhere (06 §1, §4; 05 §5)

- **`App\Support\SupportContact`** in the kernel (port of True-Doctor's).
  - Reads `config/support.php`, which holds the defaults: `SUPPORT_WHATSAPP=256783204665`, phone, email, hours. There is no platform settings table on SaaS, so env is the setting.
  - On standalone, the copy's own contact comes first: the licence partner, then the shop's own details, then ours.
  - Prefilled messages live in the kernel's `lang/en/support.php`, read through a `bp::` namespace in both apps. They never carry a customer's name, an amount or a phone number.
- **The portal:**
  - `config/site.php` reads the same `SUPPORT_*` values, and `MarketingController::facts()` falls back to `SupportContact`, so there is one number.
  - The admin menu's "Chat with support" stops putting the user's name in the message.
- **`/go/whatsapp?c=<context>`** records the click (new kernel table `support_clicks`: context, app, page, no personal data) and redirects. The plain `wa.me` link stays as the fallback.
- **Surfaces:**
  - **Public site:** header, floating button, footer, contact, each app page's closing line, pricing, demo, error pages.
  - **Portal:** Help in the menu, the onboarding wizard, the plan page and the limit modal, the grace banner, error toasts (with the reference), the 419/429/500/503 pages.
  - **Classic:** sign-in, `subscription-expired`, the header.
  - **Emails:** a footer on every platform email.
  - **Receipts and PDFs:** an optional "Powered by Budget Pro · WhatsApp" line, on by default on SaaS and off on standalone, switchable per shop in Settings.
- **The API:** a `support` block in `GET /api/v1/meta` and in the public `GET /api/v1/app/version`, so the sign-in screen has it before anyone signs in.
- **The apps:** `ContactUsScreen` and `AboutScreen` read the server's block (the number leaves the Dart code). So do diagnostics "Send to support", a help card on the account page, and one on the sign-in screen of every flavor. The last block is cached for offline use.
- **Store listings:** the support line in each `listing.md`.
- **A CI scan in all three repos** fails when the number appears outside config, env examples and docs.
- **robots:** served from a route with the current absolute sitemap address, and disallowing `/chat`, `/debts`, `/promotions` and `/welcome` too. The static `public/robots.txt` is removed.

## Order and parallel work

| Stream | Items | Repos |
|---|---|---|
| A | P0-1 config and doctor, queue | kernel, portal |
| B | P0-2 sign-in | kernel, portal, app (validators) |
| C | P0-3 and P0-4 gating and tenancy | kernel |
| D | P0-8 support everywhere, robots | kernel, portal, app, store listings |
| Lead | P0-5 CI and counts, P0-6 remote, P0-7 secrets, the review of danger areas, integration, commits | all |

**Shared files** have one owner each:
- `.env.example`: A, with the names B and D need fixed in advance.
- `routes/api.php`: C owns the groups; B and D make only small, separate edits.
- `app/Http/Kernel.php`: C.

Every item gets a second review against the ADRs and `shop-conventions.md` before it is done. That covers sign-in, tenancy and messages.

## Gates

- **Kernel:** Pint, PHPStan, tests.
- **Portal:** Pint, Larastan, tests, `npm run build`, Vitest.
- **App:** `flutter analyze`, `flutter test`.

Targeted tests run per change. The full suites run fresh at the end, and their true counts go into the READMEs.
