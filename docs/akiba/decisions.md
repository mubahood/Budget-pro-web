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

**AK-59: The first run grows with the module; office mode needs a plan that sells it.**

- `SaccoSetupService::setup` (P1.7) writes the settings, seeds the chart and its head and opens three months. The
  founder's officer position (P1.8) and the default savings products "Ordinary savings" and "Compulsory savings"
  (P1.12) join the same transaction through `SaccoSetupService::extend()` when their tables exist: the roadmap puts
  both in P1.7, before their tables.
- Setting up in office mode needs the plan feature `sacco_office_mode` (`feature_not_in_plan`): Akiba Free is group
  mode only (03 §5.2). A SACCO that later falls to Free keeps working (ADR 0005).
- The books' currency is `sacco_settings.currency`, fixed at setup; `CompanyRules::currencyLocked` also locks the
  company's currency once a SACCO is set up.
- `config:doctor` gains `sacco_payouts` (never accepted), `sacco_nin_pepper` and `sacco_messaging` (one count of SACCO
  accounts, the doctor's only database read), checked in the kernel only.

**AK-60: Module roles and permissions are offered by module; an exclusive account keeps only its own.**

- `config/permissions.php` gains `module_permissions` (the 15 `sacco_*`) and `exclusive_keeps` (the account's own:
  `view_reports`, `resolve_conflicts`, `manage_team`, `manage_settings`, `billing`). `Permissions::availableFor` and
  `rolesFor` give a shop exactly its 18 permissions and six roles (its owner's `*` no longer means the SACCO's
  permissions too), and a SACCO the owner, the viewer (with `sacco_view`) and the eight `sacco_*` roles.
- Every SACCO role is an ordinary team login (`admin_users` + `company_members`), created by the Team screen's
  `TeamService::createMember` or an invite, so the web portal's and the API's existing sign-ins accept it.
  Positions (`sacco_officers`) are on top, never instead.
- The founder is seated as chairperson (or treasurer) at setup. `SaccoAccess::refuseSyncOp` is wired into
  `SyncApplier` now; the sacco tables join `SyncRegistry` in P1.16.
- `TeamRules::editableRoles()` without a company still answers the family's roles; callers pass the company.

**AK-61: Members sign in to the web with a member sign-in of their own, one per membership.**

- `sacco_member_logins` (company, member, email, phone, password hash, lockout) beside the staff `admin_users`.
  An officer with `sacco_members` opens it (`Members\MemberLogin::enable`); the member signs in with email (or
  phone) and password, or with a code sent by email (`OtpService`, keyed `sacco:{address}` so it never collides
  with the same address's staff code; phone codes only when `OTP_PHONE_CODES` is on). Five wrong passwords lock
  it for 15 minutes; an unknown address gets the same answer as a known one.
- One person in two SACCOs has two member sign-ins (the same email is fine): a sign-in answers every membership it
  opens and the person picks one. The one-business-per-account rule for staff logins is untouched: a member is
  never an `admin_users` row and never holds a staff permission.
- The portal keeps `(company_id, member_id)` in its own session and calls `MemberLogin::resolve()` on every request
  (a disabled sign-in, a member who is no longer active, or another SACCO ends the session) and `MemberLogin::owns()`
  for every record it shows. The API's member tokens (P3.7, §6.4) will sit on the same table.
- *Why:* the owner wants every role, members included, on the web portal now; AK-19's reasons (unique staff phones,
  one business per staff account, no staff permissions for members) rule out making members staff users.
- **Also in P1.9:** `NumberSequencer` kind `mem` is numbered for life (`M-000123`, no year); `AuditLogger` gains
  `auditSensitive()`, so the NIN columns are never logged; `DuplicateService` lists members with the same phone
  and refuses to merge them there (`merge_elsewhere`): member merges move money by journal (ADR 0009).

**AK-62: P1.10 ships seven executors; `withdrawal` and `product_terms_change` come with savings (P1.12).**

- Executors registered in `SaccoModule::EXECUTORS`: `reversal`, `manual_journal`, `period_close`, `period_reopen`,
  `ledger_account_create`, `member_kyc`, `held_receipt_allocation`. `withdrawal` needs `sacco_transactions` and the
  savings account's `available()`, and `product_terms_change` needs `sacco_savings_products`; both tables are P1.12's,
  so their executors register there through `SaccoApprovals::register()`.
- If an approved request cannot execute (the money moved, an account was switched off), it stays `approved` with
  `last_error` and runs again with `SaccoApprovals::execute()`: the approval is not lost (04 §5.2 step 3).
- Requests still open from a month block its close (`approvals_pending`, a `CloseChecks` entry); an expired request
  blocks nothing and never executes. `sacco:approvals-expire` marks them hourly.
- The system may ask without an officer as maker only for `held_receipt_allocation` (`SaccoApprovals::SYSTEM_ACTIONS`).

**AK-63: Savings (P1.12) ships before shares (P1.11); the money document is one table for every kind.**

- P1.12 is committed first so the loans work (P2) can disburse to savings and size loans on savings. Shares
  (P1.11) plug into the same `SaccoTransactions::record()` through `SaccoTransactions::recorder()`.
- `sacco_savings_products` keeps the sync column `version`, so its terms counter is `terms_version`. Terms never
  change in place: `product_terms_change` (maker sacco_settings, checker sacco_approve) writes a version; a rate
  cut takes effect 30 days after approval at the earliest, and `sacco:savings-terms` (daily 00:20) puts due versions
  in force. `notice_days` is at most 7: a withdrawal on a notice product always goes to approval and is paid once
  the notice has run from its request, and an approval lives 7 days.
- Every money path takes the SACCO's ledger head lock first, then account rows in ascending id, the order
  PostingService uses: no deadlocks, and the available balance is read with locking reads under it.
- The savings sub-ledger refuses any line that would take an account below zero (`insufficient_balance`), so not
  even a manual journal or a reversal can overdraw a member.
- A member's receipt is reversed as a document (`reversal` with `transaction_id`): the mirror entry plus a
  `reversal` row (`reverses_id` unique). Reversing that receipt's journal entry directly is refused
  (`reverse_the_receipt`), so the receipt never reads posted after its money went back.
- A deposit by the member wakes a dormant account; withdrawing from a dormant account always needs approval.
  Deposits still come into a frozen account; nothing goes out of it.
- An approval whose request is rejected, cancelled or expires closes its waiting document (`ClosesSubject`).
- Withdrawal approval reasons are open to other modules (`SaccoTransactions::approvalCheck`, P2: arrears).
- Sync (per the app built against 04): phones name accounts and members by server id (`savings_account_id`,
  `share_account_id`, `member_id`, or `member_uuid` for a member registered offline), send their own `receipt_no`
  (kept as `device_receipt_no`) and `amount_minor` as an integer; products and accounts come down as pull-only
  snapshots. The tables still carry sync columns, for the server's own uuids.
- Explicit dates: `record(..., opts: ['entry_date' => 'Y-m-d'])` posts history (opening balances, the demo) into
  that day's month while it is open; the value date is never after the entry date, and nothing posts in the future.

**AK-64: Shares (P1.11): the cap from the third shareholder, measured in whole basis points; shares bought by amount.**

- The one-third rule (Tier 4 s.48, `max_holding_bp` 3333) is arithmetically impossible with one or two
  shareholders, so it applies once three members hold shares. A holding is compared in whole basis points
  (`intdiv(shares × 10000, total)`), so exactly a third (3333) is allowed. Checked under the ledger head lock with
  locking reads: two purchases racing at the cap let one through (ConcurrentWithdrawalsTest).
- A purchase sends `amount_minor` (the phone) or `shares_count` (the counter); shares = amount ÷ par. A remainder is
  `not_whole_shares` online and a held receipt offline (2150). A held receipt allocated to a share account buys whole
  shares within the cap (`SaccoTransactions::onAllocate`).
- A transfer (`share_transfer` approval: maker sacco_teller, checker sacco_approve) moves the count and the shares'
  part of what was paid for them (half up; all of it when every share moves); the receiver stays within the cap.
- The entrance fee is exactly the setting's amount, paid once (a reversed fee may be paid again). In office mode an
  applicant becomes active only with the fee paid and `min_shares` held; two or more nominees need two or more shares.
- Shares are never withdrawn in office mode: there is no method or route. Leaving members are paid by the exit
  settlement (P2) or sell to another member.
- Offline hold reasons widen to the refusal that caused them (`not_whole_shares`, `share_cap_exceeded`,
  `entrance_fee_paid`, …), so the officer allocating a held receipt sees why.

---

## Decisions made while building P2

**AK-80: Loans repay weekly, fortnightly or monthly until group meetings exist.**

- The `meeting` frequency (a group's meeting cycle) arrives with group meetings in P5; until then `LoanTerms` refuses
  it with `frequency_not_available`, and a group lends on a calendar frequency.
- A flat line's interest is the original principal × the periodic rate, rounded half-up once per line (04 §5.3:
  "per instalment"), the same rounding as a reducing line, so reducing balance never costs more than flat, to the unit.
- The 100 % a year cost ceiling is measured on the nominal year the rate is quoted on (12 months or 52 weeks = 365
  days), rounded up, so a short February never makes a lawful product look unlawful.
- *Why:* the meeting calendar is P5's table; a cap must never be met or missed by rounding.

**AK-81: The PDM preset's penalty is the legal maximum, not the handbook's 0.5 % a month.**

- The Parish Revolving Fund lends at 6 % a year, 0.5 % a month, so SI 108 reg. 4(5) caps its penalty at half of
  that: 0.25 % a month (25 bp). The handbook's 0.5 % a month surcharge is above the ceiling for a SACCO, so the preset
  carries 25 bp, and a SACCO cannot set more. Emyooga (8 % a year, 66 bp a month) carries 33 bp.
- *Why:* the regulation binds every Tier 4 institution; the handbook predates it.

**AK-82: The single-borrower limit of a young SACCO is 10 % of its assets.**

- Coop s.55AN(3) is the smaller of 10 % of total assets and 25 % of institutional capital. A SACCO with no reserves,
  retained earnings or surplus yet has no institutional capital, which would forbid every loan; until it has some,
  the limit is 10 % of total assets (net of the loss allowance). Institutional capital is equity less share capital
  and opening-balance equity, plus this year's income less expenses.
- Liquidity (cash, tills, lockbox, petty cash, bank and MoMo float; not collections in transit) is shown at approval
  and refused at payout; the single-borrower limit is refused at approval.
- *Why:* a lawful reading that lets a new SACCO lend at all; the committee sees both figures.

**AK-83: A committee decides by majority of the members who may vote; the request records the carrying vote.**

- Every decision is one `loan_decision` request asked by the appraiser (maker ≠ checker). Routes: `delegated` (an
  officer with `sacco_approve` whose position's `approval_limit_minor` covers the amount; never an insider's loan),
  `committee` (the loans committee) and `appeal` (the board: chairperson, vice chair, board members; once).
- The count is taken at each vote: the deciding body's seats with a login, less the applicant's own seat, the
  appraiser's and anyone who recused. A majority of those approves; once a majority can no longer be reached, the
  loan is rejected. Votes are kept per officer per round in `sacco_loan_votes`, final; the request needs one checker,
  the vote that carried it, cast by `Loans\Committee` only (`committee_vote_only` on the generic approvals screen).
- `SaccoApprovals` gains `Approvals\ChecksChecker`: an executor whose checker depends on the request is asked before a
  checker's decision is recorded.
- An employee (a member seated as manager or teller) is routed like an insider when the policy is `committee`; with
  `salary_advance_only` only a salary-advance product is allowed (AK-43).
- *Why:* recusals and absences change the count after the request is made, and `required_approvals` is frozen.

**AK-84: A guarantee's lien sits on one savings account, and the borrower's own savings are not liened.**

- Consent places the whole guaranteed amount on the guarantor's savings account with the most free money (it must
  cover it), so a recovery debits one account. Capacity is free savings (balances less liens) less the guarantees
  still waiting for consent; at most `max_guarantees_per_guarantor` (3) live guarantees.
