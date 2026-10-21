# 05. Akiba Ledger: roadmap

Status: plan of record for Akiba, 2026-10-21. It follows the family's way of working ([agent-command](../agent-command.md),
[07 delivery plan](../roadmap/07-delivery-plan.md)):

- one item at a time, in the right repo;
- small commits, no stubs or TODOs;
- a report at the end of each phase, then wait for "continue";
- no push, deploy or release unless the owner asks.

Design: [04-architecture](04-architecture.md), [ADR 0009](../adr/0009-sacco-double-entry-ledger.md).
Choices: [decisions](decisions.md).

## Gates (named once, used in every item)

| Gate | What must pass |
|---|---|
| **GK** | Kernel: `vendor/bin/pint --test`; PHPStan level 5 from `tools/phpstan` with **no new baseline entries**; the item's targeted tests (`php artisan test tests/…`) |
| **GP** | Portal: Pint, larastan, the item's tests, `npm run build` (commit `public/build`), Vitest |
| **GB** | Portal browser check: `shot.js` at **390 / 768 / 1366**, **light and dark**, one page-error sweep and axe with no serious or critical issues (once per UI change, not in loops) |
| **GA** | App: `flutter analyze`, the item's `flutter test` files; the screenshot harness for the `akibaledger` flavor where screens changed |
| **GM** | Money paths: property, idempotency and concurrency tests for every new posting path (ADR 0009 §9) |
| **GT** | Tenancy: `TenantModelsTest` green, `TenantIsolationTest` covers the new `{id}` routes, and member tokens are isolated |
| **GF** | Family safety: the existing suites of the other four apps are green. Run them **once per phase**, at the end, and whenever a shared file changed (`config/permissions.php`, `CompanyRules`, `Quotas`, `PlanSeeder`, `SyncApplier`, `config/apps.php`, `app_flavor.dart`). |
| **GR** | Second review against the ADRs and `shop-conventions.md` for anything touching money, tenancy, sync, sign-in or messages to members |

Testing is lean, as the owner asked:

- targeted tests per item;
- the full suites of all three repos **once at the end of each phase** (true counts in the phase report);
- browser passes once per UI change.

---

## P1: Foundation

**Exit:** a SACCO can be set up in office or group mode, members registered and KYC-checked, shares bought, savings
deposited (online and offline) and withdrawn with maker-checker, journals and reversals made, periods closed, and the
basic reports pulled. All of this works on the portal and the API, the deposit flow works in the Akiba app, and the
landing page and demo exist.

The items are in execution order. Each names its repo, what it reuses, and its definition of done (DoD).

### P1.1 Module key and exclusivity (K, P, A)

- **Change:**
  - add `sacco` in the twelve places listed in 04 §2;
  - add `CompanyRules::EXCLUSIVE_MODULES` with refusal `module_exclusive`;
  - `RegistrationService` does not add `finance` for a sacco account;
  - add `SupportContact::APPS[] = 'akiba_ledger'`;
  - give `budgetPro` in the app `hiddenModules: ['sacco']`, plus the "Open Akiba Ledger" screen when a sacco-only
    account signs into Budget Pro.
- **Reuses:** `EnsureApiModule`, `CompanyRules`, `config/onboarding.php`, `ShopMenu`, P `config/apps.php`
  `core_modules`, `EnsureModule`, `AppFlavor`.
- **DoD:**
  - `CompanyRulesSaccoTest` (sacco alone OK; sacco + shop → 422; shop alone unchanged);
  - `ModuleGatingTest` green;
  - sign-up `?app=akiba-ledger` gives exactly `['sacco']`;
  - the portal hides `sacco` on other apps' sign-up;
  - gates GK, GP, GA, GF.

### P1.2 Plan line, Akiba plans and member and SMS quotas (K)

- **Change:**
  - `App\Services\Billing\PlanLine`, applied in the 7 plan-listing places (03 §5.1);
  - `AkibaPlanSeeder` with the 6 rows (03 §5.2), called from `DatabaseSeeder` (idempotent);
  - `Quotas::KEYS` gains `members` and `sms` (hard);
  - `Entitlements::DEFAULT_FEATURES` gains the `sacco_*` flags, all false;
  - `Lifecycle::freePlan(Company)` and the trial pick in `RegistrationService` go by line.
