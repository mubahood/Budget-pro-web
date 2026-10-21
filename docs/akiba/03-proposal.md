# 03. Akiba Ledger: product proposal

Status: proposal, 2026-10-21. Inputs: [01 MobiSave study](01-mobisave-study.md), [02 Uganda research](02-uganda-research.md),
the reuse inventory (verified against the code, see [04 §0](04-architecture.md#0-what-was-verified)).
Choices are numbered `AK-nn` and explained in [decisions.md](decisions.md).

**Akiba Ledger: SACCO App** ("Akiba Ledger" for short) is the fifth app in the Budget Pro family. It runs on the same
kernel, portal and Flutter codebase as the other four. It adds:

- a new kernel module, `sacco`;
- new portal screens under `/sacco`;
- a new Flutter flavor, `akibaledger` (Android package `com.solavia.akibaledger`);
- a new app site, `akiba-ledger.solaviatech.com`.

There is no new repo and no second backend (AK-01).

---

## 1. Who it is for

There is one product. Each SACCO picks a **mode** at setup, `office` or `group` (AK-05). The mode changes the defaults,
the screens and the legal rules the engine applies. The ledger, members and money paths are the same in both.

| Segment | Mode | Size | How money moves | What they need first |
|---|---|---|---|---|
| **Staff / employee SACCOs** (teachers, local government, health workers, police, prisons) | office | 100 to 3,000 members | Payroll check-off each month, cash and MoMo at the office | Check-off schedule and remittance import, loan schedules on reducing balance, statements members can see, a "stop deduction" letter |
| **Community SACCOs** (parish, market, trade, boda-boda) | office | 100 to 10,000 | Cash over the counter, MoMo more and more, bank | Teller receipts, maker-checker on withdrawals, PAR, UMRA returns once licensed |
| **Emyooga SACCOs** (one per category per constituency) | office | Associations of 7 to 30 members, as members | MSC seed capital (a borrowing), loans to associations | Loans and repayments, external-borrowing liability, PAR. On-lending through associations is out of v1 (§4) |
| **PDM SACCOs** (one per parish) | office | Enterprise groups and households | The Parish Revolving Fund in a bank or Wendi account, loans at most UGX 1 m | The PRF loan preset (6 %/yr, 0.5 %/month default surcharge, UGX 5,000 fee), group co-guarantors, the 90 %-inputs record |
| **VSLAs and savings groups** (village, church, women's, youth, family) | group | 10 to 30 members | Cash in a lockbox at weekly meetings | Meeting entry offline, shares 1 to 5 per meeting, social fund, fines, a share-out that adds up |

The family's buyers are the same people. A treasurer who keeps a shop on Shelfie or a wedding on Pledge & Budget is
often also the treasurer of a teachers' SACCO or a village group.

## 2. The problems Akiba solves

### 2.1 From the market and the law ([02](02-uganda-research.md))

1. **Books are not trusted.** Fraud keeps happening; the Kamu Kamu Traders case alone is about UGX 3.1 bn
   ([02 §6](02-uganda-research.md#6-market-sacco-software-sold-in-uganda)). Members cannot see their own balances, so
   errors and theft go unnoticed.
   - **Akiba's answer:** a double-entry ledger that always balances and whose posted rows never change
     ([ADR 0009](../adr/0009-sacco-double-entry-ledger.md));
   - a receipt for every transaction (SI 108 reg. 16);
   - maker-checker on money that goes out;
   - members can see their own balances in the app.
2. **Since November 2024, flat interest is effectively unlawful for SACCOs** (SI 108 reg. 9). Many SACCOs still charge
   it.
   - **Akiba's answer:** in office mode the engine offers reducing balance only. It also enforces:
     - penalty at most ½ the monthly rate, on overdue principal only;
     - total charges at most 100 % of principal a year;
     - a 5-working-day cooling-off period;
     - no early-settlement fee;
     - written guarantor consent;
     - the 18-field contract and key-facts sheet.
   - Group mode keeps the VSLA flat service charge, because self-help groups are exempt (AK-07).
3. **Returns are late and expensive.** A late monthly return costs UGX 500,000 plus UGX 200,000 a working day.
   - **Akiba's answer:** UMRA Forms 1 to 7, the monthly savings statement, the Registrar's annual return and the
     s.55AS quarterly report come straight from the ledger in the gazetted line order;
   - a due-date calendar with reminders (P4).
4. **Staff SACCOs lose members over check-off errors.** Deductions continue after loans are repaid.
   - **Akiba's answer:** deduction schedules, remittance import with automatic allocation, flags for over- and
     under-deduction, and a stop letter when a loan clears (P3).
5. **Rural groups abandon tools that need the network or a laptop.**
   - **Akiba's answer:** an offline phone flow for meetings and cash receipts, built on the family's sync engine
     (ADR 0001);
   - large numbers, English, Kiswahili and Luganda.
6. **The regulators keep changing.** UMRA may be folded into MoFPED.
   - **Akiba's answer:** the regulator's name, short name and portal are settings, never code (AK-14).

### 2.2 From MobiSave (the owner's existing system, [01 §8](01-mobisave-study.md#8-assessment))

Akiba ports MobiSave's **rules**, not its code (AK-12). Each MobiSave weakness has a direct answer:

| MobiSave weakness | Akiba |
|---|---|
| W1, W2: not double-entry; the group's money sits in the admin's user account | Real ledger accounts per SACCO (cash, bank, MoMo, loans, savings and shares per member). Every journal balances to zero. |
| W3: money in varchar, float and `(int)` casts | `BIGINT` minor units with an immutable `Amount` value object. Rates in basis points. One rounding rule (AK-09). |
| W4 to W6: loans never close; flat interest charges 0; interest flat on the original principal | Schedule engine: annuity or equal principal on the reducing balance. Loans close automatically at zero. Interest is billed once per period by idempotency key, only while principal is outstanding. |
| W7, W8: dues outside the ledger; double credit | Every receipt is a journal. Funds (welfare and others) are ledger accounts (P5). |
| W9: transfers not atomic | One posting service, one DB transaction, row locks in a fixed order. |
| W10: group and member balances disagree | Balances come from the ledger. Sub-ledgers reconcile to control accounts every night. |
| W11: rows editable everywhere; history rewritten when a member leaves | Posted rows are never edited, only reversed. A member exits by settlement journals; history is never moved. |
| W12, W13: no foreign keys; drift; reports double-count | Foreign keys on money links, CHECK constraints, a nightly ledger check, reports read the ledger only. |
| S1 to S4: no tenant checks; `isAdmin()` always true; members read everyone's data | `CompanyScope` on every model, the module gate, one permission map, officer positions, and member tokens that see only their own data. |
| S5, S6: default passwords; plaintext OTPs; secrets in git | The family's sign-in (hashed OTPs, password rule, PIN lock). Nothing secret is committed. |
| P1, P2: N+1 aggregates; SMS inside model `boot()` | Balance caches updated in the posting transaction. Queued messages after commit. |
| P6: no meetings, share-out, welfare, fines or exit | Group mode (P5) and the exit workflow (P2). |

**What Akiba keeps from MobiSave:**

- scheme terms copied onto each loan;
- one open cycle per group;
- idempotent interest billing with month catch-up;
- contribution schedules unique per period, generated under lock;
- eligibility flags (compulsory, alive or late);
- the "who paid" WhatsApp text;
- guard rules:
  - one open loan;
  - one pending request;
  - liquidity ≥ loan;
  - minimum deposits before borrowing;
  - withdrawal ≤ available balance;
  - repayment ≤ outstanding.

## 3. Scope of v1 (the owner's minimum: phases P1 to P6)

The phases are in [05-roadmap.md](05-roadmap.md). In one line each:

| Phase | What ships |
|---|---|
| **P1 Foundation** | Module plumbing, plan line and member quota, `Amount`, the double-entry ledger (CoA, journals, periods, reversals, nightly check), SACCO setup and mode, roles and officer positions, members and KYC, shares, savings products, accounts and transactions with maker-checker withdrawals, approvals inbox, audit trail, basic reports (member statement, trial balance, cash book, registers), API, sync for cash receipts, receipts via Messenger, portal screens, the flavor skeleton, the landing page, demo part 1 |
| **P2 Loans** | Loan products with legal guards and the PDM preset, application → appraisal → guarantors (written consent) → committee (majority, delegated limits, insider recusal, appeal) → contract and key facts → cooling-off → disbursement (savings, cash, bank) → schedule → accrual → repayment allocation → arrears, classification, provisioning, interest suspense, NPL cap → top-up, reschedule, write-off, recovery. Member exit. Offline repayments. Demo part 2 |
| **P3 Money channels and members** | MoMo collections (Flutterwave pattern, simulated in dev), a payout interface with a simulated driver, MoMo disbursements and withdrawals, statement import and matching, check-off, teller tills and end of day, every message template and reminder, member self-service in the app (OTP + PIN, balances, statements, schedule, pay by MoMo, guarantor consent, apply, request a withdrawal) |
| **P4 Reports and year-end** | PAR 1/30/60/90, aging, portfolio, balance sheet, income statement, UMRA Forms 1 to 7 and the savings statement, the Registrar's return, s.55AS, ratios (weekly liquidity), returns calendar, savings interest, year-end close, the appropriation waterfall, the dividend run, AGM pack, dormancy, insider and guarantor reports |
| **P5 Group mode** | Cycles, offline meetings, attendance (present, absent, by representative), fine catalogue and automatic fines, social and welfare funds with arrears, shares per meeting, flat service-charge loans, lockbox counts with three key holders, share-out and carry-forward |
| **P6 Polish and release** | The full multi-page site and SEO, store listing and feature graphic, the AAB, Luganda and Kiswahili coverage, accessibility, performance and security review, the production config doctor, the deploy and runbooks |

### 3.1 Out of v1

These are written down so nobody builds them by accident. Each one can come back later through a decision.

- **MobiSave data migration** (AK-11). A generic CSV import of members and opening balances is in v1 (P1), and a
  MobiSave group can come in that way.
- **Emyooga association on-lending** (association loans with member sub-ledgers). Associations can be members of
  kind `group` in v1.
- **Live mobile-money traffic.** Collections and payouts are built and tested against the simulated drivers. Going
  live needs the owner's merchant keys (AK-17).
- **Direct MTN or Airtel drivers, Yo! and Relworx.** The payout interface allows them. Only Flutterwave and the
  simulated driver ship.
- **USSD**, agency banking and cards.
- **Credit-bureau submission.** Consent is captured in v1; there is no export.
- **Automatic filing to the regulator's portal.** Akiba exports XLSX, PDF and CSV in the exact form layouts.
- **Branches.** One office per SACCO, with several tills.
- **Multiple currencies per SACCO.** One currency, the company's.
- **A per-SMS billing ledger.** SMS is a plan allowance (§5).
- **Bank of Uganda MDI features** for large SACCOs. A threshold warning is in P4.
- **iOS.** The app repo has no `ios/` folder.
- **Classic laravel-admin screens** for the sacco module. Only the portal and the app have them (AK-20).
- **Combining `sacco` with shop, poultry or budget in one company** (AK-04).
- **Push notifications.** FCM is not built family-wide yet (roadmap 05 §3). Akiba uses SMS, WhatsApp and in-app
  until it exists.
- **Fixed-asset depreciation runs.** Manual journals cover depreciation in v1.

## 4. What makes it the family's

Akiba reuses, and does not rebuild, the family's existing pieces:

| Piece | What Akiba uses |
|---|---|
| Accounts | Sign-in, OTP, the password rule and team invites |
| Tenancy | `CompanyScope` and the tenant tests |
| Module gating | `api.module`, the portal's `module:`, Settings › Modules |
| Roles | Per-company roles (ADR 0004), with officer positions on the `BudgetAccess` pattern |
| Sync | Sync v2 (ADR 0001) |
| Messaging | `Messenger`, with the log driver in dev |
| Mobile money | Flutterwave, `MomoCollections` and `SimulatedFlutterwave` |
| Reports | dompdf and `XlsxWriter` |
| Demo | The public demo builder |
| App and site | `config/apps.php` sites and the Flutter flavors |

**What is genuinely new:**

- the ledger (ADR 0009);
- loan maths;
- a payout interface;
- SACCO screens.

---

## 5. Pricing per SACCO: rows for the kernel `Plan` table

### 5.1 The gap and the minimal fix (AK-15)

Plans have no module dimension today:

- one `plans` table serves every app;
- `Quotas::KEYS` knows users, products, sales, storage, devices and locations;
- seven places list plans:
  - `Admin\BillingController`;
  - `Api\V1\BillingController`;
  - `Quotas::planThatFits`;
  - `Lifecycle::freePlan`;
  - `RegistrationService` (the default trial);
  - classic `RecordPayment` and `CompPlan`;
  - the portal's pricing pages.

**The smallest change that keeps the four apps exactly as they are:**

1. **Plan line.** An Akiba plan carries `features.line = "akiba"`. A plan without `line` is on the family line.
   - A new kernel helper, `App\Services\Billing\PlanLine`:
     - `of(Plan)`: `features.line ?? 'family'`;
     - `for(Company)`: `akiba` when the company's modules include `sacco`, else `family`;
     - `scope(Builder, string $line)`.
   - Every plan listing above adds `PlanLine::scope($q, PlanLine::for($company))`, or the line of the page being
     shown.
   - Family plans never show to a SACCO, and Akiba plans never show to a shop.
2. **Slugs** start with `akiba-`, so the platform admin and logs can tell them apart.
3. **Trial and free fallback per line.**
   - `RegistrationService` picks `config('sacco.plans.trial', 'akiba-trial')` when the sign-up's modules include
     `sacco`.
   - `Lifecycle::freePlan(Company)` returns `akiba-free` for an Akiba company.
   - ADR 0005 holds as it is: never a lockout, only new members, logins and phones are refused.
4. **`limits.max_members`**, enforced through `Quotas`:
   - `KEYS['members'] = 'max_members'`;
   - `used(company, 'members')` counts `sacco_members` with status `applicant`, `active`, `dormant` or `suspended`;
   - `MemberService::register` calls `assertCanAdd($company, 'members')`.
   - Deposits, repayments and every money path are **never** refused for a plan limit (the ADR 0005 rule, as "sales
     are never refused").
5. **`limits.max_sms_per_month`**, through `Quotas`:
   - `KEYS['sms'] = 'max_sms_per_month'`, counted from `message_log` rows with channel `sms` for the company this
     calendar month;
   - it is hard: when the allowance is used up, receipts fall back to in-app and WhatsApp share and the treasurer is
     told once.
   - Family plans have no such key, so nothing changes for them (`null` means unlimited, and the family never sends
     SMS through this path).
6. **Feature flags** read with `Quotas::featureOn`:
   - `sacco_office_mode`;
   - `sacco_returns` (UMRA forms and the Registrar return);
   - `sacco_momo` (collections);
   - `sacco_payouts`;
   - `sacco_checkoff`;
   - `sacco_member_app`;
   - `sacco_sms_receipts`.
   - `Entitlements::DEFAULT_FEATURES` gains all of them as `false`. Their keys only matter to `sacco` code, so the
     four apps are unaffected.

**Tests:**

- a shop sees exactly today's plan list;
- a SACCO sees only `akiba-*`;
- a lapsed SACCO trial falls to `akiba-free`;
- a lapsed shop trial still falls to `free`;
- `max_members` refuses member 31 on `akiba-free`, but deposit number 10,000 is accepted.

### 5.2 The rows

Benchmarks:

- SaccoRa: UGX 50k / 70k / 100k a month for 100 / 500 / unlimited members.
- SaccoSys: UGX 800 per active member a month, plus a UGX 3 m setup fee.

Akiba has **no setup fee**. A year costs 10 months. SMS beyond the allowance is not sold in v1. MoMo fees pass through
to the SACCO.

| slug | name | price_ugx /month | price_ugx_annual | price (USD) | trial_days | is_public | limits | features (besides `line: akiba`) |
|---|---|---|---|---|---|---|---|---|
| `akiba-trial` | Akiba trial | 0 | — | 0 | **30** | false | max_members 300, max_users 5, max_devices 5, storage_mb 500, max_sms_per_month 100 | as `akiba-standard` |
| `akiba-free` | Akiba Free | 0 | 0 | 0 | 0 | true | max_members **30**, max_users 2, max_devices 2, storage_mb 100, max_sms_per_month 0 | group mode only (`sacco_office_mode` false), WhatsApp share receipts |
| `akiba-starter` | Akiba Starter | **50,000** | 500,000 | 14 | 30 | true | max_members **150**, max_users 4, max_devices 4, storage_mb 500, max_sms_per_month 300 | `sacco_office_mode`, `sacco_sms_receipts`, `sacco_member_app` |
| `akiba-standard` | Akiba Standard | **100,000** | 1,000,000 | 27 | 30 | true | max_members **600**, max_users 8, max_devices 8, storage_mb 2,000, max_sms_per_month 1,200 | + `sacco_momo`, `sacco_checkoff` |
| `akiba-compliance` | Akiba Compliance | **250,000** | 2,500,000 | 68 | 30 | true | max_members **2,500**, max_users 20, max_devices 20, storage_mb 5,000, max_sms_per_month 4,000 | + `sacco_returns`, `sacco_payouts` |
| `akiba-enterprise` | Akiba Enterprise | **600,000** | 6,000,000 | 162 | 0 | true | everything unlimited (`null`) except max_sms_per_month 12,000 | everything on |

**Why these numbers:**

- A 30-member VSLA pays nothing. It is the funnel, the same idea as ADR 0005's Free plan.
- Starter matches SaccoRa's entry price.
- Compliance is the first tier with UMRA returns, the thing licensed SACCOs pay to avoid fines. A 2,500-member SACCO
  pays UGX 100 per member, an eighth of SaccoSys and with no setup fee.
- Enterprise costs what SaccoSys charges for about 750 members.
- SMS allowances are sized at about UGX 30 per SMS, so the allowance stays under a third of the price.

**Notes:**

- The trial is longer than the family's 14 days (AK-16). SACCOs onboard over a monthly cycle: one check-off round,
  one board meeting.
- The rows go into `database/seeders/AkibaPlanSeeder.php`. It is idempotent and only adds missing keys, like
  `PlanSeeder`. Prices stay editable by the platform admin.
- `price` in USD is filled because `Plan::chargeIn` falls back to it outside Uganda.
- An over-limit downgrade follows ADR 0005: existing members keep transacting, and only new members are refused.

## 6. Brand, name and reach

**Brand** (AK-02):

- **Accent:** Akiba indigo **`#2B3A8F`**. White on it reads at **9.98:1**, and it is distinct from the family's green,
  teal, brown and plum.
- **Icon:** two equal white coin stacks joined by a level beam: *the books balance*.
- **Tone:** calm, exact, numbers first. Akiba never claims to be licensed or approved by a regulator.

**Name:** always "Akiba Ledger", never "Akiba" alone. A URSB search in classes 9 and 36 is due before launch (AK-13).

**Site:** `akiba-ledger.solaviatech.com`, on the family's `*.solaviatech.com` pattern. The DNS record is the owner's
to add (AK-06).

**Store:**

- package `com.solavia.akibaledger` (AK-03);
- listing folder `/Users/mac/Desktop/github/akiba-ledger/store/`, the same layout as the other three apps.

## 7. What success looks like (first 6 months after release)

| Measure | Target |
|---|---|
| Ledger discrepancies found by tests, the nightly check or support | **0** |
| A treasurer records a cash deposit offline and the member gets a receipt after sync | under 30 s on the phone |
| Pilot SACCOs: one staff SACCO, one community SACCO, three VSLAs | 5 |
| Monthly UMRA forms produced on time for pilots with a licence | 100 % |
| Groups on Free that upgrade within 6 months | 10 % |
| Crash-free sessions in the app | 99.5 % (family target) |
