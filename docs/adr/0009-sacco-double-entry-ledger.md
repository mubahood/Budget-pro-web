# 0009 — The sacco module keeps a true double-entry general ledger in integer minor units

Status: **Proposed** (2026-10-21), for Akiba Ledger ([docs/akiba](../akiba/README.md)). It **extends** ADR 0003 and
does not change it.

**Context.** A SACCO holds its members' money in trust, and Ugandan law expects books that tie out:

- a share and savings account per member, kept 10 years (SI 51 reg. 33);
- UMRA Forms 3–7, built from a balance sheet and an income statement;
- a reserve, a dividend and an appropriation waterfall at year-end.

What the family has today does not meet this:

- **ADR 0003** gives the shop a single-entry cash ledger (`financial_records`: Income or Expense, `source_type` and
  `source_id`, contra rows). There are no accounts, debits or credits.
- **Money columns** are `DECIMAL(20,2)`.
- **`App\Support\Money`** only formats, and it casts to float on the way.
- **`App\Support\Decimal`** is bcmath on 2-decimal strings.
- **MobiSave**, the owner's existing system, shows what goes wrong without double entry:
  - mirrored rows with inconsistent signs;
  - the group's money in a person's account;
  - varchar money with `(int)` casts;
  - rows edited and history rewritten ([01 §8.2](../akiba/01-mobisave-study.md#82-what-is-weak)).

**Decision.**

## 1. Scope

- The `sacco` module gets its **own general ledger**: tables `sacco_ledger_accounts`, `sacco_journal_entries`,
  `sacco_journal_lines`, `sacco_account_balances`, `sacco_ledger_heads`, `sacco_periods` and `sacco_period_balances`.
- It is per company (one SACCO = one company, `CompanyScope`).
- The shop, poultry, budget and finance modules keep ADR 0003 and `financial_records` **unchanged**.
- A SACCO company has no `finance` module (AK-04), so the two ledgers never describe the same money.
- Moving the shop onto this ledger would need its own ADR.

## 2. Money: `App\Support\Sacco\Amount` (new, immutable)

- **Storage:** integer **minor units** in `BIGINT` (signed for balances; `UNSIGNED` with `CHECK >= 0` for line sides),
  plus an ISO 4217 currency.
- **Minor-unit exponent:** from `App\Support\Sacco\Currency` (UGX 0, RWF 0, KES 2, TZS 2, USD 2…). This is the ISO
  exponent, not `Money::ZERO_DECIMAL`, which is a display rule for shelf prices.
- **Range:** a `BIGINT` holds ±9.2 × 10¹⁸ minor units, far beyond any SACCO.
- **The class:** `final class Amount implements JsonSerializable` with `readonly int $minor` and `readonly string $currency`.
  - Constructors:
    - `of(int, string)`;
    - `zero(string)`;
    - `fromMajor(string $decimal, string $currency)`, which parses a string and **refuses** floats, more decimals
      than the exponent allows, and non-numeric input.
  - Arithmetic:
    - `plus` and `minus` refuse a currency mismatch and integer overflow (checked with `intdiv` bounds);
    - `negate`, `abs`, `compare`, `equals`, `isZero`, `isPositive`, `isNegative`.
  - Rates and shares:
    - `times(Rate|string $ratio, Rounding $mode = HALF_UP)` computes with bcmath at scale 10 and rounds once;
    - `allocate(array $weights): Amount[]` uses the largest-remainder method, so the parts sum **exactly** to the
      whole.
  - Output:
    - `toMajorString()`;
    - `format(?int $companyId)` delegates to `Money::format` for display only;
    - `jsonSerialize()` returns `{minor, currency}`.
- **`App\Support\Sacco\Rate`:** an integer in basis points, with `monthly()`, `perPeriod(frequency)`,
  `halfOf()` (the penalty cap) and `percentString()`.
- **Namespace and compatibility:** it lives in `App\Support\Sacco` (the module's key) and not in `App\Support\Money`.
  - The four apps' `Money` and `Decimal` are untouched.
  - The roadmap's shop item "Money on bcmath" (02 §5) may later adopt `Amount` or converge with it through its own
    ADR.
- **One rounding rule** (AK-09): half-up to the minor unit, applied once per computed amount (each instalment's
  interest, each penalty, each fee, each dividend or share-out line). Distributions use `allocate()`, so totals tie
  to the pool.

## 3. Chart of accounts per SACCO

- **Seeding:** `ChartOfAccounts::seed($company, $mode)` creates the standard Ugandan SACCO chart from
  `database/data/sacco_chart.php`:
  - four-digit codes;
  - `type` and `normal_side`;
  - `control_of` for sub-ledger control accounts;
  - `system_key` for accounts the code posts to;
  - `umra_line` mapping to Schedule 4 forms.
- **Group mode** seeds the subset marked `group`.
- **Changes:** SACCOs may add accounts (maker-checker). `system_key` accounts cannot be deactivated, and no account
  is ever deleted.

The standard chart, with the lines Form 5 and Form 6 need ([02 §1.3](../akiba/02-uganda-research.md)):

| Code | Account | Type / normal | control_of / system_key | UMRA line | Group |
|---|---|---|---|---|---|
| 1000 | **Cash and equivalents** (header) | asset | | | ✓ |
| 1010 | Cash in hand: office (tills are children 1011…) | asset / Dr | `cash.office` | F5 1.1, F4 1.1 | |
| 1020 | Cash box (lockbox) | asset / Dr | `cash.box` | F5 1.1 | ✓ |
| 1030 | Petty cash | asset / Dr | `cash.petty` | F5 1.1 | |
| 1100 | Bank accounts (children per bank) | asset / Dr | `bank.main` | F5 1.2, F4 2.1 | ✓ |
| 1150 | Mobile money float (1151 MTN, 1152 Airtel) | asset / Dr | `momo.float` | F5 1.2, F4 (liquid, Tier 4 s.2) | ✓ |
| 1160 | Mobile money clearing (collections in transit) | asset / Dr | `momo.clearing` | F5 1.2 | |
| 1200 | Balances with other SACCOs and FIs | asset / Dr | | F4 3.1–3.2 | |
| 1250 | Government securities (1251 T-bills ≤ 91 days, 1252 T-bonds) | asset / Dr | | F5 3.1, F4 4.1–4.2 | |
| 1300 | Loans to members: principal | asset / Dr | control `loan_principal`, `loans.principal` | F5 4.1, F3 2.4 | ✓ |
| 1320 | Interest receivable on loans | asset / Dr | control `loan_interest` | F5 5.x | ✓ |
| 1330 | Penalty receivable | asset / Dr | control `loan_penalty` | F5 5.x | |
| 1340 | Loan fees receivable | asset / Dr | control `loan_fees` | F5 5.x | |
| 1390 | Allowance for loan losses (contra) | asset / **Cr** | `loans.allowance` | F5 4.2 | |
| 1410 | Payroll check-off receivable | asset / Dr | `checkoff.receivable` | F5 5.x | |
| 1420 | Staff advances | asset / Dr | | F5 5.x | |
| 1440 | Fines receivable | asset / Dr | control `fines` | F5 5.x | ✓ |
| 1500 | Prepayments | asset / Dr | | F5 2 | |
| 1600 | Other investments | asset / Dr | | F5 3.3 | |
| 1710 / 1720 / 1730 | Land and buildings / Furniture and equipment / Computers | asset / Dr | | F5 PPE, F2 1.6 | |
| 1790 | Accumulated depreciation (contra) | asset / Cr | | F5 PPE | |
| 1800 | Intangible assets | asset / Dr | | F5 | |
| 1900 | Other assets | asset / Dr | | F5 | |
| 2110 | Member savings: voluntary | liability / Cr | control `savings` | F5 savings deposits, F4 6.1 | ✓ (group savings) |
| 2120 | Member savings: compulsory (non-withdrawable) | liability / Cr | control `savings` | F5 non-withdrawable deposits | |
| 2130 | Fixed deposits | liability / Cr | control `savings` | F5 short-term deposits | |
| 2140 | Interest payable on savings | liability / Cr | `savings.interest_payable` | F4 6.1 | |
| 2150 | Unallocated receipts (suspense) | liability / Cr | `suspense.receipts` | F5 other liabilities | ✓ |
| 2160 | Payouts in transit | liability / Cr | `payouts.in_transit` | F5 other liabilities | |
| 2310 | External borrowings: MSC / Emyooga | liability / Cr | | F5 external borrowings | |
| 2320 | External borrowings: banks and others | liability / Cr | | F5 external borrowings | |
| 2330 | Parish Revolving Fund (PDM) | liability / Cr | | F5 external borrowings | |
| 2400 | Accounts payable and accruals | liability / Cr | | F5 | ✓ |
| 2410 | Tax payable | liability / Cr | | F5 | |
| 2420 | Dividends payable | liability / Cr | `dividends.payable` | F5 | |
| 2430 | Share-out payable | liability / Cr | `shareout.payable` | — | ✓ |
| 2440 | Cooperative Education Fund payable (1 %) | liability / Cr | `ncef.payable` | F5 | |
| 2510 | Social / welfare fund (children per fund) | liability / Cr | control `fund` | F5 other liabilities | ✓ |
| 2600 | Deferred grants | liability / Cr | | F5 | |
| 3100 | Share capital | equity / Cr | control `shares` | F5 share capital, F3 1.1.1 | ✓ (VSLA shares) |
| 3200 | Statutory reserve (≥ 10 %) | equity / Cr | `reserve.statutory` | F3 1.1.2 | |
| 3300 | Share transfer fund | equity / Cr | `stf` | F3 1.1.7 | |
| 3400 | Retained earnings: prior years | equity / Cr | `re.prior` | F3 1.1.3 | ✓ |
| 3410 | Current-year surplus (closing target) | equity / Cr | `re.current` | F3 1.1.4 (at 50 %) | ✓ (cycle surplus) |
| 3500 | Capital grants and donations | equity / Cr | | F3 1.1.5 | |
| 3600 | General and other reserves | equity / Cr | | F3 1.1.6 | |
| 3700 | Revaluation reserve | equity / Cr | | F5 | |
| 3800 | Proposed dividends | equity / Cr | | F5 | |
| 3900 | Opening balance equity (migration only; must net to 0) | equity / Cr | `opening` | — | ✓ |
| 4100 | Interest on loans | income / Cr | `inc.interest` | F6 2.1 | ✓ (service charge) |
| 4110 | Penalty interest | income / Cr | `inc.penalty` | F6 2.1 | |
| 4200 | Loan fees and commissions | income / Cr | `inc.loan_fees` | F6 2.2 | ✓ |
| 4300 / 4310 | Income from government securities / bank deposits | income / Cr | | F6 3.x | |
| 4400 | Membership and entrance fees | income / Cr | `inc.entrance` | F6 other operating | ✓ |
| 4410 | Fines | income / Cr | `inc.fines` | F6 other operating | ✓ |
| 4420 | Other fees (withdrawal, statement, passbook) | income / Cr | `inc.fees` | F6 other operating | |
| 4500 | Recoveries of written-off loans | income / Cr | `inc.recoveries` | F6 6.2 | |
| 4900 / 4950 | Other income / Grants for operations | income / Cr | | F6 9, 13.2 | |
| 5100 | Interest on member savings | expense / Dr | `exp.savings_interest` | F6 4.2 | |
| 5110 | Interest on external borrowing | expense / Dr | | F6 4.3 | |
| 5120 | Dividend expense (only if the auditor classifies shares as liabilities) | expense / Dr | | F6 4.4 | |
| 5200 | Provision for loan losses | expense / Dr | `exp.provision` | F6 6.1 | |
| 5300 | Personnel | expense / Dr | | F6 7.1 | |
| 5400 | Governance (board, AGM) | expense / Dr | | F6 7.2 | ✓ |
| 5500 | Marketing | expense / Dr | | F6 7.3 | |
| 5600 | Depreciation | expense / Dr | | F6 7.4 | |
| 5700 | Administration (5710 MoMo charges, 5720 bank charges, 5730 cash shortages, 5740 SMS and software) | expense / Dr | `exp.momo`, `exp.bank`, `exp.shortage` | F6 7.5 | ✓ |
| 5800 | Stabilisation fund and savings protection premium | expense / Dr | | F6 7.5 | |
| 5900 | Tax | expense / Dr | | F6 11 | |

## 4. Journal entries and the posting service

### 4.1 One writer

`App\Services\Sacco\Ledger\PostingService::post(JournalDraft $d): SaccoJournalEntry` is the **only** code that inserts
`sacco_journal_entries` or `sacco_journal_lines` or updates `sacco_account_balances` and the sub-ledger caches. A
static check test (`LedgerWritersTest`) greps `app/` and fails if any other class writes those tables.

### 4.2 Validation before any write

A violation throws `BusinessRuleException` and nothing is written.

- At least 2 lines.
- Each line has exactly one side greater than 0.
- **Σ debit_minor = Σ credit_minor** (as `Amount`, same currency = the SACCO's).
- Every account belongs to the company, is active and postable.
- A line on a control account has `sub_type` = the account's `control_of` and a `sub_id` that exists in this company
  and maps to **that** control account (e.g. the savings account's product → 2110). A line on a non-control account
  has no sub-ledger reference.
- `entry_date` is in an `open` period. `value_date` ≤ `entry_date`, and may be earlier: an offline receipt, or a
  correction for a closed period, which is posted today with the old value date and never into the closed period.
- `idempotency_key` is present.

### 4.3 The transaction

Under `DB::transaction`, in this order:

1. Look up `(company_id, idempotency_key)`.
   - If it exists with the same `payload_hash` → return it (**replay**).
   - With a different hash → `idempotency_conflict`.
2. `SELECT … FOR UPDATE` the company's `sacco_ledger_heads` row (created at setup). This serialises postings per
   SACCO, which is fine at SACCO volumes and gives a total order for the hash chain.
3. Lock the affected `sacco_account_balances` rows, then the sub-ledger rows (savings, share accounts, loans) in
   **ascending id order** (deadlock avoidance).
4. Run the draft's guard closures against the locked rows (available balance, share cap, loan outstanding, NPL cap).
5. Insert the entry and its lines. `entry_no` comes from `NumberSequencer` kind `je`.
6. Update the account balances (debit and credit totals, normal-side balance), then the sub-ledger caches. The
   projector for each `sub_type` adds the line's signed amount.
7. Compute `hash = sha256(prev_hash ‖ canonical_json(entry, lines))` and update the head.
8. Commit. Receipts and notices are dispatched `afterCommit`.

### 4.4 Idempotency keys

They are deterministic per business event:

| Event | Key |
|---|---|
| Transaction document | `txn:{uuid}` |
| Approval execution | `apr:{approval_uuid}` |
| Interest due | `int:{loan_id}:{schedule_version}:{installment}` |
| Penalty | `pen:{loan_id}:{yyyymm}` |
| Provisioning | `prov:{yyyymm}` |
| Savings interest | `sint:{run_id}` |
| MoMo collection | `momo:{tx_ref}` |
| Payouts | `payout:{id}:sent`, `payout:{id}:settled`, `payout:{id}:failed` |
| Reversal | `rev:{entry_id}` (with `UNIQUE(reverses_entry_id)` behind it) |
| Year-end | `close:{year}`, `approp:{year}` |
| Share-out | `shareout:{id}` |
| Opening balances | `open:{import_id}:{row}` |

Retries from phones, webhooks, polls, cron catch-up and double clicks can therefore never double-post.

## 5. Posted rows are never edited

- **Model guards:** `SaccoJournalEntry` and `SaccoJournalLine` throw on `updating` and `deleting`, as do
  `SaccoPeriodBalance` rows of a closed period. There is no API route, Livewire action or admin form that updates
  them.
- **Corrections** are **reversal entries**:
  - `ReversalService::reverse($entryId, $reason, $approvalId)` posts the mirror (debits ↔ credits, same sub-ledger
    references) with `kind = reversal`, `reverses_entry_id = original`, entry date today and value date today;
  - `UNIQUE(reverses_entry_id)` means an entry is reversed at most once;
  - a reversal of a reversal is refused (repost the correct entry instead).
- **Documents:** `sacco_transactions` follow the same rule. A new row with `kind = reversal` and a unique
  `reverses_id`, like `contribution_payments`.
- **Database level:**
  - CHECK constraints on lines (one side, non-negative, sub-ledger pairing) are added through `SchemaIntegrity::CHECKS`
    (MySQL 8.0.16+ / MariaDB 10.2+);
  - unique keys on idempotency key, entry number and reversal;
  - foreign keys `ON DELETE RESTRICT` from lines to entries and accounts, and from entries to periods.
- **Triggers:** the migration also **tries** to install `BEFORE UPDATE` and `BEFORE DELETE` triggers on the two
  journal tables that `SIGNAL SQLSTATE '45000'`. If the host refuses trigger creation (common on shared hosting with
  binary logging), the migration logs it, and `sacco:ledger-verify` reports `triggers: absent` on System health.
  - The model guards, the tests and the hash chain remain the guarantee.
  - The triggers are a bonus, never relied on.

## 6. Tamper evidence

- **The chain:**
  - each entry stores `prev_hash` and `hash` (SHA-256 over a canonical JSON of the entry and its ordered lines plus
    `prev_hash`);
  - the chain is per company and anchored in `sacco_ledger_heads`;
  - `LedgerVerifier` recomputes it.
- **What is detected:** an edit made directly in the database (by anyone with DB access) breaks every later hash. The
  first broken entry is reported to the owner and the platform.
- **What it is not:** this does not stop a privileged attacker from rewriting the whole chain. Periodic **anchors**
  cover that: at each period close, the head hash is written into `sacco_period_balances` and printed on the closing
  report, which the chair signs.

## 7. Balance caches and sub-ledgers

- **Account caches:** `sacco_account_balances` per account, updated only in step 6 under the row lock (the ADR 0002
  pattern: events are the truth, counters are caches).
- **Sub-ledger caches:**
  - `sacco_savings_accounts.balance_minor`;
  - `sacco_share_accounts.balance_minor` (and `shares_count`, moved by share transactions);
  - `sacco_loans.principal_outstanding_minor`, `interest_due_minor`, `penalty_due_minor`, `fees_due_minor`;
  - `sacco_fund_dues` and `sacco_fines` (P5).
  - Each is maintained by its projector, in the same transaction.
- **Member sub-ledgers reconcile to control accounts.** For each control account C:

  `balance(C) = Σ over sub-accounts S mapped to C of balance(S)`

  The journal lines are themselves the sub-ledger: a member's statement is `lines WHERE sub_type/sub_id`, and the
  cache is a sum of those lines.
- **`LedgerVerifier` checks every night, at period close and on demand:**
  1. Σ debits = Σ credits over all lines, and per entry.
  2. Each account cache = the sum of its lines.
  3. Each sub-ledger cache = the sum of its lines.
  4. Each control account = the sum of its sub-ledgers.
  5. The hash chain is intact.
  6. 3900 Opening balance equity nets to zero once the opening import is complete.
  7. 2150 Unallocated receipts has no item older than 30 days (a warning).
- **Failures:** any failure goes to `Notifier` (owner and platform), is shown on System health, and **blocks period
  close**.

## 8. Periods and closing

- **Periods:** `sacco_periods` are calendar months, created ahead by `sacco:month-end`.
- **Close** (maker-checker) requires:
  - `LedgerVerifier` green;
  - no pending approvals dated in the period;
  - that month's accrual, penalty and provisioning runs done.

  It then writes `sacco_period_balances` (opening, debits, credits, closing per account, with the head hash) and sets
  `closed`. Nothing more can be posted with that entry date.
- **Reopen:** only the latest closed period, with maker-checker, a chair or auditor position, and a reason. It is
  audited and shown on the next closing report.
- **Fiscal year** (Tier 4 s.45: calendar year by default). Year-end close is entry `close:{year}`:
  - every income and expense account → 3410 current-year surplus;
  - after the AGM, the appropriation `approp:{year}` moves 3410 → reserve (≥ 10 %), NCEF payable (1 %), STF,
    other reserves and dividends payable, with the gates in AK-18.
  - In group mode, the cycle close does the same into 3410, then the share-out.

## 9. Tests that hold this ADR

- **Property:** `LedgerPropertyTest` uses seeded random sequences of 500 postings across deposits, withdrawals, shares,
  loans, accruals, reversals and period closes. It asserts every `LedgerVerifier` check after each step, plus
  `allocate()` exactness.
- **Idempotency:**
  - every key family above is posted twice, sequentially and from two processes;
  - a different payload under the same key → `idempotency_conflict`.
- **Concurrency** (`pcntl_fork`, no wrapping transaction, the `ConcurrentPledgePaymentsTest` pattern):
  - two withdrawals of 60 % of one balance → exactly one posts;
  - two postings with the same key → one entry;
  - a repayment racing the interest accrual → the totals are correct either way.
- **Immutability:**
  - updating or deleting an entry or line through the model throws;
  - with the triggers present, a raw `UPDATE` fails;
  - after a raw edit with the triggers absent, `LedgerVerifier` reports the hash break.
- **Static:** `LedgerWritersTest`.
- **Tenancy:** another company's accounts can never be posted to (`account_not_found`), and the verifier runs per
  company.

**Consequences.**

- **Every SACCO money path is a posting rule plus one service call.** Reports, statements and UMRA forms are queries
  over lines and period snapshots, never over stored totals.
- **The four apps see no change:** no shared table is altered except additive columns on `momo_requests`.
- **Postings are serialised per SACCO** by the ledger-head lock. If that ever becomes a bottleneck (thousands of
  postings a minute in one SACCO), a later ADR can move the hash chain to an asynchronous sealer. The rest stands.
- **Mistakes are visible forever** as reversals. Statements show them, which is what members and auditors expect.
- **A second money abstraction (`Amount`) exists beside `Money` and `Decimal`.** It is confined to `sacco` until an
  ADR merges them.
- **Relation to the other ADRs:**
  - **0001:** offline cash receipts are sync events, posted by the handler.
  - **0002:** caches under locks, reversals not edits.
  - **0004:** permissions plus officer positions.
  - **0005:** never a lockout; money is never refused for a plan limit.
  - **0007:** members created offline are merged by transfer journals, never by editing lines.
  - **0008:** cron jobs, database queue, no new infrastructure.