- **Reuses:** `Plan`, `PlanSeeder` pattern, `Quotas`, `Entitlements`, `Lifecycle`.
- **DoD:** `PlanLineTest`:
  - a shop's plan list is unchanged, byte for byte against a fixture;
  - a SACCO sees only `akiba-*`;
  - the lapse fallback goes per line;
  - `max_members` refuses member 31 on Free;
  - money is never refused.
- **Gates:** GK, GF.

### P1.3 `Amount`, `Rate`, `Currency` (K)

- **Change:** `app/Support/Sacco/{Amount,Rate,Currency}.php` as specified in ADR 0009 §2.
- **Reuses:** bcmath (as `Decimal` does), `Money::format` for display only.
- **DoD:** unit and property tests:
  - parse refuses floats and excess decimals;
  - overflow guarded;
  - `allocate` sums exactly for 10,000 random weight sets;
  - `times` rounds half-up once;
  - `Rate::halfOf`.
- **Gates:** GK.

### P1.4 Ledger schema (K)

- **Change:** migrations `2026_MM_DD_600001` to `600004`:
  - `sacco_ledger_accounts`, `sacco_ledger_heads`;
  - `sacco_journal_entries`, `sacco_journal_lines`;
  - `sacco_account_balances`, `sacco_periods`, `sacco_period_balances`;
  - CHECK constraints, unique keys and foreign keys added through `SchemaIntegrity`;
  - best-effort immutability triggers (ADR 0009 §5).
- **Models** with `CompanyScope` and immutability guards.
- **Reuses:** `SchemaIntegrity::CHECKS/FOREIGN_KEYS`, the migration conventions, `TenantModels`.
- **DoD:**
  - `TenantModelsTest` green;
  - `SchemaIntegrityTest` sees the new checks;
  - updating or deleting an entry or line throws;
  - the migration is safe to re-run.
- **Gates:** GK, GT.

### P1.5 Posting, reversal and verification (K)

- **Change:**
  - `Ledger\{JournalDraft, PostingService, ReversalService, LedgerVerifier, PostingRules (P1 kinds)}`;
  - `NumberSequencer` kind `je`;
  - command `sacco:ledger-verify` scheduled at 03:10;
  - verifier results on System health;
  - `LedgerWritersTest`.
- **Reuses:** `DB::transaction` + `lockForUpdate` (`StockService` and `ContributionPayments` patterns), `Notifier`,
  `SchedulerHeartbeat`.
- **DoD:**
  - `LedgerPropertyTest` (500 seeded steps);
  - `PostingIdempotencyTest` (replay and conflict);
  - `ConcurrentPostingSameKeyTest` (fork);
  - the verifier detects a raw edit through the hash chain;
  - an unbalanced draft is refused before any write.
- **Gates:** GK, GM, GR.

### P1.6 Chart of accounts and periods (K)

- **Change:**
  - `database/data/sacco_chart.php` (ADR 0009 §3 table, with group flags and UMRA lines);
  - `ChartOfAccounts::seed`;
  - `Ledger\PeriodService` (create ahead; close with checks and snapshot; reopen the latest);
  - the `period_close` and `period_reopen` executors (they wait for P1.10's approvals; until then the services are
    tested directly).
- **DoD:**
  - seeding is idempotent;
  - the office chart has every `system_key` that `PostingRules` uses;
  - the group subset is complete for group postings;
  - posting into a closed period → `period_closed`;
  - close writes `period_balances` that match the trial balance.
- **Gates:** GK, GM.

### P1.7 SACCO setup and settings (K)