- The part guaranteed is the loan above the borrower's own free savings, times the product's cover. The borrower's
  savings stay unliened: the s.55AK first charge and the withdrawal block while in arrears (AK-30) protect them.
- The consent link carries an opaque token (hashed at rest, 7 days, spent on use); the consent page asks for a fresh
  code sent to the guarantor (OtpService, purpose `verify`, keyed `sacco_guarantee:{id}`).
- *Why:* one account per lien keeps recovery and release exact; locking the borrower's own savings would freeze
  most of a typical borrower's money for the whole term.

**AK-65: Opening balances (P1.13) come in two files through one approval each; amounts in major units.**

- `members` (member_no, shares as a count, `savings_{PRODUCT CODE}` per product, `loan_principal`) and `ledger`
  (account_code, debit, credit, memo). Amounts are typed as people type them ("1250000", "1250.50") and read
  exactly with `Amount::fromMajor`; shares are counted and valued at par.
- `opening_balances` (maker sacco_accounts, checker sacco_approve): `prepare` re-reads the file and freezes the
  parsed rows, so a checker approves exactly what posts; a file with any bad row is refused whole. Each row posts
  one `opening_balance` entry against 3900 with the key `open:{approval uuid}:{row}`, dated `as_of` (an open
  month: entry and value date both), opening savings and share accounts that are missing. The one-third share cap
  is not applied to history.
