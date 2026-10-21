# Akiba Ledger: decisions

Every choice made for Akiba, one per entry. Format: **id: decision.** *Why.* Alternatives considered.

- A decision changes only through a new entry that names the one it replaces.
- Owner decisions are marked **(owner)**.
- Entries that change how the whole system works also have an ADR ([0009](../adr/0009-sacco-double-entry-ledger.md)).

---

## Product and brand

**AK-01: Akiba is a module, portal screens and a flavor inside the family, not a new product stack.**

- **What it is:** the kernel module `sacco`, portal screens under `/sacco`, Flutter flavor `akibaledger` and app site
  `akiba-ledger`.
- *Why:*
  - tenancy, sign-in, roles, sync, messaging, payments, reports, demo, sites and flavors already exist and are tested;
  - the agent command says "nothing is built from scratch";
  - one deploy keeps the lockstep.
- **Alternatives:**
  - a new Laravel app, which would mean a second backend and duplicated auth and billing;
  - extending MobiSave (Laravel 8, end of life, no double entry; 01 §8).

**AK-02: Brand.**

- **Accent:** **Akiba indigo `#2B3A8F`**.
  - White on it reads at 9.98:1.
  - Soft tint `#E8EAF6`. Darker steps `#222E72` and `#172050`. Line `#B9C0E3`. Dark-mode tint proposed `#9AA7F0`,
    to be confirmed by `SiteTest` at 4.5:1 on the dark paper.
  - It is distinct from Budget Pro `#0F7B4F`, Shelfie `#0B5F73`, Kuku Tally `#9A4A07` and Pledge & Budget `#8A2C5A`.
    It is the family's first blue, and blue reads as "bank and trust" in the market.
- **Icon:** the family style, a single bold white symbol on a solid indigo rounded square.
  - The symbol is **two equal coin stacks joined by a level beam**: a balance, meaning the books balance (debits =
    credits).
  - It is drawn as a line glyph in `docs/flavor-icons/glyphs.py`, readable at 48 px, with no text in the icon.
- **Feature graphic (1024×500):**
  - solid indigo;
  - on the left, the white tile and the wordmark "Akiba Ledger" with the line "Savings, shares and loans, in books
    that balance.";
  - on the right, a phone (a real harness screenshot) showing a member statement with "Balanced ✓" under the trial
    balance total.