- **Change:**
  - migration `sacco_settings`;
  - `SaccoSetupService::setup` (settings, chart, 3 months of periods, the products "Ordinary savings" (voluntary) and
    "Compulsory savings", the founder's position);
  - an onboarding step `sacco` in `OnboardingService::stepsFor` (only for sacco accounts);
  - `config/sacco.php` and the `.env.example` keys;
  - `config:doctor` checks (04 §17);
  - the mode lock after the first posting.
- **Reuses:** `OnboardingService`, `ConfigDoctor`.
- **DoD:**
  - setup twice → refused;
  - a mode change after a posting → refused;
  - the doctor fails on `SACCO_PAYOUTS_DRIVER=simulated` in production;
  - the regulator line refuses `show_regulated_line` without a licence number.
- **Gates:** GK.

### P1.8 Roles, permissions, officer positions, `SaccoAccess` (K, P, A)

- **Change:**
  - the 15 permissions and 8 roles with the `module` key in `config/permissions.php`;
  - the Team role pickers (P `Team`, K `TeamController`/`TeamRules`, A team screen) filter roles by module;
  - migration `sacco_officers`;
  - `OfficerService`;
  - `SaccoAccess` (permission + position + insider + maker ≠ checker);
  - `ApiPermissionMap` lines;
  - `SyncApplier::permissionFor` lines.
- **Reuses:** `Permissions`, `TeamService`, the `BudgetAccess` and `CommitteeService` patterns.
- **DoD:**
  - a shop's Team screen and API offer exactly today's roles;
  - a SACCO's offer `owner`, the `sacco_*` roles and `viewer`;
  - `SaccoAccessTest` covers the matrix;
  - per-company overrides work for the new permissions.
- **Gates:** GK, GP, GA, GF, GR.

### P1.9 Members and KYC (K)

- **Change:**
  - migrations `sacco_members` (with sync columns), `sacco_member_relations`, `sacco_member_documents`;
  - `SaccoMemberRules`;
  - `Members\MemberService` (register with `Quotas::assertCanAdd('members')`, update, verify KYC (checker ≠
    capturer), status, nominate (Σ shares = 100 %, two witnesses), import CSV with a dry run, duplicates);
  - NIN encrypted with a pepper hash;
  - `NumberSequencer` kind `mem`;
  - `AuditLogger` with NIN filtering;
  - `sacco.sensitive_view` audit.
- **Reuses:** `CustomerRules`/`BaseCrudController` pattern, `Phone::e164`, `DataCrypt`, `StoredFile` and
  `Quotas::assertCanStore`, `DuplicateService` (a `sacco_members` matcher).
- **DoD:**
  - plan limit enforced;
  - the NIN is never in `audit_logs`, the API (except the masked last 4 digits) or the sync payloads;
  - import is idempotent by row;
  - twins are flagged and not refused.
- **Gates:** GK, GT.

### P1.10 Approvals engine (K)

- **Change:**
  - migrations `sacco_approvals` and `sacco_approval_votes`;
  - `SaccoApprovals` (request, approve, reject, cancel, execute, `expireDue`), with executors registered per action;
  - the P1 executors: `withdrawal`, `reversal`, `manual_journal`, `period_close`, `period_reopen`,
    `ledger_account_create`, `member_kyc`, `held_receipt_allocation`, `product_terms_change`;
  - `SaccoAudit` events.
- **Reuses:** `Shop\ApprovalService` ideas (expiry, single use); `audit_logs`.
- **DoD:**
  - `self_approval` refused, including for the owner;
  - single-officer mode only in group mode, and flagged;
  - the payload hash is frozen;
  - double execute is idempotent;
  - an expired approval never executes.
- **Gates:** GK, GM, GR.

### P1.11 Shares (K)

- **Change:**
  - migration `sacco_share_accounts`;
  - `Shares\ShareService` (`purchase`, `transfer` with approval, entrance fee);
  - the one-third cap under lock (`share_cap_exceeded`);
  - the min-shares rule for activation.
- **DoD:**
  - posting rules are tested;
  - Σ share accounts = 3100;
  - concurrent purchases at the cap → only one passes;
  - shares are not withdrawable in office mode (there is no route).
- **Gates:** GK, GM.

### P1.12 Savings products, accounts and transactions (K)

- **Change:**
  - migrations `sacco_savings_products`, `sacco_savings_product_versions`, `sacco_savings_accounts`,
    `sacco_transactions` (with sync columns);
  - `Savings\SavingsProducts` (versioning, disclosure);
  - `Savings\SavingsAccounts` (open, freeze, close, `available()`);
  - `SaccoTransactions::record` and `reverse` for deposit, withdrawal, fee, internal transfer, share purchase and
    entrance fee, in cash or bank;
  - the receipt number (kind `rct`).
- **Reuses:** the `ContributionPayments` pattern (uuid, `reverses_id` unique, reason), `NumberSequencer` with the
  device floor.
- **DoD:**
  - `ConcurrentWithdrawalsTest` (exactly one of two passes);
  - withdrawal above the threshold → `pending_approval`;
  - a dormant account always needs approval;
  - Σ accounts per product = control;
  - reversal is single and needs approval;
  - property test extended.
- **Gates:** GK, GM, GT, GR.

### P1.13 Manual journals, opening balances, ledger read models (K)

- **Change:**
  - manual journal drafts → approval → post;
  - `POST sacco/opening-balances/import` (CSV: member, shares, savings per product, loans deferred to P2 as a
    schedule-less opening loan) through approval, against 3900;
  - `Ledger\TrialBalance` and account statement queries.
- **DoD:**
  - 3900 nets to zero after a complete fictional import;
  - an incomplete import shows the gap;
  - the trial balance equals the period snapshot.
- **Gates:** GK, GM.

### P1.14 P1 reports (K)

- **Change:** the `SaccoReports` registry with `member_statement`, `trial_balance`, `cash_book`, `day_book`,
  `member_register`, `share_register`, `savings_balances` and `approvals_log`, in JSON, PDF (dompdf), XLSX
  (`XlsxWriter`) and CSV, delivered through signed links.
- **Reuses:** `ReportService` style, `ReportFormat`, `ListExports`, `InvoicePdf` layout, `EventPrintController`
  signed-link pattern.
- **DoD:**
  - golden-figure tests on a fixed fictional SACCO;
  - the PDF names the SACCO, its CS No. and licence;
  - "Regulated by …" appears only when licensed;
  - exports carry no NIN.
- **Gates:** GK.

### P1.15 API v1 for P1 (K)

- **Change:** the controllers in `Api/V1/Sacco/` for every P1 row of 04 §13.2, in a route group with
  `api.module:sacco`; then `php artisan api:docs`.
- **Reuses:** `BaseCrudController`, `apiCrud`, `ApiResponse`, `IdempotentRequests`.
- **DoD:**
  - API feature tests per controller (happy path, refusal code, permission 403);
  - `ModuleGatingTest`, `OpenApiTest`, `TenantIsolationTest` (all `{id}` routes → 404 for tenant B);
  - money only as `*_minor` integers (a decimal → 422).
- **Gates:** GK, GT.

### P1.16 Sync for members and cash receipts (K, A)

- **Change:**
  - `SyncRegistry` entries (04 §9.3);
  - `SyncApplier::applyEvent` handler `sacco_transaction` (idempotent; holds to 2150 with a conflict item instead of
    refusing a cash receipt; `offline_not_allowed` for forbidden kinds);
  - the pull snapshots for accounts and products;
  - `DERIVED_COLUMNS`;
  - the phone's `SyncTableSpec`s (optional).
- **Reuses:** `SyncApplier` and `applyContributionPayment`, `MasterDataService`, the `sync_conflicts` inbox,
  `sync:soak`.
- **DoD:**
  - `SaccoSyncReplayTest` (the same batch three times → one posting);
  - an unknown account → held, and the receipt kept;
  - a withdrawal pushed from a phone → `offline_not_allowed`;
  - an old app without the sacco tables still syncs;
  - `sync:soak` passes with sacco tables included.
- **Gates:** GK, GA, GM, GF, GR.

### P1.17 Receipts and messages for P1 (K)

- **Change:**
  - `Messaging\SaccoMessages` (the receipt templates EN and SW) and `SaccoNotices` (after commit, consent through
    `meta.sacco_member_id`, plan SMS allowance with a WhatsApp or in-app fallback);
  - a `Messenger::consentRefusal` branch for members.
- **Reuses:** `Messenger`, `LogChannel`, the `Budget\MessageTemplates` pattern.
- **DoD:**
  - dev and tests write only `message_log`;
  - a rolled-back posting sends nothing;
  - an opted-out member gets no SMS;
  - the allowance is exhausted gracefully.
- **Gates:** GK, GR.

### P1.18 Portal screens for P1 (P)

- **Change:**
  - the `[module:sacco]` route block;
  - `App\Shop\Navigation` section;
  - a dashboard redirect for sacco-only companies;
  - `resources/css/shop/sacco.css`;
  - the `@minor` directive;
  - components in this order: `Setup`, `Overview`, `Members\Index` and `Show` (import), `Teller`, `Withdrawals`,
    `Approvals`, `Savings\Accounts` and `Products`, `Shares`, `Ledger\Accounts`, `Journal`, `Periods`, `Reports`,
    `Officers`, `Settings`, `Audit`;
  - `Tests\Concerns\MakesSacco`.
- **Reuses:** `WithTable`, `PeeksAndEditsRecords`, `CrudModal`, `PerformsShopActions::attempt`, `x-ui.*`,
  `select-search` (a new `members` resource, recorded in the report as the conventions require), `PageStyles`.
- **DoD:**
  - each screen's five proofs;
  - Teller is keyboard-complete;
  - Approvals shows the frozen payload;
  - every refusal is a toast with the service's words;
  - gates GP, GB, GT.

### P1.19 Flavor skeleton with real work (A)

- **Change:**
  - `productFlavors.akibaledger` and the `src/akibaledger/res` icons (new glyph in `docs/flavor-icons/glyphs.py`);
  - `AppFlavor.akibaLedger`;
  - `fam_*_sacco` strings;
  - `assets/flavors/akibaledger.png`;
  - `ModuleRegistry` `sacco` → `SaccoHome`;
  - `lib/sacco/` with sign-in (the family one), home, members (search and detail), Receive money (offline, printed or
    shared receipt), my receipts, and approvals (online, read and decide);
  - the PIN required in this flavor.
- **Reuses:** `SyncEngine`, `SecureSession`, `PinLock`/`AppLock`, `ReceiptPrinter`, `AppListShell`, the
  `ModuleHome`/`ModuleGate`/"Add this app" screens.
- **DoD:**
  - `flavor_test` (Gradle ↔ Dart, icons);
  - `test/sacco/` (offline receipt → outbox → push → replayed; `int` money formatting; the forbidden-offline buttons
    hidden when offline);
  - harness shots of home, members and receipt;
  - `flutter build apk --flavor akibaledger --debug` builds;
  - gates GA, GF.

### P1.20 Landing stub (P)

- **Change:**
  - the `config/apps.php` `akiba-ledger` entry (04 §15.2) with `APP_AKIBA_LEDGER_DOMAIN` empty by default;
  - `resources/content/sites/akiba-ledger.php`, honest about P1 (no loans yet);
  - `marketing/apps/akiba-ledger.blade.php`;
  - the accent tokens, light and dark;
  - the OG image through `scripts/site-og.js`.
- **DoD:**
  - `SiteTest` (accent contrast, one h1, canonical, JSON-LD, no placeholders);
  - `AppSitesTest` with the domain set in the test env;
  - `site:check` passes;
  - gates GP, GB.

### P1.21 Demo part 1 (K, P)

- **Change:**
  - `App\Services\Onboarding\PublicSaccoDemo` (build, heal, rebuild; its own snapshot key and `BUILD`), called by
    `demo:public`;
  - data in `database/data/demo_sacco.php`: "Nalubaale Teachers' SACCO (Demo)", office mode, 60 fictional members,
    18 months of shares, savings and withdrawals posted through the services;
  - a `FamilyDemoBuilder::ACCOUNTS['sacco']` entry for `demo:family`;
  - the demo route on the Akiba site.
- **Reuses:** `PublicDemo` guard and heal pattern, `FamilyDemoBuilder`.
- **DoD:**
  - `PublicSaccoDemoTest` (build, heal after damage, rebuild);
  - the ledger verifier is green on the demo;
  - `Messenger` skips the demo company;
  - the family demo is unchanged.
- **Gates:** GK, GP, GF.

### P1.22 Phase report

- The true suite counts of all three repos (GF).
- Screenshots.
- What was reused.
- What is left.
- `DECISIONS.md` (kernel) gains a pointer to `docs/akiba/decisions.md`.

Then wait for "continue".

---

## P2: Loans, the full lifecycle

Items are in order. Gates: GK + GM + GT + GR on every money item; GP + GB on screens; GA on the app.

| # | Item | Reuses | Definition of done |
|---|---|---|---|
| P2.1 | `sacco_public_holidays` + `WorkingDays` | `TenantModels::GLOBAL_ON_PURPOSE` | Uganda holidays seeded; Eid editable; cooling-off tests across holidays and weekends |
| P2.2 | `Loans\Schedule` (pure maths): annuity, equal principal, flat (group only), principal grace, settlement quote | `Amount`, `Rate` | Unit and property: Σ principal = loan; the last line absorbs rounding; reducing ≤ flat total; settlement has no fee |
| P2.3 | `sacco_loan_products` + `SaccoLoanProductRules`: legal guards (flat refused in office mode; penalty ≤ ½ rate; cost ≤ 100 %/yr; cooling-off ≥ 5; cancellation ≤ 2 %; forbidden collateral types absent); the PDM, Emyooga and VSLA presets | `CustomerRules` pattern | Every guard has a refusal test; the PDM preset reproduces the handbook figures |
| P2.4 | `sacco_loans`, `sacco_loan_guarantors`, `sacco_loan_collateral`, `sacco_loan_votes`, `sacco_loan_documents`, `sacco_loan_schedule_lines` | | Migrations, models, tenant tests |
| P2.5 | `Loans\Eligibility`: multiplier on free savings, membership days, min shares, one open loan, one pending, liquidity, single-borrower limit, insider routing, the employee-loan policy | `TrialBalance` | Matrix test; refusals carry codes |
| P2.6 | Application, appraisal and guarantors: notice and written consent (signed-form upload or SMS link + OTP on a public signed page); exclusions (officers, staff, supervisory board); capacity and liens on savings | `OtpService`, signed routes, `StoredFile` | No disbursement without full consent; officer guarantor refused; the lien lowers available balance |
| P2.7 | Committee: majority of the active committee, delegated limits per position, insider → committee with recusal, appeal to the board | `SaccoAccess`, `OfficerService` | Vote tests; the recused voter is excluded from the quorum; a delegated limit is enforced |
| P2.8 | Contract and key-facts PDFs (the 18 SI 108 fields, rate % per year, total cost of credit), signing with a witness, stored documents | dompdf | Golden PDF field test; the signed version is immutable |
| P2.9 | Disbursement (approval; to savings, cash or bank), cooling-off cancellation, first-due logic, fee deduction | `PostingService`, `SaccoApprovals` | Posting rules; idempotent execute; cancellation within 5 working days reverses correctly |
| P2.10 | Repayments: cash, bank, from savings, allocation order, auto-close at zero, overpayment to savings, offline cash repayment through sync | `SaccoTransactions`, `SyncApplier` | `RepaymentVsAccrualTest` (fork); a replayed sync repayment posts once; Σ loans = 1300 |
| P2.11 | `Loans\Accruals` (interest per due date, penalty monthly), `Arrears` (DPD daily), classification, interest suspense, NPL cap, upgrade rule; `sacco:daily`, `sacco:month-end` | Scheduler, idempotency keys | Catch-up after 3 missed months posts each period once; the NPL cap holds |
| P2.12 | `Provisioning` monthly; write-off (two approvals), recovery, guarantor offset | | Provision = the table × outstanding; write-off reverses through 1390 |
| P2.13 | Top-up and reschedule (borrower consent; new schedule version; reschedule count; Form 1 flag) | | Consent required; history visible; no early-settlement fee on top-up |
| P2.14 | Withdrawal block while in arrears or a guaranteed loan is in arrears (s.55AK) | `SaccoTransactions` | Test |
| P2.15 | Member exit: blocks, settlement plan, approval, journals, no history rewrite | `SaccoApprovals` | Exit with shares transferred and savings paid; history intact |
| P2.16 | Loan API (04 §13.2 P2 rows) + `api:docs` | | API tests, isolation |
| P2.17 | Portal: `Loans\Index` (pipeline), `Loans\Show`, `Loans\Products` (calculator), guarantor consent page, the repayment tab on Teller | the P1 patterns | Five proofs; GB |
| P2.18 | App: loans list, detail, schedule, draft application, cash repayment offline | P1.19 | GA; harness shots |
| P2.19 | Loan reports: `portfolio`, `par`, `aging`, `arrears_list`, `disbursements`, `repayments`, `loan_statement`, `guarantor_exposure` | `SaccoReports` | Golden figures |
| P2.20 | Loan messages: approved, disbursed, due, overdue (borrower and guarantors only), guarantor request and exposure | `SaccoNotices` | Log driver only; consent honoured |
| P2.21 | Demo part 2: loans in every class, a write-off, a rescheduled loan | `PublicSaccoDemo` | Heal keeps them; the verifier is green |
| P2.22 | Phase report | | |

## P3: Mobile money, check-off, tills, messaging, member self-service

| # | Item | Reuses | Definition of done |
|---|---|---|---|
| P3.1 | `momo_requests` additive columns; `Payments\SaccoCollections` (request-to-pay by an officer or a member; settle by webhook or poll, key `momo:{tx_ref}`) | `FlutterwaveService`, `MomoCollections::setup`, `SimulatedFlutterwave`, `FakeFlutterwaveService` | Settle twice → one posting; declined → nothing posted; no network calls in tests |
| P3.2 | `PayoutGateway` + `SimulatedPayouts` + `FlutterwavePayouts` (`Http::fake`) + `PayoutService` (sent, settled or failed journals; `sacco:payouts-poll`) | | Failure reverses; unknown is polled; retry is idempotent; the doctor refuses simulated in production |
| P3.3 | MoMo as a channel for disbursement and withdrawal (approval inherited) | P2.9, P1.12 | End-to-end with the simulated driver |
| P3.4 | Statement import and matching (bank, MTN, Airtel, Flutterwave CSV) for 1100, 1150 and 1160 | `XlsxReader`, roadmap 04 §3 idea | Matched and unmatched lists; nothing posts without a review |
| P3.5 | Check-off: employers, deduction schedule export, remittance import, allocation, variances, stop letters | `XlsxReader`, `XlsxWriter`, dompdf | The golden remittance allocates exactly; a stop letter is issued on loan close |
| P3.6 | Tills and end of day: open, close, count, variance journal (approval) | | Variance posts to 5730; Σ tills = 1010 |
| P3.7 | Member auth: `sacco.member` middleware, `MemberAuth` (OTP + token per membership), the `EnsureApiModule` branch | `OtpService`, Sanctum | `MemberTokenIsolationTest`; throttles; a member never reaches staff routes |
| P3.8 | Member API `/sacco/me/*` (04 §13.2) | | Own data only; requests, never direct money moves |
| P3.9 | App member mode: officer or member sign-in, balances, statement, loans and schedule, pay by MoMo, guarantee consent, apply, request a withdrawal, receipts, consents | `MemberSession` in `SecureSession` | GA; harness shots; sign-out wipes |
| P3.10 | All message templates and schedules (statement links monthly, reminders); the SMS allowance dashboard | `SaccoNotices` | Log only; allowance |
| P3.11 | Portal: Collections and Payouts, Check-off, Tills, Reconcile | | Five proofs; GB |
| P3.12 | Phase report | | |

## P4: Reports, regulatory returns, year-end

| # | Item | Definition of done |
|---|---|---|
| P4.1 | `balance_sheet`, `income_statement` from `umra_line` | Golden figures; they tie to the trial balance |
| P4.2 | UMRA Forms 1–7 and the monthly savings statement in the exact Schedule 4 line order; XLSX, PDF and CSV; signatory blocks; `sacco_return_filings` | `UmraReturnsTest`: every line on a fixed ledger; Form 3 at 50 % of the current surplus; Form 4 ratio |
| P4.3 | Registrar annual return, s.55AS quarterly report, membership statistics | Golden fields |
| P4.4 | Ratios dashboard (weekly liquidity on the last working day, the 20 % rule, all limits, the BoU threshold warning) | Tests per ratio |
| P4.5 | Returns calendar and reminders (the 10th and 14th), mark filed | Due-date tests (working days) |
| P4.6 | Savings interest runs (accrue monthly, credit per product schedule) | Idempotent; rounding allocation exact |
| P4.7 | Year-end close; appropriation waterfall (reserve ≥ 10 %, NCEF 1 %, education 5 % budget, STF ≤ 5 % of share capital, others); AGM pack | Two approvals; 3410 nets correctly |
| P4.8 | Dividend run: cap 10 %, core capital ≥ 10 % after the dividend, "Registrar consent obtained" upload gate, paid to savings, MoMo or cash | Refused above the cap or without consent; allocation exact |
| P4.9 | Share Transfer Fund buy-back for exits | STF ≤ 5 %/yr enforced |
| P4.10 | Dormancy (2 years → register and notice; 3 → advertise; 5 → transfer to the regulator; stricter Tier 4 default) | Timeline tests |
| P4.11 | Insider loans, dormant register, auditor export bundle (TB, journals, registers, audit log XLSX) | Golden |
| P4.12 | Portal: Returns, Year-end, the Reports additions | Five proofs; GB |
| P4.13 | Phase report | |

## P5: Group mode (VSLA and savings groups)

| # | Item | Definition of done |
|---|---|---|
| P5.1 | `sacco_cycles` (one open per group; share value, shares per meeting, service charge, social fund amount) | Unique open cycle; carry-forward opening entry |
| P5.2 | Meetings (offline-first, resumable steps: attendance → shares and savings → social fund → repayments → loans requested → fines → cash count → submit); meeting sync tables | Offline end-to-end in the app; submit online posts all journals once |
| P5.3 | Attendance (present, late, absent, represented); every member marked before submit | Test |
| P5.4 | Fine catalogue; automatic absence and late fines; waivers by approval | Receivable and income postings |
| P5.5 | Funds (social, welfare) with arrears carried forward (MobiSave KEEP) | `fund_dues` unique per meeting; arrears formula |
| P5.6 | Group loans: flat service charge allowed; loan ≤ multiplier × shares; per-meeting frequency; **approval and disbursement need a connection** (AK-32) | Office-mode flat still refused |
| P5.7 | Lockbox cash count with two counters and three key holders; variance | Variance journal |
| P5.8 | Share-out: profit = income − expenses − written-off loans; value per share; payout or carry-forward; blocked while loans are open (digisave rule); closing journal; next cycle | `allocate()` exact; Σ payout = pool; the verifier is green |
| P5.9 | Group reports: share-out statement, meeting summary, "who paid" WhatsApp text, district SHG register export | Golden |
| P5.10 | Demo: "Twekembe Savings Group (Demo)", a group-mode demo company | Heal test |
| P5.11 | Portal and app screens for group mode | GP, GB, GA |
| P5.12 | Phase report | |

## P6: Polish, site and SEO, release

| # | Item | Definition of done |
|---|---|---|
| P6.1 | The full Akiba site: about 21–25 pages like the other hosts (`/features/*`, `/for/teachers-sacco`, `/for/community-sacco`, `/for/emyooga`, `/for/pdm`, `/for/vsla`, how it works, pricing with `akiba-*` rows, FAQ, help, legal, demo); OG images; JSON-LD | `SiteTest`, `AppSitesTest`, `site:check` green; page weight ≤ 200 KB |
| P6.2 | Luganda strings for the app and the portal's SACCO screens (SI 108 reg. 15); Kiswahili complete | String coverage test |
| P6.3 | Accessibility and performance pass: axe on every sacco route; query budgets in `PerformanceBudgetTest`; portal p95 < 300 ms on the demo | |
| P6.4 | Security review (tenancy, member tokens, signed links, NIN handling, sync refusals); the de-identification command; breach runbook; privacy notice and consent text versioned | Review notes in the phase report |
| P6.5 | Store listing in `/Users/mac/Desktop/github/akiba-ledger/store/` (`listing.md`, 512 px icon, 1024×500 feature graphic, phone shots from the harness, Data safety answers) | Matches what works |
| P6.6 | Release AAB: `flutter build appbundle --flavor akibaledger --release` (signed with the family upload key; Play App Signing) | The AAB builds; version shared with the family |
| P6.7 | Deploy: kernel migrations and seeders (`AkibaPlanSeeder`), portal build, `APP_AKIBA_LEDGER_DOMAIN`, `config:doctor`, `site:check`. **Only when the owner asks.** | The deploy runbook updated |
| P6.8 | Phase report: the v1 close-out | |