- Control accounts (savings, shares, loans) come only from member rows; the ledger file brings everything else.
  `OpeningBalances::status()` gives 3900's balance and each file's part of it; the verifier warns until it is zero.
- `loan_principal` belongs to the loans module: it registers the column with `OpeningBalances::memberColumn()`
  (a schedule-less opening loan); until then a non-zero value is refused (`loans_later`).
- `PostingRules::opening()` builds these drafts; `Ledger\TrialBalance` (between dates, or a closed month's
  snapshot) and `Ledger\AccountStatement` (a ledger account, or one member account) read the journal lines.

**AK-66: The Akiba API (P1.15) lives in routes/api_sacco.php; members get tokens of their own now.**

- `routes/api_sacco.php` is required from `routes/api.php` inside the v1 group: one staff group
  (`auth:sanctum, api.tenant, api.module:sacco, api.subscription, api.perm, api.idempotent`), the public
  `sacco/member-auth/*` (edition:saas, throttle 10/min) and `sacco/me/*`. Loans (P2) keep their routes apart.
- Refusals keep the service's code: `forbidden` → 403, anything not in this SACCO → 404 (so every `{id}` route
  answers 404 to another tenant), every other rule → 422. Writes that need a second officer answer 202 with the
  request (`summary`, `is_mine`, `requested_by_name`, the frozen payload). `POST sacco/approvals/{id}/execute`
  (sacco_approve) runs an approved request again after a refusal.