- **Tone:**
  - calm, exact, plain words, numbers first;
  - "your money is counted twice" rather than hype;
  - never "approved by" or "licensed by" any regulator, and never a guaranteed outcome;
  - East African examples on the app pages (teachers' SACCO, VSLA, PDM), international wording on the family pages
    (the FAMILY_PLAN rule).
- *Why:* a family-consistent look with its own colour; trust is the product.
- **Alternatives:**
  - deep teal (too close to Shelfie);
  - gold (fails 4.5:1 with white);
  - navy `#1E3A8A` (10.36:1, but it reads as generic corporate blue next to the family's warm tones).

**AK-03: Android package `com.solavia.akibaledger` (owner).**

- *Why:* the owner's explicit choice. It was verified unused on Play (HTTP 404, 02 Appendix A).
- It breaks the `com.budgetpro.*` pattern of the other new apps. That is accepted: IDs never change after upload, so
  it is recorded here and in `app_flavor.dart` and `build.gradle`.
- **Alternatives:** `com.budgetpro.akibaledger`.

**AK-06: Website `akiba-ledger.solaviatech.com`.**

- *Why:* it follows the live `*.solaviatech.com` pattern of the four app sites (`AppSite`, `ResolveAppSite`, host-only
  sessions).
- The DNS record and AutoSSL are the **owner's** to add. Until then, `APP_AKIBA_LEDGER_DOMAIN` stays empty and the page
  lives at `/akiba-ledger` on the main site.
- **Alternatives:** `akiba.solaviatech.com` (shorter, but weakens the full-name rule in AK-13).

**AK-13: Always the full name "Akiba Ledger".**

- *Why:* "Akiba" alone is a weak mark already used in East African finance, including a Ugandan group-savings app
  (02 Appendix A).
- The **owner** runs a URSB search in classes 9 and 36 before launch. Copy and store listings never shorten the name
  to "Akiba".
- **Alternatives:** a different name (not needed while the full name is clear).

**AK-46: Keys and names.**

| Thing | Value |
|---|---|
| Module key, tables, namespaces | `sacco` (brand-neutral, so a rename never touches the schema) |
| Flavor key | `akibaledger` |
| Dart const | `AppFlavor.akibaLedger` |
| Site key and slug | `akiba-ledger` |
| `SupportContact` app key | `akiba_ledger` |
| Store folder | `/Users/mac/Desktop/github/akiba-ledger/store/` |
| Env keys | `APP_AKIBA_LEDGER_DOMAIN`, `APP_AKIBA_LEDGER_ANDROID_ID`, `APP_AKIBA_LEDGER_PLAY_URL` |

- *Why:* the existing conventions for each surface.
- **Alternatives:** `akiba_*` tables, which tie the schema to a brand that may change.

---

## Shape of the module

**AK-04: `sacco` is an exclusive module.**

- A company with `sacco` has no other module, not even `finance` (`CompanyRules::EXCLUSIVE_MODULES`, 422
  `module_exclusive`).
- *Why:*
  - a SACCO's books are institutional and must not mix with a shop's;
  - its plan line (AK-15) must be unambiguous;
  - `financial_records` (ADR 0003) and the SACCO GL must never describe the same money.
- A treasurer who also runs a shop uses two accounts. That is the family's one-business-per-account rule.
- **Alternatives:**
  - allow combinations (ambiguous plans, two ledgers);
  - post SACCO activity into `financial_records` (single-entry; fails the returns).

**AK-05: One product with a mode, `office` or `group`, set at setup.**

- The mode is locked after the first journal; changing it later is a support action with a new chart.
- **Office mode:** staff, community, Emyooga and PDM SACCOs.
- **Group mode:** VSLAs and savings groups.
- *Why:*
  - the law differs (SI 108 binds SACCOs, not self-help groups);
  - so do the workflows (counter versus meeting).
  - The ledger, members and money paths are shared.
- **Alternatives:** two apps (double the work, and a group that grows into a SACCO would have to migrate).

**AK-20: No classic laravel-admin screens for `sacco`.**

- `module_paths['sacco'] = []`.
- *Why:* the classic panel is being retired (roadmap 03 §10), and every new screen goes in the portal.

**AK-34: Code layout.**

| Layer | Home |
|---|---|
| Models | `App\Models\Sacco*` (flat, prefixed, like `Poultry*`) |
| Services | `App\Services\Sacco\{Ledger,Loans,…}` |
| Value objects | `App\Support\Sacco\*` |
| Rules | `App\Support\Rules\Sacco*Rules` |
| API controllers | `App\Http\Controllers\Api\V1\Sacco\*` (a subfolder, because there are about 25 controllers) |
| Portal | `App\Livewire\Shop\Sacco\*` |
| App | `lib/sacco/{data,domain,services,screens}` |

- *Why:* the kernel's conventions, and roadmap 05 §6's layered layout for the app.
- **Alternatives:** a separate `app/Sacco` tree like Ping Pin. Ping Pin is a separate product with its own plans;
  Akiba is a family app.

**AK-36: The Budget Pro flavor does not show `sacco`.**

- A sacco-only account signing into Budget Pro sees "This is an Akiba Ledger account: open Akiba Ledger" with the Play
  link.
- *Why:*
  - Budget Pro means shop, farm and events;
  - exclusive SACCO accounts never coexist with those modules (AK-04).

**AK-54: Emyooga association on-lending is out of v1.**

- An association can be a member of kind `group`. Loans to it are ordinary loans.
- *Why:* member-level sub-ledgers under an association loan are a second lending book. It is scoped later from pilot
  feedback.

---

## Money and the ledger

**AK-08: Money is `BIGINT` minor units with `App\Support\Sacco\Amount`. Rates are integer basis points.**

- *Why:*
  - no floats (agent command);
  - exact sums;
  - MobiSave's text, float and `(int)` failures (W3);
  - `Money` only formats and casts to float;
  - `Decimal` works on decimal strings, not minor units.
- It sits in `App\Support\Sacco`, so the four apps' code is untouched (ADR 0009 §2).
- **Alternatives:**
  - `DECIMAL(20,2)` with `Decimal` (the family's current style; workable, but rounding stays implicit and UGX has no
    cents);
  - extend `App\Support\Money` (risks every shop call site).

**AK-09: One rounding rule.**

- Half-up to the minor unit, once per computed amount.
- Distributions (dividends, share-out, interest runs) use largest-remainder `allocate()`, so the parts sum exactly to
  the pool.
- *Why:* totals that tie to the ledger to the shilling.
- **Alternatives:**
  - banker's rounding (unfamiliar to treasurers);
  - a rounding-difference account (needless noise).

**AK-25: Journals are hash-chained per SACCO and anchored at each period close.**

- *Why:* tamper evidence that does not depend on database privileges, which shared hosting may not grant (ADR 0009
  §6).
- **Alternatives:**
  - DB triggers only (not always installable);
  - an external notary service (new infrastructure; ADR 0008).

**AK-45: Each entry has an `entry_date` (always in an open period) and a `value_date` (when the money moved).**

- *Why:*
  - offline receipts and late corrections must never post into a closed period;
  - statements must still show the real day.
- **Alternatives:** reopen periods for late items (audit noise; breaks closed returns).

**AK-24: An offline cash receipt is never refused. Problems go to 2150 Unallocated receipts with an allocation
approval.**

- Problems include an unknown or closed account, a closed loan, or an amount over `offline_receipt_max_minor`.
- *Why:*
  - the cash was physically taken, so the books must show it (the shop's "completed sales are never rejected");
  - suspense keeps the member ledgers clean until a person decides.
- **Alternatives:**
  - reject the sync (cash disappears from the books);
  - auto-post to the nearest account (wrong member risk).

**AK-53: Periods are calendar months.**

- Close and reopen need maker-checker. Only the latest closed period may be reopened.
- *Why:* monthly UMRA returns, and reopen discipline.
- **Alternatives:** free reopen (returns would drift from the books).

**AK-52: The API carries money only as `*_minor` integers plus `currency`. A decimal is refused with 422.**

- *Why:* no client can introduce a float.
- The existing family endpoints keep their decimal format.

---

## Roles, approvals and audit

**AK-21: Two layers: company roles for what a login may do, and officer positions for who holds an office.**

- **Company roles** (ADR 0004; new `sacco_*` roles carrying `'module' => 'sacco'`, offered only to SACCO companies).
- **Officer positions** (`sacco_officers`, the `BudgetAccess` pattern) cover:
  - the committee;
  - delegated limits;
  - insiders;
  - key holders;
  - signatories.
- *Why:* group-mode key holders have no login; a committee member's vote depends on the term of office, not on the
  role alone.
- **Alternatives:**
  - roles only (cannot express terms, limits or recusal);
  - positions only (breaks the one permission map).

**AK-22: The checker is never the maker, the owner included.**

- The only exception is `single_officer_mode`, allowed in **group mode only**. There, every self-approval is flagged
  on the audit and closing reports.
- *Why:*
  - fraud is the market's biggest problem (02 §6);
  - tiny groups sometimes have one literate officer.
- **Alternatives:**
  - the owner bypasses (defeats the control);
  - no exception (locks out two-person groups).

**AK-44: Withdrawal approval threshold.**

- Defaults:
  - office mode: any withdrawal above UGX 1,000,000;
  - group mode: every withdrawal;
  - always for dormant accounts (Tier 4 s.53) and members in arrears.
- It is configurable.
- *Why:* tellers serve small withdrawals at the counter, and large ones need a second officer.
- **Alternatives:** approval for every withdrawal (counter queues in office mode).

**AK-33: Audit through the existing `audit_logs`.**

- `AuditLogger` on master models, plus `SaccoAudit::record` for business actions with dotted `sacco.*` names. The
  money's own audit is the append-only, hash-chained journal.
- *Why:* one audit table and one screen.
- **Alternatives:** a separate `sacco_audit_events` table (two places to look).

---

## Loans and law

**AK-07: Interest method rules.**

- **Office mode:** reducing balance only (annuity or equal principal). `flat` is refused with
  `flat_interest_not_allowed`. The rate is always shown as % per year, with the total cost of credit (SI 108 reg. 5, 9).
- **Group mode:** `flat` is allowed as the VSLA monthly service charge. Self-help groups are exempt from SI 108.
- *Why:* the law since November 2024 (02 §4.3).
- **Alternatives:**
  - allow flat with a warning (unlawful for a SACCO);
  - reducing everywhere (fights VSLA practice for no legal reason).

**AK-39: Interest accrues per schedule period on the actual outstanding principal at the start of the period.**

- It is due on the due date.
- Early settlement pays interest only to the day (`outstanding × annual rate × days / 365`) and no fee.
- *Why:*
  - simple to explain;
  - exact on reducing balance;
  - SI 51 reg. 39 (no full-term interest);
  - SI 108 reg. 12.
- **Alternatives:** daily accrual (more postings, same result for members).

**AK-28: Penalty default.**

- 50 % of the monthly contract rate (the legal maximum, editable downward), on overdue principal only.
- It is pro-rata by days overdue, posted monthly, never compounded, and starts after `grace_days` (default 0).
- *Why:* SI 108 reg. 4(5), reg. 9.
- **Alternatives:** a flat late fee (risky under the 100 % cap; 02 §4.4).

**AK-40: Provisioning and suspense.**

- **Provisioning:** the SI 51 bands at 1 / 5 / 25 / 50 / 100 %, by days or by instalments, whichever is worse. Posted
  monthly as a delta to the required level.
- **From substandard onwards:**
  - interest goes to suspense (memorandum only);
  - interest plus fees after the NPL date are capped at the principal owed at that date (SI 108 reg. 7);
  - an upgrade needs 6 good instalments, at most twice.
- *Why:* the regulation, and Form 1 and Form 7.
- **Alternatives:** none lawful.

**AK-26: Cooling-off.**

- Disbursement is allowed once the contract is signed.
- For 5 working days (Uganda public holidays excluded, `sacco_public_holidays`), the borrower may cancel by returning
  the principal. There is no interest, and a fee of at most 2 % (default 0).
- A product may set `hold_disbursement_until_cooling_off_ends`.
- *Why:*
  - members borrow for urgent needs, and SI 108 reg. 11 grants a cancellation right, not a waiting period;
  - the hold option serves cautious boards.
- **Alternatives:** always hold for 5 working days (members would go to money lenders).

**AK-27: Repayment allocation order.**

- Default: penalty → fees → interest → principal. Configurable per SACCO.
- Overpayment goes to the member's voluntary savings, or to 2150 if they have none.
- *Why:* common practice, and the MobiSave port (01 §8.3).
- **Alternatives:** principal first (reduces the SACCO's income recognition and confuses arrears).

**AK-10: Where a loan is paid out is the SACCO's choice.**

- A product lists its `allowed_channels`: the member's savings account, cash, bank, or MoMo (P3, when payouts are
  live).
- The officer picks one per loan.
- *Why:*
  - VSLAs pay cash;
  - staff SACCOs credit savings;
  - PDM prefers direct or MoMo.
- MobiSave's always-to-wallet default is dropped (01 §8.3).
- **Alternatives:** a single fixed channel.

**AK-48: Guarantor exclusions.**

- Officers (board, supervisory board), employees and loans committee members may not guarantee (Coop s.55AP(3)).
- Every guarantor gets a written notice of the amount and nature of the liability and gives written or electronic
  consent (SI 108 reg. 10).
- *Why:* the law.

**AK-49: Guarantor defaults.**

- 2 guarantors for any part of a loan above the borrower's free savings.
- Each guarantor's total exposure is at most their free savings, which are held as a lien.
- At most 3 active guarantees per member.
- All of this is configurable per product.
- *Why:* practice (02 §7.3), with the s.55AK first charge making free savings the natural cap.

**AK-50: Loan multiple.**

- Default 3 × free savings, **excluding shares** (shares cannot be security, Tier 4 s.48).
- First loan after 90 days of membership.
- *Why:* VSLA and SACCO norms (02 §7.3).

**AK-43: The employee-loan conflict between Coop s.55AP(2) and SI 51 reg. 38 is a setting.**

- `employee_loans_policy` takes `salary_advance_only` (the default, the stricter rule) or `committee`.
- *Why:* the two laws conflict (02 §1.5).

**AK-30: Withdrawals are blocked while the member is in arrears, or a loan they guarantee is in arrears.**

- An approval with a reason can override it.
- *Why:* Coop s.55AK first charge.

---

## Shares, savings, year-end

**AK-29: Shares.**

- **Office mode:**
  - shares are permanent equity and never withdrawable;
  - no member may hold more than one third of share capital (checked under lock);
  - exit is by transfer, or by Share Transfer Fund buy-back (P4).
- **Group mode:** VSLA shares are paid out at share-out.
- *Why:* Tier 4 s.48, SI 51 reg. 32; VSLA methodology.

**AK-42: Dormancy follows the stricter Tier 4 rule by default.**

- 24 months → register and notice;
- 3 years → advertise;
- 5 years → transfer to the regulator;
- no maintenance fees.
- *Why:* the Tier 4 Act and the Cooperative Act conflict, and research advises the stricter rule for licensed SACCOs
  (02 §1.5).

**AK-18: Year-end defaults.**

- **Dividend:**
  - capped at **10 %** of paid-up shares;
  - allowed only if core capital ≥ 10 % of assets after the dividend;
  - only with a "Registrar consent obtained" upload.
- **Reserve:** **at least 10 %** of the surplus (editable upward only).
- **Other appropriations:**
  - NCEF 1 %;
  - an education budget of 5 %;
  - STF ≤ 5 % of share capital.
- *Why:* Tier 4 s.52, Coop s.46–49, Regs 1992 reg. 40.
- **Alternatives:** free entry (unlawful dividends are a known failure).

**AK-41: The financial year is 1 January to 31 December.**

- Group mode uses cycles, each ending in a share-out.
- *Why:* Tier 4 s.45; VSLA practice.

**AK-14: The regulator's identity is configuration, never code.**

- `config('sacco.regulator')` sets the platform default (UMRA). `sacco_settings` holds a per-SACCO override.
- "Regulated by {regulator}" prints only when a licence number is set.
- *Why:* UMRA may be dissolved into MoFPED (02 §1.1). Unlicensed SACCOs must never claim regulation.

---

## Plans and pricing

**AK-15: A plan line, not a module dimension.**

- Akiba plans carry `features.line = 'akiba'` and `akiba-*` slugs.
- The new `PlanLine` helper filters the 7 plan-listing places.
- `limits.max_members` and `limits.max_sms_per_month` are enforced through `Quotas`.
- *Why:*
  - the smallest change that keeps the four apps' billing byte-identical;
  - roadmap 06 §3 asked for entitlements per module, and an exclusive module makes a line equivalent.
- **Alternatives:**
  - a `module` column on plans (a migration and every caller for no extra power);
  - separate tables like Ping Pin (a second billing system).

**AK-16: The Akiba trial is 30 days (the family's is 14).**

- *Why:* a SACCO onboards over one monthly cycle (one check-off round, one board meeting).

**AK-38: SMS is a plan allowance (`max_sms_per_month`, hard).**

- When it runs out, receipts fall back to in-app (the member app's e-receipt) and the officer's one-tap WhatsApp share.
- There is no per-SMS billing in v1.
- *Why:*
  - SMS cost is the most disputed cost in the market (02 §6);
  - SI 108 reg. 16 accepts an e-receipt.
- **Alternatives:**
  - unlimited SMS (unbounded cost);
  - prepaid SMS credits (a new billing ledger).

**The prices** are in [03 §5.2](03-proposal.md#52-the-rows):

| Plan | UGX a month | Members |
|---|---|---|
| Free | 0 | ≤ 30 |
| Starter | 50k | ≤ 150 |
| Standard | 100k | ≤ 600 |
| Compliance | 250k | ≤ 2,500 |
| Enterprise | 600k | unlimited |

- No setup fee. A year costs 10 months.
- They are proposals in the seeder and editable by the platform admin.

---

## Members, self-service, data protection

**AK-19: Members sign in to self-service with their own Sanctum tokens on `SaccoMember` records, not as staff users.**

- Sign-in is OTP by phone, then a membership is picked, then a PIN is set on the device.
- *Why:*
  - `admin_users.phone_e164` is globally unique;
  - one account belongs to one business (`TeamService::acceptExisting`), so a teacher with a Shelfie shop could
    never be a member of their SACCO;
  - members must never gain staff permissions.
- **Alternatives:**
  - a staff role `member` (blocked by both rules);
  - a separate guard and users table (a second auth system).

**AK-31: NIN handling.**

- The NIN is the KYC key. It is stored encrypted (`DataCrypt`) with an HMAC hash for duplicate lookup.
- It is never in logs, audit diffs, sync payloads, exports (except audited regulator lists) or on phones.
- ID scans are optional and kept only with consent.
- National IDs, passports, ATM cards and passbooks are never collateral.
- *Why:* DPPA s.9 (financial data is special), SI 108 reg. 8.

**AK-47: The consent text is versioned and stored per member.**

- It covers the processing of financial data and the storage location (DPPA s.19), plus separate opt-ins for messages
  and the credit bureau.
- *Why:* hosting may be outside Uganda, and explicit consent is the lawful basis that does not depend on it.

**AK-37: In the Akiba flavor the app PIN is required, and sign-out wipes local `sacco_*` data.**

- *Why:* officers' phones carry member names, phones and balances for offline work, and SQLCipher is not yet built
  family-wide (roadmap 05 §8).

---

## Offline and channels

**AK-23: Offline rules.**

- **A phone may record offline:**
  - cash receipts (deposits, shares, fees, repayments, fines, fund contributions);
  - member registrations;
  - loan application drafts;
  - group meeting steps.
- **A phone may never do offline:** withdrawals, disbursements, approvals or votes, reversals, manual journals,
  non-cash channels, transfers, KYC verification or period actions. These are refused with `offline_not_allowed`.
- *Why:*
  - receipts only add money, and their uuid makes them idempotent;
  - anything that moves money out needs a live, locked balance and a second person.
- **Alternatives:** offline withdrawals with a limit (overdraw risk across phones).

**AK-32: In group mode, approving and paying out a loan at a meeting needs a moment of connection.**

- The meeting flow is otherwise fully offline.
- *Why:*
  - the owner's rule: never disburse or approve offline;
  - groups usually have a signal at some point in the meeting.
- The pilots in P5 will tell whether a "pending disbursement" holding account is needed. That would be a new entry.
- **Alternatives:** offline meeting loans (breaks AK-23).

**AK-17: No live mobile-money traffic from dev or tests.**

- Collections reuse Flutterwave with `SimulatedFlutterwave` and `FakeFlutterwaveService`.
- Disbursements go through the new `PayoutGateway` with the drivers `off` (the production default), `simulated` and
  `flutterwave`.
- `config:doctor` fails production on `simulated`.
- Live use needs the **owner's** keys.
- *Why:* the agent command's Never list.
- **Alternatives:** direct MTN or Airtel drivers first (each needs its own contract; the interface allows them later).

---

## Migration, demo, research

**AK-11: No MobiSave data migration in v1.**

- A generic CSV import of members and opening balances (P1.9, P1.13) lets any group, MobiSave included, start with
  correct balances.
- *Why:*
  - MobiSave's mirror rows and drift (W1, W12) mean a migration would rebuild journals from events and reconcile, not
    copy (01 §10 Q4);
  - that is a project of its own.

**AK-12: Port MobiSave's rules, not its code.**

- The KEEP and PORT list is 01 §8.3.
- *Why:* the code is on Laravel 8, single-entry and insecure (01 §8.2).

**AK-35: The Akiba demo is its own public demo company.**

- "Nalubaale Teachers' SACCO (Demo)" in office mode (P1, P2). "Twekembe Savings Group (Demo)" in group mode (P5).
- Both are built and healed by `demo:public` beside the family demo.
- All members are fictional. The names always end in "(Demo)".
- *Why:*
  - AK-04 forbids putting `sacco` in the family demo company;
  - the family demo must not change.

**AK-51: Uganda public holidays are a global table, `sacco_public_holidays`.**

- It is seeded per year and editable by the platform admin for movable feasts.
- *Why:* cooling-off and return due dates count working days.

---

## Decisions made while building P1

**AK-55: A control account's `control_of` names the account family; its lines carry the sub-ledger's `sub_type`.**

- `control_of` keeps the chart's finer names (`loan_principal`, `loan_interest`, `loan_penalty`, `loan_fees`, `fines`,
  `fund`), while a line names the row it belongs to (`loan`, `fine`, `fund_due`). The map is
  `SaccoLedgerAccount::CONTROLS`; `savings` and `shares` are the same on both sides.
- *Why:* ADR 0009 §4.2 says "sub_type = the account's control_of", but §3.2 gives the two different value lists. Four
  loan accounts share one loan row, so the line's type is the row's.

**AK-56: The rules CHECK constraints encode are also enforced by the application, on every database.**

- The local MAMP server is MySQL 5.7.44, which parses but ignores CHECK, so `SchemaIntegrity` marks them `app_only`
  there. `PostingService` validates every line before any write (one side, non-negative, the sub-ledger pair), and
  `SchemaIntegrity::STATUS_MODELS` guards the sacco status columns on save.
- The immutability triggers do install on 5.7. On MySQL 8.0.16+ the CHECKs are added as designed.

**AK-57: Member sub-ledgers plug into the ledger; month-close checks plug into the close.**

- `Ledger\SubLedger` (lock, project, balances) is implemented by the module that owns each table and registered in
  `Ledger\SubLedgers` at boot: shares (P1.11), savings (P1.12), loans (P2). `PostingService` refuses a line naming
  an unregistered type (`sub_ledger_unavailable`) and `LedgerVerifier` checks every registered one.
- `Ledger\CloseChecks` holds the other modules' conditions for closing a month (the approvals engine's "nothing
  pending from that month"; P2's accrual runs). `PeriodService::close` always runs the ledger check itself.
- *Why:* P1.5 builds the posting service before the savings, shares and loan tables exist (P1.11, P1.12, P2), and
  the ledger must not know their tables. The ledger tests prove the mechanism with a stand-in sub-ledger.
- `ChartOfAccounts::seed` also creates each account's empty balance row and the ledger head (the books exist the
  moment their chart does); `LedgerWritersTest` lists it beside `PostingService` for that reason.

**AK-58: Group mode posts only what its chart holds.**

- The group chart (the ADR 0009 §3 ✓ rows) has no 4420 Other fees, so `PostingRules::fee` is office-only
  (`not_in_group_mode`); a group charges fines (P5). Cash in group mode is 1020 Cash box, never 1010.
- System accounts get a `system_key` even where the ADR table gave only `control_of` (1320 `loans.interest`, 2110
  `savings.voluntary`, 2120 `savings.compulsory`, 2130 `savings.fixed`, 3100 `shares.capital`, 2510 `fund.social`,
  1440 `fines.receivable`, 2400 `payables`, 3600 `reserve.general`, 5400 `exp.governance`, 5740 `exp.software`), so
  code never finds an account by its code.
