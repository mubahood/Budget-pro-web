# 04. Akiba Ledger: architecture

Status: design, 2026-10-21. This document is implementable as written. Where it says "reuse X", X exists today; where
it says "new", nothing like it exists. The ledger itself is specified in
[ADR 0009](../adr/0009-sacco-double-entry-ledger.md); this document refers to it rather than repeating it. Decision
ids `AK-nn` are in [decisions.md](decisions.md).

Abbreviations: **K** = kernel `/Applications/MAMP/htdocs/budget-pro`, **P** = portal
`/Applications/MAMP/htdocs/budget-pro-new`, **A** = app `/Users/mac/Desktop/github/budget-pro-mobo`.

---

## 0. What was verified

These claims from the reuse inventory were checked in the code on 2026-10-21. The design below depends on them.

| Claim | Where | Holds? |
|---|---|---|
| `App\Support\Money` is a formatter only (`symbol`, `decimals`, `format` with `number_format((float) …)`) | K `app/Support/Money.php` | Yes. It casts to float, so it must never touch Akiba amounts beyond display. |
| `App\Support\Decimal` is bcmath on strings with `SCALE = 2` | K `app/Support/Decimal.php` | Yes |
| `EnsureApiModule::MODULES = ['shop','finance','budget','poultry','pingpin']` plus `CORE` | K `app/Http/Middleware/EnsureApiModule.php:36` | Yes |
| `CompanyRules::CORE_MODULES = ['shop','poultry','budget']` (at least one is required) | K `app/Support/Rules/CompanyRules.php:37` | Yes |
| `Quotas::KEYS` covers users, products, sales, storage, devices and locations; `SOFT = ['sales']` | K `app/Services/Billing/Quotas.php:20-23` | Yes |
| Plans have no module or line dimension. They are listed in `Admin\BillingController`, `Api\V1\BillingController`, `Quotas::planThatFits`, `Lifecycle::freePlan` (slug `free`), `RegistrationService` (`saas.default_plan`), and the classic `RecordPayment` and `CompPlan` | K `grep Plan::where` | Yes |
| `config/permissions.php` holds 18 permissions and 6 global roles; there are per-company overrides | K | Yes |
| `BudgetAccess` defines `ROLES` (chair, treasurer, secretary, member, viewer), abilities and the sync guard `refuseSyncOp` | K `app/Services/Budget/BudgetAccess.php` | Yes |
| One business per account. `admin_users.phone_e164` is globally unique, and `TeamService::acceptExisting` refuses a user active in another company | K `TeamService.php:139`, migration `2026_09_27_100001` | Yes. This is why member self-service does **not** use staff logins (AK-19). |
| `Messenger::send($to, $text, $channels, $meta, $now)`; the log driver is the dev default | K `app/Services/Messaging/Messenger.php:31` | Yes |
| `MomoCollections::request(SaleRecord …)` is tied to a sale. `momo_requests` has `sale_record_id` and `amount decimal(20,2)`. No payout exists | K | Yes |
| `XlsxWriter` is in-house; `barryvdh/laravel-dompdf ^2.1` is installed | K `app/Services/Reports/XlsxWriter.php`, `composer.json` | Yes |
| `SchemaIntegrity` adds CHECK constraints (MySQL 8.0.16+ or MariaDB 10.2+) | K `app/Support/SchemaIntegrity.php:34` | Yes, but the local MAMP server is **MySQL 5.7.44** (the 8.0 is only the client), so locally every CHECK is `app_only`: `PostingService` and the model guards (`SchemaIntegrity::STATUS_MODELS`) enforce the same rules on every database (AK-56). Triggers do install on 5.7. |
| `SupportContact::APPS` lists 9 surfaces; a new app needs an entry | K `app/Support/SupportContact.php:40` | Yes |
| `OtpService` (purposes login, register, reset, verify), `DataCrypt`, `NumberSequencer`, `DuplicateService`, `StoredFile`, `IdempotentRequests` and `Shop\ApprovalService` all exist | K | Yes |
| Models are flat in `app/Models` with a module prefix (`Poultry*`) | K | Yes. Akiba uses the `Sacco*` prefix. |
| Portal routes are grouped in `// ── [module:x]` marker blocks; `config/apps.php` drives the sites; `resources/content/sites/<key>.php` holds each site's copy | P `routes/web.php`, `config/apps.php` | Yes |
| `AppFlavor` has `key`, `accent`, `modules`, `module`, `businessType`, `signUpModules` and `home`; `AppFlavor.all` lists 4 flavors | A `lib/core/app_flavor.dart` | Yes |

---

## 1. Where the code lives

