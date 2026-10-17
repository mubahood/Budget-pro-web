# 02. Stabilise and secure: every number right, every door locked, nothing silently off

**Goal:** the system behaves the same on every install and never fails quietly:

- an OTP that is sent arrives;
- a sale is recorded once with the right money;
- a cashier cannot see another shop's data;
- a sign-in cannot be guessed;
- a test result we trust says "green".

---

## 1. Config that cannot silently switch things off (P0)

**Rewrite `.env.example` in both the kernel and the portal** with every key the code reads, grouped and commented:

| Group | Keys |
|---|---|
| App | `APP_EDITION` |
| SaaS | `SAAS_MAIL_ENABLED`, `SAAS_REDIRECT_TO_NEW_UI` and the trial and grace days |
| Messaging | `MESSAGING_*` drivers, `WHATSAPP_*` (Meta token, phone id, templates), `SMS_*` (Africa's Talking) |
| Payments | `FLUTTERWAVE_*` |
| Fiscal | `EFRIS_*` |
| Queue | `QUEUE_CONNECTION=database` (ADR 0008) |
| Site facts | `SITE_*` (company name, registration, address, phone, WhatsApp, email, hours) |
| Support | `SUPPORT_*` (06 §4) |

**`config:doctor` (new kernel command, also run by `shop:check` and the deploy script):**

- In production it fails the deploy when a required value is missing:
  - mail is off;
  - the queue is `sync`;
  - messaging drivers are `log`;
  - payment keys are in test mode;
  - the site facts or the support number are empty.
- It warns in local.
- The System health page shows the same list.

**CI uses a test env file made from `.env.example`,** so a missing key fails CI the way it would fail production.

## 2. Sign-in (P0)

| Item | Build |
|---|---|
| Attempt limits on every sign-in | The classic Encore sign-in gains the same throttle as the API (per user and per IP), with a lockout message and the support link. Add tests |
| Master password | Keep it SaaS-only (already off on standalone). Store a **hash** in env (`MASTER_PASSWORD_HASH`), not plaintext, with a minimum of 20 characters. It works only for super admins, from an allow-listed IP or with a 2FA code. Every use is written to an audit table (who, which account, why, IP) and the owner gets a WhatsApp or email alert. The session it opens is marked and time-limited |
| Password rules | At least 8 characters with letters and numbers, plus a breached-password check, for sign-up, change, reset and staff, through one rule in `app/Support/Rules` |
| Two-factor | TOTP required for platform admins and shop owners, optional for others. OTP by WhatsApp or SMS as a fallback (the OTP sign-in exists), plus recovery codes and trusted devices for 30 days |
| Tokens | One per device, revoked on password change. Users see their devices and can sign out everywhere |

## 3. Module gating in the API (P0)

- Add an `api.module:{module}` middleware that reads `Company::modules()` and answers a clear `403 module_off` with the module's name and the "add it" endpoint.
- Map every `/api/v1` route group to its module: shop, finance, budget, poultry, pingpin. The same map serves the classic paths (`module_paths`) and the portal (`EnsureModule`).
- **A test walks every API route** and fails if one has no module, permission or explicit "core" mark.

## 4. Tenancy that cannot be forgotten (P0)

- Make `CompanyScope` apply to the Sanctum guard as well, through the resolved company from `api.tenant`, so the API stops depending on hand-written filters. Keep `withoutGlobalScopes()` only where the code must cross companies, each with a comment and a test.
- **Scope the models that have none:** HeldCart, PriceChange, LabelQueueItem, FiscalSetting, FiscalSubmission and any other tenant table. Leave global tables marked as global on purpose.
- **Isolation tests** for every tenant model and every API route group: company A cannot read, list, update or delete company B's rows. Today there are 4 tests in `TenantIsolationTest`.

## 5. Money exact to the cent (P1)

- **A `Money` value object** on bcmath strings (or integer minor units per currency, zero-decimal currencies included), used by `SaleService`, `PaymentService`, promotions, tax, landed cost, loyalty, gift cards and finance reports. `round()` on floats goes, and today's `app/Support/Money.php` keeps only formatting.
- **Migrate the 4 remaining float columns** to `decimal`, with a recorded migration and a backfill check.
- **Budget and pledge amounts** become `decimal` (no `(int)` casts).
- **Property tests** for the sale maths: line discount, promotion, header discount, tax, rounding and change. Random baskets are checked against a reference calculation.

## 6. Pledges with history (P1, needed by Pledge & Budget)

- Add a `contribution_payments` table (append-only): amount, date, method, received by, receipt number and an idempotency key. `ContributionRecord.paid_amount` becomes the sum, recalculated in a transaction and never edited by hand.
- **Committee roles per event** (chair, treasurer, secretary, member, viewer), with per-event permissions and invitations by phone.
- Phone numbers on pledges (normalised to E.164), for reminders (04 §4).

## 7. Sync and legacy APIs (P1)

- **Retire the v1 `/poultry/sync/*` routes** (phone-clock cursor). Leave a 410 answer with "update the app" for 60 days after the last client using them is seen, and track them in the error tracker until zero.
- **Retire the pre-v1 API** the same way, with a usage counter per route first.
- **Poultry conflict rule:** today the newest client timestamp wins. Move to the ADR 0001 rule (server sequence plus field merge where safe), and show conflicts to the user as the shop sync does.

## 8. Tests we trust (P0)

- Delete the stale `.phpunit.result.cache`, run every suite fresh (kernel, portal, app), fix failures, and write the true counts in each README.
- **CI for all three:**
  - **Kernel:** MySQL 8 and the production MariaDB or MySQL version.
  - **Portal:** add `npm run build`, Vitest and the asset-size budget (03 §2).
  - **App:** create the remote, then analyze, test and build all four flavors.
  - Fix the kernel's dead mobile job.
- **Coverage where money moves:** sale, payment, void, return, stock movement, transfer, goods receipt, landed cost, gift card, loyalty, pledge payment and subscription billing, each with tests for double submission (idempotency) and concurrency (two sales of the last item).

## 9. Secrets and housekeeping (P0)

- Move `LIVE_ACCOUNTS.local.md` out of the web-served folder into the owner's password manager, and add the pattern to `.gitignore`, not only `.git/info/exclude`.
- Check every repo's history for secrets (`gitleaks`) and rotate anything found.
- Confirm that `vendor/` deployed by git carries no dev tools in production.

## 10. Docs that tell the truth (P1)

- **Archive** the 2025 set into `docs/archive/2025/`:
  - `SESSION_*`, `PHASE_1_PROGRESS_REPORT`, `PROJECT_STATUS_COMPLETE`;
  - `ENVATO_*`, `PENDING_ENVATO_TASKS`, `LAUNCH_CHECKLIST`, `DOCUMENTATION_INDEX`;
  - the old `DEVELOPER/DEPLOYMENT/INSTALLATION` guides, `QUICK_*`;
  - `API_VERSIONING_STRATEGY`;
  - the old `docs/*.md` user pages.
- **Write one `docs/README.md` map** pointing at what is current: the master plan, the ADRs, `MOBILE_API_CONTRACT.md`, `STANDALONE_EDITION_PLAN.md`, this roadmap and the runbooks.
- **Fix the status lines:** `STANDALONE_EDITION_PLAN.md` (built on 16 October); `BACKEND_API_MASTER_TASKS.md` (mark superseded); and the portal's `UPGRADE_PLAN.md` and `POWER_PLAN.md` (tick what is built, so open items are visible).
- **Make `openapi.json` generated** (for example with Scramble on the kernel once it runs Laravel 11+, or a maintained generator now), not hand-edited.

## 11. Operations (P0 to P1)

- **Queue:** `database` everywhere (ADR 0008), with the every-minute worker that exists. `config:doctor` fails on `sync` in production.
- **Scheduler catch-up:** each daily task records its last run and runs at the first tick after it is due, so a late or irregular cron never skips a backup, a billing reconcile or a fiscal submission (P1).
- **Error tracking:**
  - keep the in-house tracker;
  - add Sentry or GlitchTip with personal data scrubbed, for the kernel, the portal and the apps;
  - each error page shows a reference and the WhatsApp support link.
- **Uptime:** an external monitor on the kernel's and the portal's health endpoints every minute, alerting the owner on WhatsApp and email, and a public status page.
- **Upgrade the kernel from Laravel 10** (security fixes ended in early 2025) to the portal's Laravel 12 in steps (11, then 12), with the full suite green at each step (P1). The Encore admin's compatibility decides the pace; if it blocks, the classic screens that remain move to the portal first (03 §5).