- Nominations go through `POST sacco/members/{id}/relations` with `role: nominee` (the whole nomination at once);
  savings terms through `PUT sacco/savings-products/{id}` (a `product_terms_change` request, 202).
- Member mode, brought forward from P3 for the app: `member-auth/otp` (an email code, the answer the same for an
  unknown address) → `verify` (the memberships and a 10-minute ticket) → `token` (one membership, 30 days; or
  email + password). The token is a `personal_access_tokens` row on `sacco_member_logins` with the ability
  `sacco:member`; Sanctum's staff guard never accepts it (no HasApiTokens), and `SaccoMemberAuth` accepts nothing
  else, re-checking the membership on every request. `me`, `me/accounts`, `me/statement`, `me/receipts/{id}`,
  `me/logout` answer the member's own records only (MemberLogin::owns).

**AK-85: Loan posting rules live in `Ledger\LoanPostingRules`, beside the P1 `PostingRules`.**

- Disbursement, interest due, penalty, repayment (with suspense recognised on receipt and the overpayment to savings
  or 2150), cooling-off cancellation, provision, write-off, recovery and the MoMo payout's settlement each build their
  draft there and nowhere else. A loan repayment is a `loan_repayment` receipt through `SaccoTransactions` (its
  recorder hook), so receipts, idempotency, the ledger lock and sync are the P1 ones.
- *Why:* one place per family of rules, and no edit races on the P1 file while P1 and P2 are built side by side.

**AK-86: MoMo payouts have drivers `off` and `simulated` only in this phase.**

- `Payouts\PayoutGateway`, `SimulatedPayouts` and `PayoutService` (sent → settled or reversed, the 24-hour poll) are
  built and tested; the Flutterwave transfers driver waits for the owner's keys (P3). With `off` a MoMo payout is
  refused with `payouts_off` and the other channels are used.