| Layer | Akiba code | Rule |
|---|---|---|
| **K schema** | `database/migrations/2026_MM_DD_6xxxxx_*.php` (the 6xxxxx block is Akiba's) | Guarded with `Schema::hasTable`. Doc comment cites the roadmap item. |
| **K models** | `app/Models/Sacco*.php` (flat, prefixed, like `Poultry*`) | Every tenant model adds `CompanyScope` in `booted()`. Synced ones use `Syncable`. Ledger models refuse `updating` and `deleting`. |
| **K services** | `app/Services/Sacco/{Ledger,Members,Savings,Shares,Loans,Payments,Payouts,Checkoff,Messaging,Reports,YearEnd,Group,Auth}/*` | The only place rules and money paths live (AK-34) |
| **K value objects** | `app/Support/Sacco/{Amount,Rate,Currency,WorkingDays}.php` | Immutable, no floats (ADR 0009) |
| **K rules** | `app/Support/Rules/Sacco{Member,SavingsProduct,LoanProduct,…}Rules.php` | `WRITABLE` and `rules()`, shared by API and portal |
| **K API** | `app/Http/Controllers/Api/V1/Sacco/*Controller.php` (a subfolder: about 25 controllers) | `ApiResponse` envelope, `BusinessRuleException` codes |
| **K config** | `config/sacco.php` | Defaults, regulator, provisioning table, payout driver |
| **P screens** | `app/Livewire/Shop/Sacco/*`, views `resources/views/livewire/shop/sacco/*`, CSS `resources/css/shop/sacco.css`, helpers `app/Shop/Sacco*.php` | No kernel classes, no migrations; writes through kernel services (`shop-conventions.md`) |
| **P site** | `config/apps.php` entry `akiba-ledger`, `resources/content/sites/akiba-ledger.php`, `resources/views/marketing/apps/akiba-ledger.blade.php`, tokens in `resources/css/marketing.css` | Same as the other four |
| **A** | `lib/sacco/{data,domain,services,screens}` (poultry's layered layout), flavor `akibaledger` | Money is `int` minor units; screens never ask which flavor they are in |

---

## 2. Module plumbing (the places to touch)

There is no central module registry. Each place below gets one line, and the existing route-walk and tenant tests catch
anything missed.

| # | File | Change |
|---|---|---|
| 1 | K `config/onboarding.php` | `modules['sacco'] = 'SACCO and savings group'`; `module_paths['sacco'] = []` (no classic screens, AK-20); `business_types['sacco'] = ['label' => 'SACCO or savings group', 'modules' => ['sacco'], 'group' => 'Something else', …]` |
| 2 | K `EnsureApiModule::MODULES` | Add `'sacco'`. `label()` falls back to the onboarding label. |
| 3 | K `CompanyRules::CORE_MODULES` | Add `'sacco'`. **New rule** `EXCLUSIVE_MODULES = ['sacco']`: a company with `sacco` may have no other module (AK-04). The refusal is 422 `module_exclusive` ("A SACCO account keeps only the SACCO books. Use a separate account for a shop, farm or event."). `businessTypeFor(['sacco'])` returns `sacco`. |
| 4 | K `RegistrationService` | With `modules = ['sacco']`, `finance` is **not** added. The trial plan is `akiba-trial` (§13.3). |
| 5 | K `App\Support\ShopMenu` | Entries with `'module' => 'sacco'` for the portal and app menus |
| 6 | K `SupportContact::APPS` | Add `'akiba_ledger'` |
| 7 | K `TenantModels` | `SaccoPublicHoliday` goes in `GLOBAL_ON_PURPOSE` (a national calendar). Every other `Sacco*` model applies the scope. |
| 8 | K `PublicDemo` | A second, separate demo company (AK-35). `PublicDemoBuilder::MODULES` is **not** changed: the family demo stays as it is. |
| 9 | P `config/apps.php` | App entry `akiba-ledger` (§15.2), and `core_modules['sacco'] = ['label' => 'SACCO books', 'hint' => 'Members, savings, shares and loans', 'app' => 'akiba-ledger', 'icon' => 'landmark', 'exclusive' => true]`. Sign-up and Settings › Modules hide exclusive modules unless `?app=akiba-ledger` (or the account already has the module). |
| 10 | P `EnsureModule` | Nothing new: `module:sacco` works once 9 is in place |
| 11 | A `AppFlavor` | `akibaLedger`, added to `all` (§15.3). `budgetPro` gains `hiddenModules: ['sacco']` (AK-36). |
| 12 | A `ModuleRegistry.modules` | `sacco` → `SaccoHome()` |

**Tests:**

- `ModuleGatingTest` covers every new route;
- a new `CompanyRulesSaccoTest` checks exclusivity both ways;
- the existing suites of the four apps stay green.

---

## 3. Data model

### 3.0 Conventions for every table

- **Keys and tenancy:**
  - `id BIGINT UNSIGNED` primary key;
  - `company_id BIGINT UNSIGNED NOT NULL`, with a foreign key to `companies` `ON DELETE RESTRICT` (added through `SchemaIntegrity::FOREIGN_KEYS`);
  - every index starts with `company_id`.
- **Money:** `*_minor BIGINT` (signed only where a balance can go negative; otherwise `UNSIGNED` with a CHECK) and
  `currency CHAR(3)` on money documents. **No** `DECIMAL` or `FLOAT` in any `sacco_*` money column (AK-08).
- **Rates:** `*_bp INT UNSIGNED` (basis points; 150 = 1.50 %).
- **Dates:** business dates are `DATE` in the SACCO's local day (`LocalDate::today($companyId)`); instants are
  `TIMESTAMP` in UTC.
- **Sync columns (S)** go only on tables a phone writes: `uuid CHAR(36)`, `server_seq BIGINT UNSIGNED`, `version INT UNSIGNED`, `is_deleted TINYINT(1)`, `client_created_at`, `client_updated_at`, `device_id VARCHAR(64)`, with `UNIQUE(company_id, uuid)` and `INDEX(company_id, server_seq)` (ADR 0001).
- **Users:**
  - `created_by_id` / `*_by_id` reference `admin_users.id`;
  - member-originated rows carry `member_actor_id` instead, which references `sacco_members.id`.
- Status values are guarded with CHECK constraints, added to `SchemaIntegrity::CHECKS`.

### 3.1 P1: setup, officers, members

**`sacco_settings`** (one row per company; created by `SaccoSetupService::setup`)

| Column | Type | Notes |
|---|---|---|
| company_id | BIGINT UNSIGNED, **unique** | |
| mode | VARCHAR(10) | `office` or `group`. Locked once the first journal is posted (AK-05). |
| legal_name, short_name | VARCHAR(190) / VARCHAR(60) | Printed on receipts and returns |
| registration_no | VARCHAR(60) NULL | Cooperative "CS No." |
| licence_no, licence_expires_on | VARCHAR(60) NULL / DATE NULL | Tier 4 licence; empty means not licensed |
| regulator_name, regulator_short, regulator_portal_url | VARCHAR | Defaults come from `config('sacco.regulator')` (AK-14) |
| show_regulated_line | TINYINT(1) | Allowed only when `licence_no` is set (validated) |
| fiscal_year_start_month | TINYINT | Default 1 (Tier 4 s.45) |
| currency | CHAR(3) | Copied from `companies.currency` at setup; fixed afterwards |
| share_par_minor | BIGINT UNSIGNED | Office mode par value, default 10,000 |
| min_shares | INT UNSIGNED | Default 5 for community SACCOs, 1 for staff SACCOs |
| max_holding_bp | INT UNSIGNED | Default 3333 (one third, Tier 4 s.48) |
| entrance_fee_minor | BIGINT UNSIGNED | Default 20,000 |
| withdrawal_approval_above_minor | BIGINT UNSIGNED | Default 1,000,000 in office mode, 0 in group mode (AK-44) |
| offline_receipt_max_minor | BIGINT UNSIGNED | Default 5,000,000 (AK-23) |
| single_officer_mode | TINYINT(1) | Group mode only (AK-22) |
| receipts_channel | VARCHAR(12) | `sms`, `whatsapp`, `app` or `none` |
| dividend_cap_bp, reserve_min_bp, education_fund_bp | INT UNSIGNED | 1000 / 1000 / 100 (AK-18) |
| dormancy_months | TINYINT | Default 24 |
| employee_loans_policy | VARCHAR(16) | `salary_advance_only` (Coop s.55AP) or `committee` (SI 51 reg. 38); default `salary_advance_only` (AK-43) |
| allocation_order | VARCHAR(60) | Default `penalty,fees,interest,principal` (AK-27) |
| created_at, updated_at | | AuditLogger |

**`sacco_officers`**: governance positions, the `budget_program_members` pattern (AK-21)

| Column | Type | Notes |
|---|---|---|
| member_id | BIGINT UNSIGNED NULL → sacco_members | An officer is usually a member |
| user_id | BIGINT UNSIGNED NULL → admin_users | Null for a group-mode key holder with no login |
| position | VARCHAR(24) | `chairperson`, `vice_chair`, `treasurer`, `secretary`, `manager`, `board_member`, `loans_committee`, `supervisory`, `key_holder`, `money_counter`, `teller`, `custom` |
| title | VARCHAR(60) NULL | For `custom` |
| approval_limit_minor | BIGINT UNSIGNED NULL | Delegated loan approval limit (Coop s.55AH) |
| starts_on, ends_on | DATE / DATE NULL | A term ends; the row is never deleted |
| created_by_id | | |
| INDEX | (company_id, position, ends_on), (company_id, user_id) | |

**`sacco_members`** (S: created offline in group mode; ADR 0007 merge)

| Column | Type | Notes |
|---|---|---|
| member_no | VARCHAR(20) | Server-assigned through `NumberSequencer` kind `mem` (e.g. `M-000123`), `UNIQUE(company_id, member_no)`. Rows created offline get one when they sync. |
| kind | VARCHAR(12) | `individual`, `group` (enterprise group or association) or `institution` |
| first_name, last_name, other_names | VARCHAR(80) | For kind `group`, the name is in `first_name` |
| gender | VARCHAR(8) NULL | `female`, `male` or `other`, for gender-disaggregated statistics |
| date_of_birth | DATE NULL | 12–17 may be members but not officers (Coop s.13) |
| nin_encrypted | TEXT NULL | `DataCrypt` (AK-31) |
| nin_hash | CHAR(64) NULL | HMAC-SHA256 with `config('sacco.nin_pepper')`. `INDEX(company_id, nin_hash)`. Used to find duplicates, not as a unique constraint (ADR 0007). |
| phone_e164, phone_alt_e164 | VARCHAR(20) NULL | `Phone::e164`; `INDEX(company_id, phone_e164)` |
| email | VARCHAR(190) NULL | |
| address, district, subcounty, parish, village | VARCHAR | |
| occupation, employer_name | VARCHAR(120) NULL | Register field (Regs 1992 reg. 12) |
| employer_id | BIGINT UNSIGNED NULL → sacco_employers (P3) | Check-off |
| payroll_no | VARCHAR(40) NULL | Check-off match key |
| joined_on | DATE | |
| status | VARCHAR(12) | `applicant`, `active`, `dormant`, `suspended`, `exited`, `deceased` or `rejected` |
| status_changed_on | DATE NULL | |
| exit_reason | VARCHAR(255) NULL | |
| kyc_status | VARCHAR(10) | `pending`, `verified` or `rejected` |
| kyc_verified_by_id, kyc_verified_at | | Set by an officer who is not the one who captured the member (maker-checker, §7) |
| consent_data_at | TIMESTAMP NULL | DPPA s.7/s.9 explicit consent, including the hosting clause (AK-47) |
| consent_messages_at, messages_opt_out_at | TIMESTAMP NULL | Read by `Messenger`'s consent check through `meta.sacco_member_id` (§10) |
| consent_credit_bureau_at | TIMESTAMP NULL | SI 108 reg. 14. Captured; no export in v1. |
| guardian_name, guardian_phone_e164 | NULL | Members aged 12–17 (DPPA s.8) |
| is_insider | TINYINT(1) | Derived on save: an active officer or employee. Cached for loan routing. |
| photo_file_id, signature_file_id | BIGINT UNSIGNED NULL → files | `StoredFile`, counted by `Quotas::assertCanStore` |
| merged_into_id | BIGINT UNSIGNED NULL | ADR 0007 tombstone target |
| created_by_id, updated_by_id | | |
| INDEX | (company_id, status), (company_id, last_name, first_name), (company_id, employer_id, payroll_no) | |

**`sacco_member_relations`**: nominees and next of kin

| Column | Type | Notes |
|---|---|---|
| member_id | → sacco_members | |
| role | VARCHAR(12) | `nominee` or `next_of_kin` |
| name, relationship, phone_e164 | | |
| nin_encrypted | TEXT NULL | |
| share_bp | INT UNSIGNED NULL | Nominee portion. The member's nominees must total 10000. More than one nominee requires more than one share (Regs 1992 reg. 10). |
| witness1_name, witness2_name, signed_on | | Nominations are attested by two witnesses |
| document_file_id | NULL → files | |
| ended_on | DATE NULL | Replaced, never deleted |

**`sacco_member_documents`**: `member_id`, `type` (`national_id`, `passport`, `photo`, `signature`, `application`,
`nomination`, `other`), `file_id`, `captured_by_id`, `verified_by_id`, `verified_at`, `notes`.

- ID scans are optional and kept only with consent.
- The NIN number is the KYC key (AK-31).

### 3.2 P1: the ledger

These tables are specified in ADR 0009. Their columns are summarised here.

| Table | Columns | Indexes and constraints |
|---|---|---|
| **`sacco_ledger_accounts`** (chart of accounts) | `code` VARCHAR(16), `name` VARCHAR(120), `type` (`asset`, `liability`, `equity`, `income`, `expense`), `normal_side` (`debit` or `credit`), `parent_id` NULL, `is_postable` (headers are not), `control_of` NULL (`savings`, `shares`, `loan_principal`, `loan_interest`, `loan_penalty`, `loan_fees`, `fines`, `fund`), `system_key` VARCHAR(40) NULL (e.g. `cash.office`, `momo.clearing`, `suspense.receipts`), `umra_line` VARCHAR(16) NULL (e.g. `F5.1.1`, `F6.2.1`), `is_active`, `sort`, `created_by_id` | `UNIQUE(company_id, code)`, `UNIQUE(company_id, system_key)`. Never deleted (deactivated). |
| **`sacco_ledger_heads`** | `company_id` (PK), `last_entry_id`, `last_hash` CHAR(64), `entries_count` BIGINT | The per-SACCO posting lock and the end of the hash chain |
| **`sacco_journal_entries`** | `entry_no` VARCHAR(24) (`JE-2026-000001`), `entry_date` DATE (posting, always in an open period), `value_date` DATE (when the money moved), `period_id`, `cycle_id` NULL (group mode), `kind` VARCHAR(24), `source_type` VARCHAR(40), `source_id`, `idempotency_key` VARCHAR(120), `payload_hash` CHAR(64), `memo` VARCHAR(255), `total_minor` BIGINT UNSIGNED, `currency`, `reverses_entry_id` NULL, `approval_id` NULL, `posted_by_id` NULL (null means system), `prev_hash`, `hash` CHAR(64), `created_at` (no `updated_at`) | `UNIQUE(company_id, idempotency_key)`, `UNIQUE(company_id, entry_no)`, `UNIQUE(reverses_entry_id)`, `INDEX(company_id, entry_date)`, `INDEX(company_id, source_type, source_id)` |
| **`sacco_journal_lines`** | `journal_entry_id`, `line_no` SMALLINT, `ledger_account_id`, `debit_minor` BIGINT UNSIGNED, `credit_minor` BIGINT UNSIGNED, `sub_type` VARCHAR(16) NULL (`savings`, `shares`, `loan`, `fine`, `fund_due`), `sub_id` BIGINT UNSIGNED NULL, `member_id` NULL, `entry_date`, `value_date` (copied from the entry), `memo` VARCHAR(255) NULL | `CHECK (debit_minor >= 0 AND credit_minor >= 0 AND ((debit_minor = 0) <> (credit_minor = 0)))`, `CHECK ((sub_type IS NULL) = (sub_id IS NULL))`, `UNIQUE(journal_entry_id, line_no)`, `INDEX(company_id, ledger_account_id, entry_date)`, `INDEX(company_id, sub_type, sub_id, entry_date)`, `INDEX(company_id, member_id, entry_date)` |
| **`sacco_account_balances`** | `ledger_account_id` (unique per company), `debit_total_minor`, `credit_total_minor`, `balance_minor` BIGINT signed (positive on the normal side), `last_entry_id` | A cache, written only by `PostingService` under `FOR UPDATE` (ADR 0002 pattern) |
| **`sacco_periods`** | `year` SMALLINT, `month` TINYINT, `starts_on`, `ends_on`, `status` (`open`, `closing`, `closed`), `closed_by_id`, `closed_at`, `approval_id` | `UNIQUE(company_id, year, month)` |
| **`sacco_period_balances`** | `period_id`, `ledger_account_id`, `opening_minor`, `debits_minor`, `credits_minor`, `closing_minor` | Written once at close, then immutable. Fast trial balance and returns. |

### 3.3 P1: shares, savings, money documents, approvals

**`sacco_share_accounts`**:

| Column | Notes |
|---|---|
| `member_id` | `UNIQUE(company_id, member_id)` |
| `account_no` | |
| `shares_count` | INT UNSIGNED cache |
| `balance_minor` | BIGINT cache |
| `opened_on` | |
| `status` | `active` or `closed` |

**`sacco_savings_products`**

| Column | Type | Notes |
|---|---|---|
| code, name | VARCHAR(16) / VARCHAR(80) | `UNIQUE(company_id, code)` |
| kind | VARCHAR(12) | `voluntary`, `compulsory`, `fixed` or `group` |
| withdrawable | TINYINT(1) | Compulsory savings: no (non-withdrawable deposits, Form 5) |
| min_opening_minor, min_balance_minor | BIGINT UNSIGNED | |
| interest_rate_bp | INT UNSIGNED | Annual rate; 0 = no interest |
| interest_basis | VARCHAR(16) | `none`, `daily_balance` or `min_monthly_balance` |
| interest_posting | VARCHAR(10) | `monthly`, `quarterly` or `annually` |
| term_months | SMALLINT NULL | Fixed deposits |
| early_withdrawal_penalty_bp | INT UNSIGNED | Fixed deposits |
| withdrawal_fee_minor | BIGINT UNSIGNED | Counted on the key-facts sheet |
| max_withdrawals_per_month | SMALLINT NULL | |
| notice_days | SMALLINT | |
| ledger_account_id | → sacco_ledger_accounts (`control_of = savings`) | Each product has its own control account (2110, 2120, 2130…) |
| interest_expense_account_id | → ledger account | Default 5100 |
| disclosure_text | TEXT | Printed disclosures (SI 51 reg. 33) |
| is_active | TINYINT(1) | |
| version | INT UNSIGNED | Bumped by every change; the old terms go to `sacco_savings_product_versions` |

**`sacco_savings_product_versions`**: `product_id`, `version`, `effective_from` DATE, `terms` JSON, `approval_id`,
`created_by_id`. Rate changes take effect at least 30 days after member notice (§7).

**`sacco_savings_accounts`**

| Column | Type | Notes |
|---|---|---|
| account_no | VARCHAR(24) | `UNIQUE(company_id, account_no)`; `NumberSequencer` kind `sav` |
| member_id, product_id | | `INDEX(company_id, member_id)` |
| status | VARCHAR(10) | `active`, `dormant`, `frozen` or `closed` |
| opened_on, closed_on, maturity_on, dormant_since | DATE | |
| balance_minor | BIGINT | Cache (`PostingService`) |
| held_minor | BIGINT UNSIGNED | Liens: guarantees (P2), minimum balance excluded |
| last_activity_on | DATE | Dormancy |
| frozen_reason | VARCHAR(255) NULL | |

**Available balance** = `balance_minor − held_minor − product.min_balance_minor − pending withdrawals`. It is computed
under lock, never stored.

**`sacco_transactions`**: the money **document** behind each member-facing posting (S; kind `event`; ADR 0001)

| Column | Type | Notes |
|---|---|---|
| receipt_no | VARCHAR(30) | `NumberSequencer` kind `rct`. A device's own numbers follow the device floor (the shop's receipt pattern, commit 7ccda265). |
| kind | VARCHAR(24) | `deposit`, `withdrawal`, `share_purchase`, `share_transfer`, `entrance_fee`, `fee`, `internal_transfer`, `loan_disbursement`, `loan_repayment`, `loan_cancellation`, `fine_payment`, `fund_contribution`, `reversal` |
| channel | VARCHAR(10) | `cash`, `bank`, `momo`, `checkoff` or `internal` |
| member_id | | |
| savings_account_id, share_account_id, loan_id, counter_account_id | NULL | `counter_account_id` is the other side of an internal transfer or share transfer |
| shares_count | INT NULL | Share purchase or transfer |
| amount_minor | BIGINT UNSIGNED | `CHECK > 0` |
| fee_minor | BIGINT UNSIGNED | |
| currency | CHAR(3) | |
| value_date | DATE | The phone's local business day when it was recorded offline |
| status | VARCHAR(18) | `posted`, `held`, `pending_approval`, `rejected` or `cancelled` |
| hold_reason | VARCHAR(40) NULL | `unknown_account`, `account_closed`, `over_offline_max` or `loan_closed` (AK-24) |
| journal_entry_id | NULL | Set once when posted |
| reverses_id | NULL, **UNIQUE** | A transaction is reversed once (the `contribution_payments` pattern) |
| reason | VARCHAR(255) NULL | Required on a reversal |
| approval_id, momo_request_id, payout_id | NULL | |
| reference | VARCHAR(80) NULL | Bank slip or MoMo id |
| narration | VARCHAR(255) NULL | |
| received_by_id | | The officer |
| member_actor_id | NULL | Self-service |
| INDEX | (company_id, member_id, value_date), (company_id, kind, status) | |

**Immutability:**

- the model refuses changes to `kind`, `amount_minor`, `member_id`, the account ids and `value_date` once the row
  exists;
- only the workflow columns (`status`, `hold_reason`, `journal_entry_id`, `approval_id`) move, and only forward;
- the phone may never change a row (`immutable_event`).

**`sacco_approvals`**: maker-checker requests (§7)

| Column | Type | Notes |
|---|---|---|
| uuid | CHAR(36) | Also the API idempotency key |
| action | VARCHAR(32) | §7 list |
| subject_type, subject_id | | e.g. `sacco_transactions`, 55 |
| payload | JSON | The frozen request; what the checker sees is what gets executed |
| payload_hash | CHAR(64) | |
| amount_minor | BIGINT UNSIGNED NULL | |
| required_approvals | TINYINT | 1 by default, 2 for write-offs and dividends |
| status | VARCHAR(12) | `pending`, `approved`, `rejected`, `cancelled`, `expired` or `executed` |
| requested_by_id, requested_at | | |
| executed_entry_id | NULL | |
| expires_at | TIMESTAMP | Default 7 days. Expired requests never execute. |
| decision_note | VARCHAR(255) NULL | |
| INDEX | (company_id, status, action) | |

**`sacco_approval_votes`**:

- `approval_id`, `user_id`, `officer_id`, `decision` (`approve` or `reject`), `note`, `self_approved` TINYINT(1)
  (single-officer mode only), `created_at`;
- `UNIQUE(approval_id, user_id)`.

### 3.4 P2: loans

**`sacco_loan_products`**

| Column | Type | Notes |
|---|---|---|
| code, name | | |
| preset | VARCHAR(12) NULL | `pdm`, `emyooga`, `vsla` or null. Presets fill the defaults (02 §7.2). |
| interest_method | VARCHAR(20) | `reducing_annuity`, `reducing_equal_principal` or `flat` (**flat refused unless mode = group**, AK-07) |
| rate_bp | INT UNSIGNED | Rate per `rate_period` |
| rate_period | VARCHAR(6) | `month` or `year` (shown to members as % per year, SI 108 reg. 5(2)(m)) |
| repayment_frequency | VARCHAR(12) | `weekly`, `fortnightly`, `monthly` or `meeting` (group) |
| min_term, max_term | SMALLINT | In periods of the frequency |
| min_amount_minor, max_amount_minor | BIGINT UNSIGNED | PDM preset: max 1,000,000 including charges |
| savings_multiplier_bp | INT UNSIGNED | 30000 = 3 × free savings (shares excluded, Tier 4 s.48) |
| min_membership_days, min_shares | | |
| guarantors_required | TINYINT | |
| guarantee_cover_bp | INT UNSIGNED | Share of (principal − own free savings) that must be guaranteed; default 10000 |
| processing_fee_bp, processing_fee_minor, insurance_bp | | Counted in the 100 % cap |
| penalty_rate_bp | INT UNSIGNED | Monthly; validated ≤ 50 % of the monthly contract rate (SI 108 reg. 4(5)) |
| grace_days | SMALLINT | Days after the due date before penalty starts (default 0) |
| principal_grace_periods | SMALLINT | Interest-only periods at the start |
| cooling_off_working_days | TINYINT | Default 5, minimum 5 in office mode |
| cancellation_fee_bp | INT UNSIGNED | ≤ 200 (2 %); default 0 |
| hold_disbursement_until_cooling_off_ends | TINYINT(1) | Default 0 (AK-26) |
| allowed_channels | JSON | Subset of `savings`, `cash`, `bank`, `momo` (AK-10) |
| allow_top_up | TINYINT(1) | |
| portfolio_account_id, interest_income_account_id, fee_income_account_id, penalty_income_account_id | → ledger accounts | Defaults 1300 / 4100 / 4200 / 4110 |
| is_active, version | | Terms are **snapshotted** onto each loan (MobiSave KEEP) |

**`sacco_loans`** (status is workflow; money is ledger-derived and cached)

| Column | Type | Notes |
|---|---|---|
| loan_no | VARCHAR(24) | `NumberSequencer` kind `ln` |
| uuid | CHAR(36) | A draft application can be started offline (master kind); everything after `submitted` is online |
| member_id, product_id, officer_id | | |
| status | VARCHAR(16) | `draft`, `submitted`, `appraised`, `in_committee`, `approved`, `rejected`, `appealed`, `signed`, `cancelled`, `disbursing`, `active`, `closed`, `written_off` or `restructured` |
| applied_minor, approved_minor | BIGINT UNSIGNED | |
| purpose, purpose_category | | PDM: `inputs_share_bp` recorded (≥ 9000) |
| terms | JSON | The snapshot: method, rate, frequency, term, fees, penalty, grace, cooling-off, allocation order, product version |
| is_insider | TINYINT(1) | Copied from the member at submission. Routes to the committee with recusal. |
| disbursement_channel | VARCHAR(10) | |
| disburse_to_savings_account_id | NULL | |
| momo_phone_e164 | NULL | |
| applied_at, appraised_at, approved_at, signed_at, cooling_off_ends_on, disbursed_on, first_due_on, maturity_on, closed_on | | |
| principal_outstanding_minor, interest_due_minor, penalty_due_minor, fees_due_minor, interest_in_suspense_minor | BIGINT | Caches (ADR 0009) |
| arrears_principal_minor, days_past_due | BIGINT / INT | Updated by the daily arrears run |
| classification | VARCHAR(12) | `performing`, `watch`, `substandard`, `doubtful` or `loss` |
| npl_since, npl_cap_minor | DATE / BIGINT NULL | SI 108 reg. 7: interest + fees after NPL ≤ the principal owing at NPL |
| reschedule_count, upgrade_count | TINYINT | Upgrade at most twice (SI 51 reg. 41) |
| top_up_of_loan_id, restructured_from_loan_id | NULL | |
| schedule_version | SMALLINT | |
| INDEX | (company_id, member_id, status), (company_id, status, classification) | |

**`sacco_loan_schedule_lines`**

- Columns: `loan_id`, `schedule_version`, `installment_no`, `due_on`, `opening_principal_minor`,
  `principal_due_minor`, `interest_due_minor`, `fee_due_minor`, `principal_paid_minor`, `interest_paid_minor`,
  `fee_paid_minor`, `penalty_paid_minor`, `status` (`due`, `partial`, `paid` or `superseded`), `paid_on`.
- `UNIQUE(loan_id, schedule_version, installment_no)`.
- The paid columns are allocation caches, written by `Repayments` in the posting transaction. They are not ledger
  rows.

**`sacco_loan_guarantors`**

| Column | Notes |
|---|---|
| `loan_id`, `guarantor_member_id` | |
| `guaranteed_minor` | |
| `consent_status` | `requested`, `consented`, `declined` or `released` |
| `consent_channel` | `signed_form` (upload), `sms_link` (signed URL + OTP) or `member_app` |
| `notice_sent_at` | The written notice of the amount and nature of the liability (SI 108 reg. 10) |
| `consented_at`, `consent_evidence_file_id`, `consent_otp_ref` | |
| `hold_savings_account_id`, `held_minor` | The lien |
| `released_at` | |

Refused as guarantors:

- officers, employees and supervisory board members (Coop s.55AP(3), AK-48);
- members over their guarantee capacity (free savings);
- members with 3 or more active guarantees.

**`sacco_loan_collateral`**:

- `loan_id`, `type` (`land_title`, `logbook`, `chattel`, `salary_assignment`, `deposit_lien`, `other`),
  `description`, `value_minor`, `document_file_id`, `demand_notice_on`, `realised_on`, `realised_minor`;
- national ID, passport, ATM card or PIN, savings passbook and pre-signed transfer deeds are **not** in the type list
  (SI 108 reg. 8);
- a sale needs `demand_notice_on` at least 60 days earlier (validated).

**`sacco_loan_votes`**:

- `loan_id`, `officer_id`, `user_id`, `vote` (`approve`, `reject` or `abstain`), `recused` TINYINT(1), `note`,
  `created_at`;
- `UNIQUE(loan_id, officer_id)`.

**`sacco_loan_documents`**:

- `loan_id`, `type` (`key_facts`, `contract`, `signed_contract`, `witness_statement`, `demand_notice`, `stop_letter`),
  `file_id`, `generated_at`, `signed_by_member_at`, `witness_name`, `witness_phone_e164`;
- generated PDFs are stored, so what was signed is what was shown.

**`sacco_public_holidays`** (global, `GLOBAL_ON_PURPOSE`):

- `country` CHAR(2), `date`, `name`;
- used by `WorkingDays` for cooling-off;
- seeded for UG, and editable by the platform admin for movable dates (Eid).

**`sacco_member_exits`**:

- `member_id`, `reason` (`voluntary`, `expelled`, `deceased`), `requested_on`, `settlement` JSON (shares → transfer
  or STF buy-back, savings payout, offsets), `approval_id`, `journal_entry_ids` JSON, `completed_on`;
- an exit is blocked while the member has an open loan or an active guarantee.

### 3.5 P3: money channels

| Table | Columns |
|---|---|
| **`momo_requests`** (existing, additive) | New nullable `purpose` VARCHAR(20) (`sale`, the default for old rows, `sacco_deposit`, `sacco_repayment`, `sacco_shares`), `subject_type`, `subject_id`, `sacco_member_id`, `amount_minor` BIGINT UNSIGNED. `amount decimal(20,2)` stays filled for the existing code. |
| **`sacco_payouts`** | `uuid`, `purpose` (`loan_disbursement`, `withdrawal`, `shareout`, `dividend`, `exit`), `subject_type`/`subject_id`, `member_id`, `phone_e164`, `network`, `amount_minor`, `fee_minor`, `provider` (`simulated`, `flutterwave`), `provider_ref`, `idempotency_key` UNIQUE, `status` (`queued`, `sent`, `successful`, `failed`, `unknown`, `reversed`), `attempts`, `last_error`, `approval_id`, `sent_journal_id`, `settled_journal_id`, timestamps |
| **`sacco_employers`** | `name`, `contact`, `payroll_system` (`ipps_hcm`, `private`), `remittance_day`, `checkoff_account_id` (1410) |
| **`sacco_checkoff_batches`** | `employer_id`, `period` (YYYY-MM), `status` (`draft`, `sent`, `remitted`, `posted`), `expected_minor`, `remitted_minor`, `file_id` (the import), `approval_id` |
| **`sacco_checkoff_lines`** | `batch_id`, `member_id`, `payroll_no`, `expected` JSON (shares, savings and loan per account), `expected_minor`, `remitted_minor`, `variance_minor`, `status` (`matched`, `under`, `over`, `unknown`), `transaction_ids` JSON |
| **`sacco_tills`** | `name`, `ledger_account_id` (a child of 1010), `user_id` NULL, `is_vault` |
| **`sacco_till_sessions`** | `till_id`, `opened_by_id`, `opened_at`, `opening_count_minor`, `closed_at`, `closing_count_minor`, `expected_minor`, `variance_minor`, `variance_journal_id`, `checked_by_id` |
| **`sacco_statement_imports`** | `source` (`bank`, `mtn`, `airtel`, `flutterwave`), `ledger_account_id`, `file_id`, `period_from`, `period_to`, `status` |
| **`sacco_statement_lines`** | `import_id`, `line_date`, `reference`, `amount_minor` signed, `matched_entry_id` NULL, `match_status` (`matched`, `unmatched`, `ignored`) |

### 3.6 P4: year-end and returns

| Table | Columns |
|---|---|
| **`sacco_fiscal_years`** | `year`, `starts_on`, `ends_on`, `status` (`open`, `closing`, `closed`), `net_surplus_minor`, `close_entry_id`, `approval_id` |
| **`sacco_appropriations`** | `fiscal_year_id`, `lines` JSON (reserve, NCEF, education, STF, other reserves, dividend), `agm_date`, `agm_minutes_file_id`, `registrar_consent_file_id`, `registrar_consent_on`, `status`, `approval_id`, `entry_id` |
| **`sacco_dividend_runs`** | `fiscal_year_id`, `rate_bp` (≤ `dividend_cap_bp`), `basis` (`year_end_shares` or `weighted_monthly`), `pool_minor`, `core_capital_ratio_bp_after`, `status`, `approval_id`, `entry_id` |
| **`sacco_dividend_lines`** | `run_id`, `member_id`, `basis_minor`, `amount_minor`, `paid_to` (`savings`, `momo`, `cash`), `transaction_id` |
| **`sacco_interest_runs`** | Savings interest: `product_id`, `period_from`, `period_to`, `rate_bp`, `total_minor`, `entry_id`, `status`. Lines in `sacco_interest_run_lines` (`account_id`, `basis_minor`, `amount_minor`). |
| **`sacco_return_filings`** | `form` (`F1`–`F7`, `SAV`, `REG_ANNUAL`, `S55AS`), `period_key`, `due_on`, `generated_at`, `file_ids` JSON, `authorised_by_id`, `countersigned_by_id`, `filed_on`, `filed_reference` |
| **`sacco_dormancy_notices`** | `savings_account_id`, `stage` (`register`, `notice`, `advertised`, `transferred`), `on`, `file_id` |

### 3.7 P5: group mode

| Table | Columns |
|---|---|
| **`sacco_cycles`** | `name`, `starts_on`, `ends_on`, `status` (`open` or `closed`), `share_value_minor`, `min_shares_per_meeting` (1), `max_shares_per_meeting` (5), `meeting_frequency`, `loan_multiplier_bp`, `service_charge_bp`, `social_fund_per_meeting_minor`, `opening_entry_id`, `shareout_id`. Partial-unique "one open cycle": a generated column `open_flag = IF(status='open',1,NULL)` with `UNIQUE(company_id, open_flag)`. |
| **`sacco_meetings`** (S) | `cycle_id`, `number`, `held_on`, `location`, `gps` NULL, `status` (`draft`, `in_progress`, `submitted`, `posted`), `opening_cash_minor`, `closing_cash_minor`, `expected_closing_minor`, `variance_minor`, `step` (resumable), `agenda_next` |
| **`sacco_meeting_attendance`** (S) | `meeting_id`, `member_id`, `status` (`present`, `late`, `absent`, `represented`), `representative_name` |
| **`sacco_fine_types`** | `name`, `default_minor`, `auto_on` (`absent`, `late`, `missed_saving` or null) |
| **`sacco_fines`** (S) | `meeting_id`, `member_id`, `fine_type_id`, `amount_minor`, `status` (`owed`, `paid`, `waived`), `charge_entry_id`, `waiver_approval_id` |
| **`sacco_funds`** | `name`, `kind` (`social`, `welfare`, `building`, `other`), `ledger_account_id`, `per_meeting_minor`, `eligibility` (`all`, `compulsory`) |
| **`sacco_fund_dues`** | `fund_id`, `member_id`, `meeting_id`, `due_minor`, `paid_minor`, `arrears_minor` (MobiSave and digisave rule: owed = arrears + required − paid), `UNIQUE(fund_id, member_id, meeting_id)` |
| **`sacco_cash_counts`** (S) | `meeting_id`, `counted_minor`, `counter1_officer_id`, `counter2_officer_id`, `keyholders` JSON (3) |
| **`sacco_shareouts`** | `cycle_id`, `profit_minor`, `value_per_share` stored as `pool_minor / shares` with an exact remainder, `status` (`preview`, `approved`, `posted`), `approval_id`, `entry_id` |
| **`sacco_shareout_lines`** | `shareout_id`, `member_id`, `shares`, `amount_minor`, `carry_forward_minor`, `paid_minor`, `transaction_id` |

---

## 4. Services (kernel, `App\Services\Sacco\…`)

| Service | Responsibility | Reuses |
|---|---|---|
| `Ledger\PostingService::post(JournalDraft): SaccoJournalEntry` | The only writer of journals and balance caches (ADR 0009 §4) | `DB::transaction`, `lockForUpdate`, `NumberSequencer` |
| `Ledger\JournalDraft` | Value object: lines, kind, dates, idempotency key, source, guards (closures run under lock) | `Amount` |
| `Ledger\ReversalService::reverse(entryId, reason, approvalId)` | Mirror entry, `reverses_entry_id` unique | |
| `Ledger\ChartOfAccounts` | `seed(company, mode)` from `database/data/sacco_chart.php`, `account(systemKey)`, `controlFor(subType, subId)` | |
| `Ledger\PeriodService` | Creates monthly periods ahead, `close` (checks + snapshot), `reopen` (the latest closed only) | |
| `Ledger\LedgerVerifier` | Trial balance = 0; Σ lines = cached balances; Σ sub-ledgers = controls; hash chain intact. `sacco:ledger-verify` runs nightly and on demand. | `Notifier` (alerts the owner and platform on any failure) |
| `Ledger\PostingRules` | Maps each `sacco_transactions.kind` × channel to its journal lines (§5) | |
| `SaccoSetupService::setup(Company, array)` | Settings, CoA, periods, default products (Ordinary savings, Compulsory savings), the founder's `chairperson` or `treasurer` position | `OnboardingService` step |
| `SaccoAccess` | `can(user, ability, ?subject)`: the company permission (ADR 0004) **and**, where needed, an active position (loan votes, delegated limits), insider recusal, maker ≠ checker. `refuseSyncOp()` for `SyncApplier`. | `Permissions`, the `BudgetAccess` pattern |
| `OfficerService` | `appoint`, `endTerm`, `positionsOf`, `committee()` | `CommitteeService` pattern |
| `SaccoApprovals` | `request(action, subject, payload)`, `approve`, `reject`, `cancel`, `execute` (calls the action's executor in one transaction), `expireDue` | |
| `Members\MemberService` | `register` (`Quotas::assertCanAdd('members')`), `update`, `verifyKyc`, `setStatus`, `nominate`, `import(csv)`, `duplicates()` | `CustomerRules` pattern, `DuplicateService`, `Phone`, `DataCrypt` |
| `Members\MemberExit` (P2) | Settlement plan, approval, journals | |
| `Shares\ShareService` | `purchase`, `transfer`, `buyBackFromStf` (P4), the one-third cap under lock | |
| `Savings\SavingsProducts` | CRUD with versioning and notice | |
| `Savings\SavingsAccounts` | `open`, `freeze`, `unfreeze`, `close`, `available()` | |
| `SaccoTransactions` | `record(array)`: validates, writes the document, posts through `PostingRules` (or holds it, or sends it to approval). Also `reverse()` and `applySynced()` for the sync handler. | `contribution_payments` pattern |
| `Loans\Schedule` | Pure maths: `build(principal, terms, firstDue): ScheduleLine[]`; `settlementQuote(loan, date)` | `Amount`, `Rate`, `WorkingDays` |
| `Loans\Eligibility` | Multiplier, membership days, min shares, one open loan, one pending application, liquidity (cash + bank + MoMo ≥ amount), single-borrower limit (min of 10 % of assets and 25 % of institutional capital), 100 % cost cap, insider routing | `TrialBalance` |
| `Loans\LoanApplications`, `Loans\Guarantors`, `Loans\Committee`, `Loans\Contracts` (dompdf), `Loans\Disbursement`, `Loans\Repayments`, `Loans\Accruals`, `Loans\Arrears`, `Loans\Provisioning`, `Loans\Restructure`, `Loans\WriteOffs` | The lifecycle (§5.3) | |
| `Payments\SaccoCollections` | Request-to-pay for a member's deposit, repayment or shares; `settle(txRef)` → `SaccoTransactions::record` with idempotency key `momo:{tx_ref}` | `FlutterwaveService::chargeMobileMoney`, `momo_requests`, `SimulatedFlutterwave` |
| `Payouts\PayoutGateway` (interface) + `SimulatedPayouts`, `FlutterwavePayouts`; `Payouts\PayoutService` | §11 | |
| `Checkoff\CheckoffService` | Schedule export, remittance import, allocation, variances, stop letters | `XlsxReader`, `XlsxWriter` |
| `Tills\TillService` | Open, close, variance journal | |
| `Reconciliation\StatementImport` | CSV import, matching by reference, amount and date | `XlsxReader` |
| `Messaging\SaccoMessages` (templates), `Messaging\SaccoNotices` (send after commit) | §10 | `Messenger`, the `Budget\MessageTemplates` pattern |
| `Reports\*` | §12 | `ReportService` registry style, `XlsxWriter`, dompdf |
| `YearEnd\{SavingsInterest,Closing,Appropriation,Dividends,Dormancy}` | P4 | |
| `Group\{Cycles,Meetings,Fines,Funds,CashCount,ShareOut}` | P5 | |
| `Auth\MemberAuth` | OTP and token for members (§6.4) | `OtpService`, Sanctum |
| `SaccoSummary` | Dashboard figures for the portal and app home | |
| `App\Services\Billing\PlanLine` | The plan line (03 §5.1) | |

**Writes from the portal and the app go only through these services.** The portal's `$this->attempt()` and the API
controllers are thin.

---

## 5. Money paths

### 5.1 Posting rules (office mode CoA codes; group mode uses the same keys)

| Event | Debit | Credit | Sub-ledger |
|---|---|---|---|
| Cash deposit to savings | 1010 Cash (or the till's child account) | 21x0 product savings | savings account |
| Deposit by MoMo (collection succeeded) | 1160 MoMo clearing | 21x0 | savings account |
| Deposit by check-off | 1410 Check-off receivable (when the remittance is posted) | 21x0 | savings account |
| Withdrawal, cash | 21x0 | 1010 | savings account |
| Withdrawal by MoMo: sent | 21x0 | 2160 Payouts in transit | |
| Withdrawal by MoMo: confirmed | 2160 | 1150 MoMo float | |
| Withdrawal fee | 21x0 | 4420 Other fees | |
| Share purchase, cash | 1010 | 3100 Share capital | share account |
| Share transfer (member A → B) | 3100 (A) | 3100 (B) | both share accounts; counts move |
| Entrance fee | 1010 | 4400 Membership fees | |
| Internal transfer (own accounts) | 21x0 (from) | 21x0 (to) | both accounts |
| Loan disbursement to savings | 1300 Loans principal | 21x0 | loan; savings account |
| Loan disbursement, cash or bank | 1300 | 1010 / 1100 | loan |
| Loan disbursement by MoMo: sent | 1300 | 2160 | loan |
| Loan disbursement by MoMo: confirmed | 2160 | 1150 | |
| Processing fee (deducted at disbursement) | 21x0 or 1010 | 4200 Loan fees | |
| Interest due (per period, performing) | 1320 Interest receivable | 4100 Interest on loans | loan |
| Interest due (substandard or worse) | — memorandum only: `interest_in_suspense_minor` += x | — | loan (no GL until received, SI 51 reg. 41) |
| Penalty accrual (≤ ½ monthly rate × overdue principal) | 1330 Penalty receivable | 4110 Penalty interest | loan |
| Repayment (allocated penalty → fees → interest → principal) | 1010 / 1160 / 21x0 (from savings) / 1410 | 1330, 1340, 1320, 1300 (in that order) | loan (and savings when paid from savings) |
| Repayment of suspended interest | cash | 4100 directly (recognised on receipt) | loan; suspense reduced |
| Loan cancellation within cooling-off | 1010 / 21x0 | 1300; fee ≤ 2 % to 4200 | loan |
| Provision top-up (monthly, to the required level) | 5200 Provision for loan losses | 1390 Allowance (contra-asset) | none (portfolio-level; computed per loan for Form 1) |
| Write-off | 1390 | 1300, 1320, 1330 (remaining) | loan |
| Recovery after write-off | 1010 | 4500 Recoveries | loan |
| Guarantor offset on default | 21x0 (guarantor) | 1300 (borrower) | both |
| Fine charged (group mode) | 1440 Fines receivable | 4410 Fines | fine |
| Fine paid | 1020 Cash box | 1440 | fine |
| Social fund contribution | 1020 | 2510 Social fund | fund due |
| Savings interest accrual | 5100 Interest on savings | 2140 Interest payable | |
| Savings interest credited | 2140 | 21x0 | savings account |
| Year-end close | income accounts (4xxx) / 3410 | 3410 / expense accounts (5xxx) | none |
| Appropriation (after the AGM) | 3410 | 3200 Reserve, 2440 NCEF payable, 3300 STF, 3600, 2420 Dividends payable | none |
| Dividend paid to savings | 2420 | 21x0 | savings account |
| Opening balances (migration) | asset accounts / 3900 | 3900 / liability and equity accounts | per member |
| Offline receipt held (unknown or closed account) | 1010 | 2150 Unallocated receipts | none, until allocated |
| Allocation of a held receipt | 2150 | target account | target |
| Till variance (short) | 5730 Cash shortages | 10xx till | none |

All of these build a `JournalDraft` in `PostingRules` and go through `PostingService::post`. No other code writes
`sacco_journal_*` or the caches.

### 5.2 Withdrawal (online, maker-checker, concurrency-safe)

1. `POST sacco/withdrawals {uuid, savings_account_id, amount_minor, channel}`, with permission `sacco_withdraw`.
2. `SaccoTransactions::record` writes the document with status `pending_approval` when any of these holds:
   - the amount is above `withdrawal_approval_above_minor`;
   - the account is dormant (two officers, Tier 4 s.53);
   - the member has arrears or a guaranteed loan in arrears (s.55AK; P2).

   Otherwise it posts straight away.
3. An approval runs `execute`. Inside the posting transaction, the guard closure locks the savings account row and
   re-checks:
   - `amount ≤ available()`;
   - the product's monthly withdrawal count;
   - the notice days.

   A failure throws `insufficient_available` and nothing is written. The approval stays `approved, not executed`, and
   the officer sees the reason.
4. Cash is paid out by the teller. A MoMo withdrawal goes through `PayoutService` (P3).

### 5.3 Loan lifecycle (P2)

```
draft ─submit→ submitted ─appraise→ appraised ─(delegated limit ≥ amount & not insider)→ approved
                                              └─send→ in_committee ─majority→ approved | rejected ─appeal→ appealed ─board→ approved | rejected
approved ─contract+key facts generated→ ─signed (+ witness)→ signed  [cooling_off_ends_on = WorkingDays::add(signed_on, 5)]
signed ─disburse request→ (approval: maker ≠ checker) → disbursing → active   (MoMo: active on payout success)
active ─repayments→ closed (principal, interest, penalty and fees all 0)  | written_off | restructured (new loan, new schedule version)
signed/active within cooling-off ─cancel→ cancelled (principal back, fee ≤ 2 %)
```

**Interest (AK-39):**

- Each instalment's interest = `outstanding principal at the period start × periodic rate`, rounded half-up (AK-09).
  The periodic rate is the annual rate divided by 12, 26 or 52.
- It is due on the due date. `Accruals` posts it on the due date with idempotency key `int:{loan_id}:{v}:{n}`.
- Early settlement: interest only to the settlement day, `outstanding × annual_rate × days / 365`, and no fee
  (SI 51 reg. 39, SI 108 reg. 12).
- Flat (group mode only): `principal × rate × periods / periods`, per instalment.

**Penalty:**

- `Arrears` runs daily at 01:30 and computes `days_past_due` and `arrears_principal_minor`.
- On the 1st of each month, `Accruals` posts the penalty for the previous month on overdue principal only, pro-rata by
  days overdue: `overdue_principal × penalty_rate_bp × days / days_in_month`.
- Idempotency key `pen:{loan_id}:{yyyymm}`.
- The penalty never compounds.

**Classification and provisioning:**

- The day bands are the SI 51 table in `config('sacco.provisioning')`: 0 / 1–60 / 61–90 / 91–180 / >180 days, at
  1 / 5 / 25 / 50 / 100 %.
- The instalments test applies too: the worse of the two results wins.
- On entering substandard:
  - `npl_since` and `npl_cap_minor = principal_outstanding` are set;
  - later interest and penalty go to suspense;
  - interest plus fees after the NPL date never pass `npl_cap_minor`.
- Upgrades come only after 6 instalments of good performance, at most twice.
- `Provisioning` runs at month-end and posts the delta to the required total. Key `prov:{yyyymm}`.

---

## 6. Roles, permissions and positions

### 6.1 Company permissions (ADR 0004, `config/permissions.php`)

New permissions:

| Permission | Meaning |
|---|---|
| `sacco_view` | See the SACCO screens, members and balances |
| `sacco_members` | Register and edit members, nominees and documents |
| `sacco_kyc` | Verify KYC (checker of member capture) |
| `sacco_teller` | Record deposits, share purchases, fees and repayments; print receipts |
| `sacco_withdraw` | Request withdrawals |
| `sacco_approve` | Check other officers' requests: withdrawals, reversals, manual journals, periods |
| `sacco_loans` | Create, appraise and manage loan applications and guarantors |
| `sacco_loans_vote` | Vote on loans (also needs an active committee position) |
| `sacco_disburse` | Request disbursements and payouts |
| `sacco_accounts` | Chart of accounts, manual journals, periods, tills, statement import |
| `sacco_reports` | Reports, statements and returns |
| `sacco_settings` | SACCO settings and products |
| `sacco_officers` | Appoint and end officer positions |
| `sacco_audit_view` | Read the audit trail and everything else, read-only; export for audit |
| `sacco_meetings` | Run group meetings (P5) |

New roles, each carrying a new key `'module' => 'sacco'`. The Team screens (portal `Team`, API `team`, app) offer a
company only the roles whose module it has, plus `owner` and `viewer`.

| Role | Default permissions |
|---|---|
| `sacco_manager` (office manager or CEO) | view, members, teller, withdraw, approve, loans, disburse, accounts, reports, settings, officers |
| `sacco_chair` | view, approve, loans_vote, reports, officers, audit_view |
| `sacco_treasurer` | view, members, teller, withdraw, approve, disburse, accounts, reports |
| `sacco_secretary` | view, members, kyc, reports, meetings |
| `sacco_loans_officer` | view, members, teller, loans, reports |
| `sacco_committee` | view, loans_vote, reports |
| `sacco_auditor` (supervisory board or internal auditor) | view, reports, audit_view |
| `sacco_teller` | view, teller, withdraw |

`owner` (`*`) and `viewer` (`view_reports` plus `sacco_view` added for sacco companies) stay as they are.
`admin_roles` maps each new role to `shop_viewer` for the classic panel, which has no SACCO screens.

### 6.2 Officer positions (`sacco_officers`, AK-21)

A permission says **who may press the button**. A position says **who holds an office**:

- the credit committee's membership for majority votes;
- delegated approval limits;
- insider status;
- guarantor exclusion;
- the key holders and money counters in group mode;
- signatories on returns (the authorising and countersigning officers).

`SaccoAccess::canVote(user, loan)` requires all of these:

- `sacco_loans_vote`;
- an active `loans_committee` (or `board_member` on appeal) position;
- the voter is not the applicant;
- the voter is not related to the applicant (not recused).

### 6.3 Maps

- `ApiPermissionMap::RULES` gains one line per endpoint group (§13).
- `SyncApplier::permissionFor` gains `sacco_transactions` → `sacco_teller` and `sacco_members` → `sacco_members`
  (master; P5 adds meetings → `sacco_meetings`).
- `SaccoAccess::refuseSyncOp` refuses kinds that may not come from a phone (§9).

### 6.4 Members (self-service, AK-19)

Members are **not** staff users, because of the one-business-per-account rule and the unique phone numbers.

- `SaccoMember` uses Sanctum's `HasApiTokens`. Tokens carry the ability `sacco-member`.
- `POST /api/v1/sacco/member-auth/otp {phone}`: throttled 5 a minute and 10 an hour per phone. It sends an
  `OtpService` code (purpose `login`, the identifier scoped as `sacco:{phone}`) when at least one active member row
  has that phone.
- `POST …/verify {phone, code}` returns the memberships (SACCO name and member number, nothing else).
- `POST …/token {phone, code_token, member_id, device_name}` issues a token for that one membership.
- The app then asks for a 4–6 digit PIN (`PinLock`, required, AK-37).
- The `sacco.member` middleware:
  - requires the tokenable to be an active `SaccoMember`;
  - wraps the request in `CompanyScope::forRequest($member->company_id)`;
  - sets the actor for audit.
- `EnsureApiModule` gains a branch to resolve the company from a `SaccoMember` tokenable (additive).
- Members see only their own accounts, loans, guarantees and receipts. Every `/me` query is keyed by the token's
  member id, never by a request parameter.

---

## 7. Maker-checker flows

The rule (AK-22): **the checker is never the maker.** The owner is no exception. `SaccoApprovals::approve` refuses
when `user_id = requested_by_id` with `self_approval`. In group mode with `single_officer_mode`, self-approval is
allowed, stored as `self_approved = 1`, and shown on the audit report and the period-close summary.

| Action key | Maker (permission) | Checker | Default threshold | Offline |
|---|---|---|---|---|
| `withdrawal` | sacco_withdraw | sacco_approve | above `withdrawal_approval_above_minor`; always for dormant accounts or arrears | never |
| `reversal` (any posted transaction or journal) | any sacco permission that recorded it | sacco_approve | always | never |
| `manual_journal` | sacco_accounts | sacco_approve | always | never |
| `member_kyc` | sacco_members (capture) | sacco_kyc | always in office mode; off in group mode | capture yes, verify no |
| `held_receipt_allocation` | system (sync) | sacco_approve | always | — |
| `loan_decision` | sacco_loans (appraisal) | committee majority, or a position with `approval_limit_minor ≥ amount`; insiders always go to committee with recusal | always | never |
| `loan_disbursement` | sacco_disburse | sacco_approve (≠ the loan's appraiser) | always | never |
| `payout` (MoMo) | the action that caused it | inherited from the withdrawal or disbursement approval | — | never |
| `write_off` | sacco_loans | 2 approvals: chair and treasurer positions | always | never |
| `reschedule`, `top_up` | sacco_loans | committee, plus the borrower's written consent (SI 108 reg. 6) | always | never |
| `fee_waiver`, `penalty_waiver` | sacco_loans | sacco_approve | always | never |
| `period_close` | sacco_accounts | sacco_approve | always | never |
| `period_reopen` | sacco_accounts | sacco_approve **and** an auditor or chair position | always | never |
| `ledger_account_create` | sacco_accounts | sacco_approve | always | never |
| `product_terms_change` | sacco_settings | sacco_approve; savings rate cuts and loan terms take effect ≥ 30 days after the member notice is sent | always | never |
| `member_exit` | sacco_members | sacco_approve | always | never |
| `appropriation`, `dividend_run` | sacco_accounts | 2 approvals (chair, treasurer), plus the "Registrar consent obtained" upload for a dividend | always | never |
| `shareout` (group) | sacco_meetings | sacco_approve (or the meeting's two money counters) | always | never |
| `officer_change` | sacco_officers | no checker; every active officer is notified and it is audited | — | never |

Every approval:

- freezes its payload and payload hash, so the checker approves exactly what executes;
- expires after 7 days;
- executes in the same transaction as its journal;
- is idempotent: executing twice returns the first result.

---

## 8. Audit log (AK-33)

- **Model diffs:** `App\Traits\AuditLogger` goes on every `Sacco*` master model (settings, officers, members,
  relations, documents, products, accounts, loans' workflow columns, approvals). It writes `audit_logs` (`model_type`,
  `model_id`, `action`, `old_values`, `new_values`, ip, user agent, url, `company_id`).
- **NIN and documents:**
  - `filterSensitiveData` lists `nin_encrypted`, so the NIN never appears in the log;
  - viewing a NIN or downloading an ID document writes action `sacco.sensitive_view`.
- **Business actions:** a thin `SaccoAudit::record(string $action, Model $subject, array $context)` writes the same
  `audit_logs` table with dotted actions:
  - `sacco.approval.requested`, `.approved`, `.rejected`;
  - `sacco.loan.voted`, `sacco.loan.recused`;
  - `sacco.member.exported`, `sacco.statement.sent`;
  - `sacco.member_login` (with member id), `sacco.period.closed`;
  - `sacco.ledger.verify_failed`.
- **The money itself** is its own audit: journals are append-only and hash-chained (ADR 0009 §6). An auditor checks
  the chain with `sacco:ledger-verify --company=ID` or the portal's Audit › Ledger check.
- **Portal screen:** Audit trail, under `sacco_audit_view`. It filters `audit_logs` by `company_id` and the `Sacco*`
  model types and `sacco.*` actions, and exports to XLSX.
- **Retention:** ledger and member accounts are kept 10 years (SI 51 reg. 33), then de-identified by a P6 command.
  Audit logs are kept as long as the ledger.

---

## 9. Offline rules and sync (ADR 0001)

### 9.1 What a phone may do offline

| May do offline | How | Server rule on push |
|---|---|---|
| Record a **cash** deposit, share purchase, entrance fee or fee (office and group); a loan repayment in cash (P2); a fine payment and fund contribution (P5) | Inserts `sacco_transactions` (event kind, uuid) and the phone's own receipt number; prints or shares the receipt locally | Idempotent by uuid (`replayed`). Posts through `SaccoTransactions::applySynced`. **A cash receipt is never refused** (cash was physically taken, the shop's "completed sales are never rejected"). Unknown or closed account, a closed loan, or amount > `offline_receipt_max_minor` → posted to 2150 Unallocated receipts with `status = held` and a conflict-inbox item and approval `held_receipt_allocation` (AK-24). Value date = the phone's local day; entry date = today (an open period). |
| Register a member (group mode; office mode as `applicant`) | `sacco_members` master row | ADR 0007: kept; `DuplicateService` flags twins by phone, NIN hash and name. Server assigns `member_no`. |
| Start a loan application draft | `sacco_loans` with status `draft` (master) | Only `draft` may come from a phone; `submitted` and later are API-only |
| Meeting steps, attendance, fines owed, cash counts (P5) | Their tables | Fines become journals when the meeting is **submitted online** |
| Read balances | Pulled snapshots | Shown as "at {last sync}" + local unsynced receipts as "not yet sent" |

### 9.2 What a phone may never do offline (AK-23)

These are refused by `SaccoAccess::refuseSyncOp` with `offline_not_allowed`:

- withdrawals;
- disbursements;
- any approval or vote;
- reversals;
- manual journals;
- MoMo or bank channels (only `cash` may be pushed);
- internal transfers;
- KYC verification;
- loan submission or decision;
- period actions;
- share transfers;
- product or settings changes.

The app does not show these buttons without a connection. It says "Needs the internet: this moves money out or
approves it."

### 9.3 Registry entries

| Table | Kind | Writable fields | Handler | Permission |
|---|---|---|---|---|
| `sacco_members` | master | names, gender, dob, phones, address, occupation, kind, joined_on, consent timestamps (not `nin_*`: the NIN is sent through the API only, never stored on the phone, AK-31) | — | sacco_members |
| `sacco_transactions` | event | kind, channel, member_uuid, account uuids, amount_minor, fee_minor, value_date, narration, reference | `sacco_transaction` in `SyncApplier::applyEvent` (modelled on `applyContributionPayment`) | sacco_teller |
| `sacco_loans` (draft only) | master | product, applied_minor, purpose, term | — | sacco_loans |
| `sacco_savings_accounts`, `sacco_share_accounts`, `sacco_savings_products`, `sacco_loan_products`, loan summaries, `sacco_fine_types`, `sacco_funds`, `sacco_cycles` | reference / snapshot (pull only) | — | — | sacco_view |
| `sacco_meetings`, `sacco_meeting_attendance`, `sacco_fines`, `sacco_cash_counts` (P5) | event / master | | `sacco_meeting` | sacco_meetings |

Phone side: `SyncTableSpec`s in `lib/sync/sync_tables.dart` with `optional: true`, so the other flavors skip them.
`DERIVED_COLUMNS` includes every `*_minor` cache, `status` (except on drafts), `journal_entry_id` and `member_no`.

---

## 10. Messaging

- **The pipe:** everything goes through `Messenger::send` (log driver in dev and tests; `message_log` rows; demo
  companies skipped; queued `SendMessageJob`). Never directly to a driver.
- **Sending:** `SaccoNotices` dispatches **after commit** (`DB::afterCommit`), so a rolled-back posting never sends a
  receipt.
- **Consent:**
  - `meta.sacco_member_id` lets `Messenger::consentRefusal` read `sacco_members.messages_opt_out_at` (an additive
    branch beside the customer check);
  - transactional receipts are sent unless the member opted out;
  - reminders need `consent_messages_at`.
- **Templates** live in `App\Services\Sacco\Messaging\SaccoMessages`:
  - English and Kiswahili, Luganda falling back to English until translated;
  - placeholders only;
  - each 160 characters or fewer for SMS, with a WhatsApp variant;
  - the SACCO's short name, never the platform's.

  | Key | When | Text (EN) |
  |---|---|---|
  | `receipt_deposit` | posted | "{sacco}: received {amount} to {account} on {date}. Balance {balance}. Rct {receipt}." |
  | `receipt_withdrawal` | posted | "{sacco}: paid {amount} from {account}. Balance {balance}. Rct {receipt}." |
  | `receipt_shares` | posted | "{sacco}: {count} shares bought, {amount}. You hold {total} shares. Rct {receipt}." |
  | `receipt_repayment` | posted | "{sacco}: loan {loan} repayment {amount}. Still owed {outstanding}. Rct {receipt}." |
  | `loan_approved` | approved | "{sacco}: loan {loan} of {amount} approved. Sign within {days} days. Total cost {cost}." |
  | `loan_disbursed` | active | "{sacco}: {amount} paid to {channel}. First instalment {due} on {date}. You may cancel within 5 working days." |
  | `due_reminder` | 3 days before | "{sacco}: instalment {due} on loan {loan} is due {date}." |
  | `overdue_notice` | days 1, 7, 30 | "{sacco}: loan {loan} is {days} days overdue: {arrears}. Please pay or talk to us." (SI 108 reg. 13: never messages anyone but the borrower and guarantors) |
  | `guarantor_request` | on add | "{sacco}: {borrower} asks you to guarantee {amount} on a loan of {loan_amount}. You may be liable for up to {amount}. Open {link} to read and reply." |
  | `guarantor_exposure` | default or offset | "{sacco}: {borrower}'s loan is in arrears. Up to {amount} of your savings may be used." |
  | `statement_link` | monthly, opt-in | "{sacco}: your statement for {month}: {link} (opens for 7 days)." |
  | `meeting_reminder` (P5) | the day before | "{group}: meeting {n} tomorrow {time}." |

- **Channel choice:**
  - `sacco_settings.receipts_channel`;
  - SMS only with plan feature `sacco_sms_receipts` and within `max_sms_per_month`, otherwise WhatsApp (one-tap
    share from the officer's phone, as Pledge & Budget does) or in-app.
  - A receipt in the member app counts as the e-receipt (SI 108 reg. 16).
- **Links:**
  - statement and guarantor-consent links are signed routes (the `EventPrintController` 30-minute pattern, extended
    to 7 days for statements);
  - each carries only an opaque token, never a name, amount or phone number in the URL.

---

## 11. Mobile money

### 11.1 Collections (P3)

- **Reuse:**
  - `FlutterwaveService::chargeMobileMoney` through a Flutterwave subaccount per SACCO, on the SaaS pattern of
    `MomoCollections::setup`;
  - `momo_requests` with `purpose = sacco_*`;
  - the existing webhook route, with signature verification by `verifyWebhookSignature`.
- **Settling:**
  - `SaccoCollections::settle(txRef)` verifies by reference (`transactionSatisfies` checks the amount and currency);
  - it then records a `sacco_transactions` row with channel `momo` and idempotency key `momo:{tx_ref}`, so the
    webhook and the poll can both arrive safely.
- **The money:** it lands in the SACCO's own subaccount. The platform never holds SACCO funds. 1160 MoMo clearing is
  cleared to the bank by a statement-import match or a manual journal.
- **Dev and tests:**
  - `SimulatedFlutterwave` (approves after 6 s; a phone ending in 000 is declined) is bound locally with
    `billing.fake_payments`;
  - tests use `FakeFlutterwaveService`;
  - the config doctor fails production when it is bound.

### 11.2 Disbursements and payouts (P3, AK-17)

```php
namespace App\Services\Sacco\Payouts;

interface PayoutGateway {
    public function key(): string;                                    // 'simulated' | 'flutterwave'
    public function validateAccount(string $e164, string $network): AccountCheck; // name on the wallet, if the provider gives it
    public function send(PayoutRequest $r): PayoutResult;             // idempotent on $r->idempotencyKey
    public function status(string $providerRef): PayoutResult;
}
```

- **Driver:** `config('sacco.payouts.driver')`, one of `off` (the production default until the owner adds keys),
  `simulated` (local and tests) or `flutterwave`.
- **`SimulatedPayouts`:**
  - resolves `successful` after a configurable delay;
  - a phone ending in `000` fails, and one ending in `999` returns `unknown` (tests the poll);
  - it never makes network calls.
- **`FlutterwavePayouts`:** implemented against the Transfers API with `Http::fake` in tests. It is not used until keys
  exist.
- **The config doctor** fails production with `simulated`. With `off`, the MoMo channel is hidden on disbursement and
  withdrawal.
- **`PayoutService` flow:**
  1. Approval executed.
  2. Journal "sent" (Dr loan or savings, Cr 2160 in transit), key `payout:{id}:sent`.
  3. `send`.
  4. On `successful`: journal "settled" (Dr 2160, Cr 1150), key `payout:{id}:settled`.
  5. On `failed`: `ReversalService` reverses the sent journal (key `payout:{id}:failed`), the loan goes back to
     `signed`, the withdrawal to `rejected`, and the treasurer is told.
  6. On `unknown`: polled by `sacco:payouts-poll` every 5 minutes up to 24 h, then left for manual resolution.
  7. Retries reuse the same provider idempotency key.

---

## 12. Reports

All reports are computed from the ledger and the sub-ledgers, never from stored totals.

- **Registry:** they are registered in `App\Services\Sacco\Reports\SaccoReports::REPORTS` (key → class, permission,
  formats), in the style of `ReportService::REPORTS`.
- **Formats:** JSON for the screens, PDF through dompdf (an `InvoicePdf`-style view with the SACCO's name, CS No. and
  licence, and the "Regulated by …" line only when licensed), XLSX through `XlsxWriter`, CSV.
- **Delivery:** downloads come from signed links (30 minutes).

| Key | Phase | Contents |
|---|---|---|
| `member_statement` | P1 | Per account: opening, each line (date, value date, receipt, narration, debit, credit, running balance), closing. Shares, savings and loans together or per account. |
| `trial_balance` | P1 | Per account: opening, debits, credits, closing for a date range. Must total 0 (the report shows the check). |
| `cash_book` / `day_book` | P1 | All entries touching cash, bank and MoMo accounts, or all entries for a day, with the officer |
| `member_register` | P1 | Reg. 12 fields: number, name, address, occupation, date admitted, shareholding, date ceased, nominees |
| `share_register` | P1 | Member, shares, value, % of capital (flags > ⅓) |
| `savings_balances` | P1 | Per product and account, with available balance and holds |
| `approvals_log` | P1 | Maker, checker, self-approvals |
| `portfolio`, `par`, `aging` | P2 | Outstanding principal, PAR1/PAR30/PAR60/PAR90 (principal of loans with any arrears > N days ÷ gross portfolio), aging in Form 1 bands, by product, officer and classification |
| `arrears_list`, `disbursements`, `repayments`, `expected_vs_collected` | P2 | Collections work lists |
| `loan_statement`, `schedule` | P2 | Per loan |
| `guarantor_exposure` | P2 | Per guarantor: guarantees, held savings, borrowers' status |
| `checkoff_schedule`, `checkoff_variances` | P3 | Per employer and month |
| `till_eod`, `momo_reconciliation` | P3 | |
| `balance_sheet`, `income_statement` | P4 | By `umra_line` mapping, current and prior period |
| `umra_f1` … `umra_f7`, `umra_savings_statement` | P4 | Exact Schedule 4 line order and labels (02 §1.3). Form 3 counts current-year surplus at 50 %; Form 4 ratio = net liquid assets ÷ (6.3 + 7.3). Signatory blocks filled from officer positions. Generated files are stored in `sacco_return_filings`. |
| `registrar_annual_return`, `s55as_quarterly` | P4 | |
| `ratios` | P4 | Core capital/assets ≥ 10 %, institutional ≥ 8 %, core/deposits ≥ 8 %, liquidity ≥ 15 % (weekly, last working day), 20 % of savings in bank or securities, external borrowing ≤ 25 %, non-earning ≤ 10 %, investments ≤ 40 % of core capital, and the BoU threshold warning (savings → 1.5 bn, institutional capital → 500 m) |
| `dividend_run`, `appropriation`, `agm_pack` | P4 | |
| `insider_loans`, `dormant_register`, `membership_stats` (gender, age, youth, active, dormant, new, exited) | P4 | |
| `shareout_statement`, `meeting_summary`, `who_paid` (WhatsApp text) | P5 | |

**UMRA mapping:**

- each CoA account carries `umra_line`;
- a form line is the sum of its accounts, or a sub-ledger query where the form needs loan-level splits (Form 1
  classes, rescheduled loans, insiders);
- `UmraReturnsTest` builds a fixed fictional SACCO ledger and asserts every line of every form against hand-computed
  figures.

---

## 13. API v1

### 13.1 Groups

```php
// Staff
Route::prefix('sacco')->middleware(['auth:sanctum','api.tenant','api.module:sacco','api.subscription','api.perm','api.idempotent'])
// Members (self-service)
Route::prefix('sacco/me')->middleware(['auth:sanctum','sacco.member','api.module:sacco','api.idempotent','throttle:60,1'])
// Member sign-in (public)
Route::prefix('sacco/member-auth')->middleware(['edition:saas','throttle:10,1'])
```

**Conventions:**

- Money is sent and returned as `*_minor` integers plus `currency`. The API never accepts a decimal amount for Akiba
  (`SaccoRules` refuses non-integers with 422).
- Every write takes an `Idempotency-Key` header (`IdempotentRequests`, 24 h), and money writes also take a body `uuid`
  that becomes the document uuid.
- Errors use `BusinessRuleException` codes:
  - `insufficient_available`, `account_frozen`, `module_exclusive`, `plan_limit_reached`;
  - `self_approval`, `approval_expired`, `period_closed`, `offline_not_allowed`;
  - `share_cap_exceeded`, `flat_interest_not_allowed`;
  - `penalty_rate_too_high`, `cost_cap_exceeded`;
  - `guarantor_not_allowed`, `guarantee_capacity`, `cooling_off_active`, `loan_open`, `liquidity`,
    `single_borrower_limit`, `idempotency_conflict`.
- `php artisan api:docs` regenerates `docs/openapi.json` and the Postman collection, and `OpenApiTest` covers them.

### 13.2 Endpoints

| Phase | Method and path | Permission | Notes |
|---|---|---|---|
| P1 | `POST sacco/setup` | owner | Once. Mode, names, currency, defaults. Seeds the CoA. |
| P1 | `GET/PUT sacco/settings` | sacco_view / sacco_settings | Mode is locked after the first posting |
| P1 | `GET sacco/overview` | sacco_view | `SaccoSummary`: members, savings, shares, cash position, pending approvals, (P2) PAR30 |
| P1 | `apiCrud sacco/officers` (+ `POST {id}/end`) | sacco_officers | |
| P1 | `GET/POST sacco/members`, `GET/PUT sacco/members/{id}` | sacco_view / sacco_members | Search by name, number, phone, NIN (hashed lookup) |
| P1 | `POST sacco/members/{id}/kyc` `{decision}` | sacco_kyc | Checker ≠ capturer |
| P1 | `POST sacco/members/{id}/status` | sacco_members | applicant → active needs the entrance fee and min shares (setting) |
| P1 | `apiCrud sacco/members/{id}/relations`, `POST sacco/members/{id}/documents` | sacco_members | Upload through `FileController` rules |
| P1 | `POST sacco/members/import` (CSV) | sacco_members | Dry-run first; `max_members` checked |
| P1 | `POST sacco/opening-balances/import` | sacco_accounts | Opening-balance journals against 3900 (with approval) |
| P1 | `GET sacco/members/{id}/statement?from&to&format` | sacco_reports (or self) | |
| P1 | `GET sacco/share-accounts`, `POST sacco/shares/purchase`, `POST sacco/shares/transfer` | sacco_view / sacco_teller / sacco_approve | |
| P1 | `apiCrud sacco/savings-products` | sacco_settings | Changes create versions and notices |
| P1 | `GET/POST sacco/savings-accounts`, `POST sacco/savings-accounts/{id}/{freeze,unfreeze,close}` | sacco_view / sacco_teller / sacco_approve | |
| P1 | `POST sacco/transactions` | sacco_teller | Deposit, share purchase, fees, internal transfer |
| P1 | `GET sacco/transactions`, `GET sacco/transactions/{id}`, `GET sacco/transactions/{id}/receipt` | sacco_view | Receipt as PDF or ESC/POS text for the Bluetooth printer |
| P1 | `POST sacco/withdrawals` | sacco_withdraw | §5.2 |
| P1 | `POST sacco/transactions/{id}/reverse` `{reason}` | sacco_teller | Creates an approval |
| P1 | `GET sacco/approvals?status&action`, `POST sacco/approvals/{id}/{approve,reject,cancel}` | sacco_approve (cancel: the maker) | |
| P1 | `GET/POST sacco/ledger/accounts`, `PUT sacco/ledger/accounts/{id}` (name, active, umra_line) | sacco_view / sacco_accounts | Creating an account needs an approval |
| P1 | `GET sacco/ledger/journal`, `GET sacco/ledger/journal/{id}`, `POST sacco/ledger/journal` (manual → approval), `POST sacco/ledger/journal/{id}/reverse` | sacco_accounts | |
| P1 | `GET sacco/periods`, `POST sacco/periods/{id}/close`, `POST sacco/periods/{id}/reopen` | sacco_accounts | Through approvals |
| P1 | `GET sacco/reports`, `GET sacco/reports/{key}?…&format=json\|pdf\|xlsx\|csv` | sacco_reports | Downloads redirect to a signed link |
| P1 | `GET sacco/audit` | sacco_audit_view | |
| P1 | `GET sacco/ledger/verify` | sacco_audit_view | Runs `LedgerVerifier` for this SACCO |
| P1 | sync: existing `POST sync/push`, `GET sync/pull` | per table | New tables (§9.3) |
| P2 | `apiCrud sacco/loan-products`, `POST sacco/loan-products/{id}/preview` | sacco_settings / sacco_view | Preview = schedule + key facts (total cost, APR) |
| P2 | `GET/POST sacco/loans`, `GET sacco/loans/{id}` | sacco_view / sacco_loans | |
| P2 | `POST sacco/loans/{id}/{submit,appraise,send-to-committee,decide,appeal,contract,sign,cancel,disburse}` | sacco_loans / sacco_approve / sacco_disburse | `decide` only within a delegated limit |
| P2 | `POST sacco/loans/{id}/votes` | sacco_loans_vote + position | Recusal recorded |
| P2 | `apiCrud sacco/loans/{id}/guarantors`, `POST sacco/loans/{id}/guarantors/{g}/consent` (officer records a signed form) | sacco_loans | |
| P2 | `apiCrud sacco/loans/{id}/collateral` | sacco_loans | |
| P2 | `POST sacco/loans/{id}/repayments` | sacco_teller | Cash, bank or from savings |
| P2 | `GET sacco/loans/{id}/{schedule,statement,settlement-quote}` | sacco_view | |
| P2 | `POST sacco/loans/{id}/{top-up,reschedule,write-off,recover,waive}` | sacco_loans | Through approvals |
| P2 | `GET sacco/guarantees?member_id` | sacco_view | |
| P2 | `POST sacco/members/{id}/exit` | sacco_members | Through an approval |
| P2 | `GET/POST public signed: /sacco/consent/{token}` (portal web route, not API) | — | Guarantor reads the notice and consents with an OTP |
| P3 | `POST sacco/collections` `{member_id, purpose, account, amount_minor, phone}` | sacco_teller | Request-to-pay |
| P3 | `GET sacco/collections/{id}` | sacco_view | |
| P3 | `GET sacco/payouts`, `POST sacco/payouts/{id}/retry` | sacco_view / sacco_disburse | |
| P3 | `apiCrud sacco/employers`, `POST sacco/checkoff/batches` (generate), `POST sacco/checkoff/batches/{id}/remittance` (import), `POST sacco/checkoff/batches/{id}/post`, `GET sacco/loans/{id}/stop-letter` | sacco_accounts | |
| P3 | `apiCrud sacco/tills`, `POST sacco/tills/{id}/{open,close}` | sacco_teller / sacco_approve (variance) | |
| P3 | `POST sacco/statement-imports`, `POST sacco/statement-imports/{id}/match` | sacco_accounts | |
| P3 | `POST sacco/member-auth/{otp,verify,token}`, `POST sacco/me/logout` | public, throttled | §6.4 |
| P3 | `GET sacco/me`, `GET sacco/me/accounts`, `GET sacco/me/statement`, `GET sacco/me/loans`, `GET sacco/me/loans/{id}/schedule`, `GET sacco/me/receipts/{id}` | member | Own data only |
| P3 | `POST sacco/me/collections`, `POST sacco/me/withdrawal-requests`, `POST sacco/me/loan-applications` | member | Creates requests; never posts money directly |
| P3 | `GET sacco/me/guarantee-requests`, `POST sacco/me/guarantee-requests/{id}/{consent,decline}` | member | Consent with a fresh OTP (written e-consent) |
| P3 | `PUT sacco/me/consents` | member | Messages, credit bureau |
| P4 | `GET sacco/returns`, `POST sacco/returns/{id}/generate`, `POST sacco/returns/{id}/mark-filed` | sacco_reports / sacco_accounts | |
| P4 | `POST sacco/interest-runs` (preview, post), `POST sacco/year-end/{year}/{close,appropriate}`, `POST sacco/dividend-runs` (preview, post) | sacco_accounts | Through approvals |
| P5 | `apiCrud sacco/cycles`, `apiCrud sacco/meetings` (+ `attendance`, `submit`), `apiCrud sacco/fine-types`, `GET sacco/fines`, `apiCrud sacco/funds`, `POST sacco/cash-counts`, `POST sacco/shareouts` (preview, post) | sacco_meetings / sacco_approve | |

### 13.3 Billing touches (03 §5.1)

- `PlanLine` filters on `GET billing/plans` (API), the classic billing screens and `Quotas::planThatFits`.
- `RegistrationService` and `Lifecycle::freePlan` pick by line.
- `Quotas::KEYS` gains `members` and `sms`.
- `MarketingController` pricing on the Akiba site shows `akiba-*` plans and `plan_rows = ['max_members', 'max_users', 'max_devices', 'max_sms_per_month']`.

---

## 14. Portal (P)

### 14.1 Routes

They go in a new `// ── [module:sacco]` marker block inside the `['auth','shop','no-store']` → `shop.access` group:

```php
Route::middleware('module:sacco')->prefix('sacco')->name('sacco.')->group(function () { … });
```

### 14.2 Screens

They are under `App\Livewire\Shop\Sacco\` and follow `shop-conventions.md`: `WithTable`, peek with
`PeeksAndEditsRecords`, `CrudModal`, `x-ui.*`, `$this->attempt(fn, 'toast', 'permission')`, `@money` with an Akiba
directive `@minor($minor)` that formats through `Money::format(Amount::…->toMajorString())`, and `#[Locked]` ids.

| Route name | Component | Phase | Main action |
|---|---|---|---|
| `sacco.overview` | `Overview` | P1 | "Receive money". Cards: members, savings, shares, cash today, approvals waiting, (P2) PAR30, (P4) returns due |
| `sacco.setup` | `Setup` | P1 | The first-run wizard: mode, names, currency check, defaults, officers |
| `sacco.members` | `Members\Index` (+ peek, create, import) | P1 | "Add member"; `?peek=`, `?new=1`, `?scope=applicant\|dormant` |
| `sacco.members.show` | `Members\Show` | P1 | Tabs: accounts, statement, nominees, documents, loans (P2), guarantees (P2) |
| `sacco.teller` | `Teller` | P1 | Find a member, then deposit, shares or fee (repayment in P2), then receipt. Keyboard-first. |
| `sacco.withdrawals` | `Withdrawals` | P1 | Request; the list of mine |
| `sacco.approvals` | `Approvals` | P1 | The inbox: approve or reject with the frozen payload shown in full |
| `sacco.savings` | `Savings\Accounts`, `Savings\Products` | P1 | |
| `sacco.shares` | `Shares` | P1 | Register and transfers |
| `sacco.accounts` | `Ledger\Accounts` | P1 | Chart of accounts (tree, balances) |
| `sacco.journal` | `Ledger\Journal` (+ manual entry modal) | P1 | Read-only list; "New journal" creates a draft for approval |
| `sacco.periods` | `Ledger\Periods` | P1 | Close and reopen through approvals |
| `sacco.reports` | `Reports` | P1+ | The registry list with filters; exports |
| `sacco.officers` | `Officers` | P1 | |
| `sacco.settings` | `Settings` | P1 | Profile, licence, regulator line, receipts, thresholds |
| `sacco.audit` | `Audit` | P1 | Audit trail and ledger check |
| `sacco.loans` | `Loans\Index` (pipeline by status) | P2 | "New application" |
| `sacco.loans.show` | `Loans\Show` | P2 | Appraisal, guarantors, votes, contract, disburse, schedule, repayments |
| `sacco.loan-products` | `Loans\Products` (with calculator) | P2 | |
| `sacco.collections` | `Payments\Collections`, `Payments\Payouts` | P3 | |
| `sacco.checkoff` | `Checkoff` | P3 | |
| `sacco.tills` | `Tills` | P3 | |
| `sacco.reconcile` | `Reconcile` | P3 | |
| `sacco.returns` | `Returns` (calendar) | P4 | |
| `sacco.year-end` | `YearEnd` | P4 | |
| `sacco.meetings`, `sacco.cycles`, `sacco.funds`, `sacco.shareout` | `Group\*` | P5 | |
| `/sacco/consent/{token}` (public, signed, throttled) | `GuarantorConsent` (a plain view) | P2 | |

### 14.3 Shell, CSS and tests

- **Shell:**
  - `App\Shop\Navigation` gains the sacco section when the module is on;
  - for a sacco-only company, `dashboard` redirects to `sacco.overview`;
  - the shop's dashboard cards stay out.
- **CSS:** `resources/css/shop/sacco.css`, tokens only, `bp-` prefix, dark mode. The accent comes from the app's
  `accent` token when on the Akiba host (the existing per-app accent mechanism). `PageStyles` lists the routes.
- **Tests:**
  - `tests/Feature/Shop/Sacco/*Test.php` with a new `Tests\Concerns\MakesSacco` (`saccoAccount($mode)`,
    `saccoMember()`, `deposit()`, `officer($role)`);
  - every screen proves the five conventions (roles and 403, parity with the kernel service, refusals as toasts,
    tenant isolation, grace read-only);
  - Playwright `shot.js` at 390/768/1366 in light and dark, with a page-error sweep and axe (per the memory rule: one
    pass per UI change).

---

## 15. App (A) and site (P)

### 15.1 Flavor `akibaledger`

- **Gradle:** `android/app/build.gradle`:

  ```gradle
  productFlavors { akibaledger { dimension "app"; applicationId "com.solavia.akibaledger"; resValue "string","app_name","Akiba Ledger" } }
  ```

- **Resources:** `android/app/src/akibaledger/res/` (icons from `docs/flavor-icons/render.py` with the new glyph),
  plus `src/akibaledger/AndroidManifest.xml` removing `USE_EXACT_ALARM` as Shelfie does.
- **Dart:**

  ```dart
  static const akibaLedger = AppFlavor(
    key: 'akibaledger', name: 'Akiba Ledger', taglineKey: 'fam_tagline_akiba',
    accent: Color(0xFF2B3A8F), slug: 'akiba-ledger', applicationId: 'com.solavia.akibaledger',
    icon: Icons.account_balance_rounded, logoAsset: 'assets/flavors/akibaledger.png',
    home: FlavorHome.module, modules: ['sacco'], module: 'sacco', businessType: 'sacco',
    signUpModules: ['sacco'], createKey: 'fam_create_sacco', businessNameKey: 'fam_name_sacco',
    homeTabKey: 'fam_tab_sacco', demo: false, menuKeys: _accountMenu, intro: [ … 3 slides … ],
  );
  ```

  `all` gains it, and `test/family/flavor_test.dart` checks Gradle ↔ Dart.
- **Strings:** `fam_*_sacco` keys in `lib/l10n/strings_family.dart` (EN, SW; LG later).

### 15.2 Site: the `config/apps.php` entry `akiba-ledger`

| Key | Value |
|---|---|
| `name`, `slug`, `flavor` | `Akiba Ledger`, `akiba-ledger`, `akibaledger` |
| `domain` | `env('APP_AKIBA_LEDGER_DOMAIN','')` |
| `tagline` | "Savings, shares and loans, in books that balance." |
| `audience` | "SACCOs and savings groups" |
| `modules`, `business_types` | `['sacco']` |
| `icon` | `landmark` |
| `accent`, `accent_soft` | `#2b3a8f`, `#e8eaf6` |
| `application_id`, `play_url` | `env('APP_AKIBA_LEDGER_ANDROID_ID','')`, `env('APP_AKIBA_LEDGER_PLAY_URL','')` |
| `category`, `sub_category` | `FinanceApplication`, "SACCO and savings group management" |
| `plan_rows` | `['max_members','max_users','max_devices','max_sms_per_month']` |
| `register` | "Set up your SACCO", "SACCO or group name", "e.g. Kisoro Teachers' SACCO", "Set up my SACCO" |
| `faq` | Honest; P1 says what works today |

- **Copy:** `resources/content/sites/akiba-ledger.php`.
- **Page:** `resources/views/marketing/apps/akiba-ledger.blade.php`, from the shared partials.
- **Tokens:** `[data-app="akiba-ledger"]{--accent:#2b3a8f;--accent-soft:#e8eaf6;--accent-d:#222e72;--accent-dd:#172050;--accent-line:#b9c0e3;--glow:rgba(43,58,143,.24);--glow-2:rgba(43,58,143,.08);}`, with a dark-mode tint (proposed `#9aa7f0`) that `SiteTest` checks at 4.5:1 on the dark paper.

### 15.3 App screens (`lib/sacco/`)

- **Data and sync:**
  - `data/` holds the repos over sqflite, the `SyncTableSpec`s, and money as `int`, formatted with
    `Money.minor(int, currency)` in `sacco_format.dart`;
  - `services/` holds `SaccoApi` (`/api/v1/sacco/*`) and `MemberSession` (member tokens in `SecureSession`, kept
    apart from the staff session).
- **Officer screens:**
  - P1: home (today's receipts, unsynced count, approvals waiting); members (search; detail with balances "at last
    sync"); Receive money (offline; receipt printed or shared); my receipts; approvals (online).
  - P2: loans (read, apply as a draft, repay in cash offline).
  - P5: meeting flow.
- **Member mode** (P3): on sign-in, "I'm an officer" (the family sign-in) or "I'm a member" (phone → OTP → pick a
  SACCO → PIN). Member screens:
  - balances;
  - statement;
  - loan and schedule;
  - pay by MoMo;
  - guarantee requests;
  - apply for a loan;
  - request a withdrawal;
  - receipts.
- **Security (AK-37):**
  - the PIN is required in this flavor (`AppLock` forced on);
  - no NIN, document or photo is cached;
  - "Sign out of this phone" wipes `sacco_*` local tables.

---

## 16. Scheduler and commands (ADR 0008: cron + database queue)

| Command | When | What |
|---|---|---|
| `sacco:ledger-verify` | daily 03:10, `withoutOverlapping` | All companies with sacco. Failures go to the owner and platform via `Notifier`, and onto System health. |
| `sacco:daily` | daily 01:30 | Interest due on due dates, arrears and DPD, classification, due reminders, expiring approvals, dormancy flags, payout poll fallback |
| `sacco:month-end` | the 1st, 02:30 | Penalty for the previous month, provisioning, savings interest accrual, open next month's period, returns calendar reminders (the 10th and 14th) |
| `sacco:payouts-poll` | every 5 min | `unknown` payouts |
| `demo:public` | hourly :05 (existing) | Also heals the Akiba demo (AK-35) |

Every job is idempotent through journal idempotency keys. A missed night catches up month by month (the MobiSave
catch-up, KEEP), never double-posts, and is stamped on the scheduler heartbeat.

## 17. Configuration (`config/sacco.php`, values from env; no secrets in git)

```php
return [
  'regulator' => ['name' => env('SACCO_REGULATOR_NAME','Uganda Microfinance Regulatory Authority'), 'short' => env('SACCO_REGULATOR_SHORT','UMRA'), 'portal' => env('SACCO_REGULATOR_PORTAL','')],
  'plans' => ['trial' => 'akiba-trial', 'free' => 'akiba-free'],
  'payouts' => ['driver' => env('SACCO_PAYOUTS_DRIVER','off')],   // off | simulated | flutterwave
  'nin_pepper' => env('SACCO_NIN_PEPPER'),                        // config:doctor: required in production
  'provisioning' => [['performing',0,0,100],['watch',1,60,500],['substandard',61,90,2500],['doubtful',91,180,5000],['loss',181,null,10000]],
  'defaults' => [/* 02 §7.3: par, min shares, entrance fee, multiplier 30000, office rate 150/200 bp monthly, penalty = 50 % of rate, fee 100 bp, guarantors 2, dormancy 24 … */],
  'country' => 'UG',
];
```

`.env.example` in the kernel gains the keys with empty values. `config:doctor` fails production when:

- `SACCO_PAYOUTS_DRIVER=simulated`;
- `SACCO_NIN_PEPPER` is empty;
- a company has sacco enabled while messaging is `log`, unless that is accepted with `CONFIG_DOCTOR_ACCEPT`.

## 18. Security and data protection

- **Tenancy:**
  - `CompanyScope` on every `Sacco*` model (except the global holidays);
  - `TenantModelsTest` scans them;
  - `TenantIsolationTest` gains every `sacco/*` `{id}` route (tenant B gets 404);
  - member tokens get the same tests (member A can never read member B in the same SACCO or another).
- **Data protection (AK-47):**
  - **NIN:** encrypted at rest (AK-31) and never in logs, exports or the phone. Exports that need it (the UMRA
    committee register) decrypt on demand and are audited.
  - **Consent:** the consent text (DPPA s.7, s.9, s.19) is shown at registration and stored with a version.
  - **Retention:** 10 years, then a de-identification command (P6).
  - **Breaches:** a breach-notification runbook goes in `docs/runbooks/` (P6).
- **Phones:** members' phone numbers and names are on officers' phones for offline work. That is covered by the
  required PIN and sign-out wipe. SQLCipher is a family item (roadmap 05 §8) that Akiba adopts when it lands.
- **Collection guardrails:** no messages to anyone other than the borrower and guarantors (SI 108 reg. 13). Recovery
  agents are out of v1.

## 19. Test strategy (money paths in detail in ADR 0009 §9)

| Kind | Where | Examples |
|---|---|---|
| Unit | `tests/Unit/Sacco/` | `Amount` (overflow, parse, allocate exactness), `Rate`, `Schedule` (Σ principal = loan; last instalment absorbs rounding; reducing interest ≤ flat), `WorkingDays` (holidays) |
| Property | `tests/Feature/Sacco/LedgerPropertyTest` | 500 random seeded postings → TB = 0, caches = recomputed, Σ sub-ledgers = controls, hash chain verifies |
| Idempotency | `PostingIdempotencyTest`, `SyncReplayTest`, `MomoSettleTwiceTest`, `PayoutRetryTest`, `AccrualCatchUpTest` | Same key twice → one entry; same key, different payload → `idempotency_conflict` |
| Concurrency (`pcntl_fork`, the `ConcurrentPledgePaymentsTest` template) | `ConcurrentWithdrawalsTest`, `ConcurrentPostingSameKeyTest`, `RepaymentVsAccrualTest` | Two withdrawals of 60 % of a balance → exactly one succeeds |
| Legal guards | `LoanProductRulesTest`, `CoolingOffTest`, `PenaltyCapTest`, `NplCapTest`, `GuarantorRulesTest` | Flat refused in office mode; penalty > ½ rate refused; Eid inside the 5 working days |
| Tenant | `TenantIsolationTest` additions, `MemberTokenIsolationTest` | |
| Plans | `PlanLineTest` | 03 §5.1 list |
| API docs | `OpenApiTest` | |
| Portal | five proofs per screen, browser + axe | |
| App | `flutter analyze`, `flutter test` (`test/sacco/`: offline receipt → outbox → push → replay; money formatting; member session isolation), the screenshot harness for the flavor | |