- *Why:* AK-17: no live mobile-money traffic from dev or tests.

**AK-87: Interest is charged on the schedule's due dates only; after the last one, overdue principal carries the penalty.**

- Each due date charges the principal owed at the start of its period (by value date) × the periodic rate (AK-39). A
  loan still owing after maturity charges no more interest, only the penalty on overdue principal (≤ half the
  monthly rate, SI 108 reg. 4(5)), and everything charged once it is non-performing stops at the NPL cap.
- The daily run works on the days that matter (due dates, each 1st, today), all computed from repayments by value
  date, so a catch-up gives the same figures as nightly runs; reminders go out only for today. A loan that became
  non-performing is dated to the day its oldest unpaid instalment passed 60 days.
- A month closes only when every open loan has been brought past its end (`loan_runs_pending`) and, in office mode,
  its provisioning has posted (`provisioning_pending`).
- *Why:* member-friendly and simple to explain; the penalty already compensates late money; catch-ups must not drift.

**AK-88: A write-off takes two officers in the chair's, vice chair's or treasurer's office; a guarantor recovery takes a second officer.**

- 04 §7 asks for "chair and treasurer positions". The engine checks offices per action, not per approval, so the rule
  is: two different checkers with `sacco_approve`, each holding one of those offices (the founder chairperson counts).
- Taking a guarantor's held savings for a defaulted loan (`guarantor_recovery`) is not in the §7 list; it moves a
  member's savings out, so it is maker-checker too (`sacco_loans` asks, `sacco_approve` checks). Only a loan in
  arrears or written off; at most what is still held from that guarantor and what the loan owes.
- Write-off: Cr 1300/1320/1330/1340 what is left, Dr 1390 as far as the allowance holds and 5200 for the rest; the
  suspense memo is dropped into the loan's history. Recoveries later (borrower or guarantor) are Dr cash/bank/the
  guarantor's savings, Cr 4500, receipted, and reversible through the receipt.
- Provisioning runs on each 1st for the month before (`sacco_provision_runs`, key `prov:{yyyymm}`); office mode only.

**AK-67: Sync for Akiba (P1.16), as the app was built against 04.**

- `SyncRegistry`: `sacco_members` (master; the fields of 04 §9.3 in stored form, phones in E.164, consent as a
  timestamp; never a NIN, a member number, a status or a KYC result), `sacco_transactions` (event, handler
  `sacco_transaction`), and pull-only snapshots `sacco_savings_products`, `sacco_savings_accounts`,
  `sacco_share_accounts` (with `member_uuid`). A receipt names accounts and members by server id, or a member the
  phone registered by `member_uuid`; the phone's own `receipt_no` is kept as `device_receipt_no`.
- A member pushed from a phone is numbered by the server (`SaccoMember::creating`), counted against the plan,
  active in a group and an applicant in an office, and refused without the consent timestamp (`consent_required`).
  Registering members from a phone needs `sacco_members` (a teller does not have it).
- `SyncSerializer` drops every attribute a model hides, so the NIN and its hash never reach a phone.
- A receipt that cannot reach its account (unknown, closed, a member who left, not whole shares, over the offline
  limit…) posts to 2150 as `held` with a conflict-inbox item (`held_receipt`); when the account is known, the
  system also asks for its `held_receipt_allocation`. Only `cash` deposits, share purchases, entrance fees and fees
  may come from a phone; everything else is `offline_not_allowed`.
- `sync:soak` stays the shop's soak; the SACCO soak (three phones, lost answers, out-of-order replays) is
  `SaccoSyncReplayTest`, and the shop soak still passes with the sacco tables registered.

**AK-68: Receipts and member messages (P1.17); Akiba Ledger never names the family.**

- `Messaging\SaccoMessages`: English and Kiswahili (Luganda falls back to English); each SMS form at most 160
  characters (free text is shortened, never a figure), a WhatsApp form beside it; signed with the SACCO's short
  name. `Messaging\SaccoNotices` sends every posted receipt (a `SaccoTransactions::afterPosted` hook) and the
  notice of a savings rate change through Messenger, after the commit. `receipts_channel`: `sms` only on a plan
  with `sacco_sms_receipts` and within the month's allowance (else WhatsApp), `whatsapp`, `app` (read in the app,
  nothing sent), `none`.
- `Messenger::send` gains the member branch (`meta.sacco_member_id` → `SaccoNotices::consentRefusal`): an opted-out
  member gets nothing (logged `opted_out`); reminders and offers also need `consent_messages_at`.
- `GET sacco/transactions/{id}/receipt?format=json|text|pdf`: a 32-column ASCII receipt for the Bluetooth printer,
  a small PDF, and the WhatsApp and SMS texts to share.
- The owner's rule (2026-10-21): nothing a SACCO's staff or members receive names Budget Pro or another app. So a
  SACCO's welcome says Akiba Ledger and its own first steps; its mail carries no family footer and defaults its
  subject to "Akiba Ledger"; members' sign-in codes go out in the SACCO's name (Akiba Ledger when one address opens
  several SACCOs), with no footer. The loans module's messages follow the same rule.

**AK-89: Akiba Ledger speaks only in the SACCO's name; its links open on the Akiba Ledger site.**

- The owner's rule: nothing a SACCO user, member or guarantor receives names another product of the family. Every
  loan text starts with the SACCO's short name; codes for guarantors come through `OtpService::request(..., $sender)`
  in the SACCO's name (not the platform's `app.name`); contracts and key facts name the SACCO and its regulator.
- Links (the guarantor's consent page `/sacco/consent/{token}`) use `config('sacco.public_url')`
  (`SACCO_PUBLIC_URL`, else `https://` + `APP_AKIBA_LEDGER_DOMAIN`), so no other product's domain appears. The token is
  32 random characters (hashed at rest), short enough that the guarantor's request fits one SMS.
- Loan messages use SaccoNotices' purpose families (`sacco_receipt`, `sacco_notice`: unless opted out;
  `sacco_reminder`: also needs consent) and the same channel rule (SMS within the plan's allowance, else WhatsApp).
  English only for now; Kiswahili joins when the loan texts enter `SaccoMessages`' catalogue.

**AK-90: The law's checks on loans apply only to a licensed SACCO; refusals speak plainly (the owner).**

- `LoanBooks::strict($companyId)` is true when the SACCO has an UMRA licence number (the same test as
  `SaccoRules::strict`). Only then are these refused: flat interest in office mode, interest and fees above 100 % a
  year, a penalty above half the monthly rate, a cooling-off under 5 working days or a cancellation fee above 2 %
  (SI 108 reg. 4, 9, 11); an ID, passport, ATM card, PIN, passbook or transfer deed as collateral and a sale before 60
  days after the demand (reg. 8); an officer, employee or board member as guarantor (Coop s.55AP(3)); an insider's
  loan decided within a delegated limit (SI 51 reg. 38); signing without a witness (reg. 5); a reschedule without the
  borrower's recorded consent (reg. 6); a write-off without a reason (SI 51 reg. 44); and the single-borrower limit
  (Coop s.55AN(3)).
- An unlicensed SACCO keeps its own rules: its products' terms, cooling-off and charges are what it sets, as group
  mode already could. What is never a law but arithmetic or the books stays for everyone: Σ principal = the loan,
  one open loan, the savings multiple, guarantee capacity, maker ≠ checker, the NPL cap on suspense, no early-
  repayment fee (Akiba never charges one), a receipt for every payment.
- Refusal messages never cite a law, regulation or decision number; the citations stay in the code's comments and
  these docs.

**AK-69: Legal checks apply to regulated SACCOs only; no age limits (the owner's instruction, 2026-10-21).**

- No age rule anywhere: a member of any age joins without a guardian, and any member may hold an office. A date of
  birth (and a guardian) stays optional information.
- `SaccoRules::strict($company)` is true only for a SACCO with a licence number. Only then: the member's data consent
  at registration (DPPA), two witnesses on a nomination and more than one share for more than one nominee (Regs 1992
  reg. 10), the one-third share cap (Tier 4 s.48), a second officer for any withdrawal from a dormant account (Tier 4
  s.53), and 30 days' notice before a savings rate cut (SI 51 reg. 33). Everyone else may proceed. The SACCO's own
  settings (entrance fee, minimum shares, withdrawal threshold) and the maker-checker rule apply to all.
- Refusals say what is needed in plain words and cite no law; the citations stay in code comments and these docs.
