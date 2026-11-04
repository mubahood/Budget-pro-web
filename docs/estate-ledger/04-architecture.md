# 04. Estate Ledger: architecture

Status: design, 2026-10-24, implementable as written. The money model is
[ADR 0010](../adr/0010-rentals-ledger.md); this document refers to it rather than repeating it. Decision ids `KR-nn`
are in [decisions.md](decisions.md).

Abbreviations: **K** = kernel `/Applications/MAMP/htdocs/budget-pro`, **P** = portal
`/Applications/MAMP/htdocs/budget-pro-new`, **A** = app `/Users/mac/Desktop/github/budget-pro-mobo`.

---

## 1. Where the code lives

| Layer | Estate Ledger code | Rule |
|---|---|---|
| K schema | `database/migrations/2026_10_24_7xxxxx_*.php` (the 7xxxxx block is Estate Ledger's) | Guarded with `Schema::hasTable`/`hasColumn`; a doc comment cites this file |
| K models | `app/Models/Rental*.php` (flat, prefixed, like `Sacco*`) | Every model adds `CompanyScope` in `booted()`. Journal, allocation, payment and charge models refuse `deleting`; journal and allocation rows also refuse `updating` |
| K services | `app/Services/Rentals/**` | The only place rules and money paths live. Screens and controllers never write rental tables directly |
| K value objects | `app/Support/Rentals/*.php` (`Minor`, `Period`, `Periods`) | Pure, immutable, no floats |
| K rules | `app/Support/Rules/Rental*Rules.php` | `WRITABLE` + `rules()`, shared by the API and the portal |
| K API | `app/Http/Controllers/Api/V1/Rentals/*Controller.php`, routes in `routes/api_rentals.php` | `ApiResponse` envelope; refusals are `BusinessRuleException` codes |
| K config | `config/rentals.php` | Defaults, plans, demo, kinds and lists |
| P staff screens | `app/Livewire/Shop/Rentals/**`, views `resources/views/livewire/shop/rentals/**`, CSS `resources/css/shop/rentals.css`, helpers `app/Shop/Rentals*.php`, PDF controllers `app/Http/Controllers/Shop/Rental*Controller.php` | `docs/shop-conventions.md`; writes through K services only |
| P people portal | `app/Livewire/RentalPortal/**`, layout `resources/views/layouts/rental-portal.blade.php`, routes `/my/*` | Tenants and landlords; never a staff login (§8) |
| P site | `config/apps.php` entry `estate-ledger`, `resources/content/sites/estate-ledger.php`, `resources/views/marketing/apps/estate-ledger.blade.php`, images `public/images/site/estate-ledger/` | Same as the other app sites; stands alone (KR-05) |
| A | `lib/rentals/{data,domain,services,screens,ui}`, flavor `estate-ledger` | Money is `int` minor units; screens never ask which flavor they are in |

## 2. Module plumbing (the places to touch)

There is no central module registry. Akiba (`sacco`) is the model: every file below already has a `sacco` line, and
Estate Ledger adds a `rentals` line beside it (`grep -n sacco <file>` finds the spot). The module is **exclusive** (KR-04).

| # | File | Change |
|---|---|---|
| 1 | K `config/onboarding.php` | `modules['rentals'] = 'Rentals and tenants'`; `module_paths['rentals'] = []`; `business_types['rentals'] = ['label' => 'Letting agency or landlord', 'modules' => ['rentals'], 'group' => 'Something else', 'icon' => 'fa-key', 'hint' => 'Properties, tenants, rent and landlords']` |
| 2 | K `EnsureApiModule::MODULES` | add `'rentals'` |
| 3 | K `CompanyRules` | `CORE_MODULES[] = 'rentals'`, `EXCLUSIVE_MODULES = ['sacco', 'rentals']`; `businessTypeFor()` returns `rentals`; the exclusive refusal names the right app |
| 4 | K `Company::modules()` and the exclusive-module guards | treat `rentals` like `sacco` |
| 5 | K `RegistrationService` | with `modules = ['rentals']`, no `finance`; trial plan `config('rentals.plans.trial')` |
| 6 | K `PlanLine` | `ESTATE_LEDGER = 'estate-ledger'`, `LINES[]`, `MODULE_LINES['rentals']`; `assertFor` words per line |
| 7 | K `Quotas` | key `units` (rental units not inactive) on the estate-ledger line; `Lifecycle::freePlan` per line; `Entitlements` defaults `rentals_portal`, `rentals_sms` false |
| 8 | K `database/seeders/EstateLedgerPlanSeeder.php` (+ `DatabaseSeeder`) | the plans of §15 (idempotent `updateOrCreate` by slug) |
| 9 | K `config/permissions.php` | the permissions and roles of §7, `module => 'rentals'`; `admin_roles` map them to `shop_viewer` |
| 10 | K `Team\Permissions`, `TeamService`, `TeamRules` | roles offered by module (already generic through `module`); the viewer of a rentals account gets `rentals_view` + `rentals_reports` |
| 11 | K `ApiPermissionMap::RULES` | one line per endpoint group of §9 |
| 12 | K `ShopMenu` | a `rentals` section (§10.1) |
| 13 | K `SupportContact::APPS` | `'estate-ledger'` |
| 14 | K `TenantModels` / `SchemaIntegrity` | every `Rental*` model scoped; status CHECKs (app-enforced on MySQL 5.7); FKs to `companies` |
| 15 | K `OtpService` | identifier scope `rentals:` for portal codes (as `sacco:`) |
| 16 | K `PublicDemo` guards | call `RentalsDemo::guard()` / `guardAccount()` as they call `SaccoDemo` |
| 17 | K `Console/Kernel.php` | §13 schedule |
| 18 | K `ConfigDoctor`, `HealthService` | `rentals:check` failures on System health |
| 19 | K `FileController::PURPOSES` | `rental_photo`, `rental_document` |
| 20 | K `routes/api.php` | `require __DIR__.'/api_rentals.php';` |
| 21 | P `config/apps.php` | app `estate-ledger` (§12) and `core_modules['rentals']` (`'exclusive' => true`, `'app' => 'estate-ledger'`, `'icon' => 'key'`) |
| 22 | P `EnsureModule`, `Navigation`, `PageStyles`, `ShopContext` | `module:rentals` works; the menu from `ShopMenu`; `rentals.css` on `rentals.*` routes |
| 23 | P `Livewire/Shop/Dashboard` | a rentals-only account's dashboard redirects to `rentals.overview` (as sacco → `sacco.overview`) |
| 24 | P `Onboarding/Wizard`, `Settings/Business`, `Team/Index`, `RegisteredShopController` | exclusive module handling, as for sacco |
| 25 | P `AppSite`, `AppSiteController`, `MarketingController`, `DemoController`, `layouts/marketing`, `marketing/*` | the Estate Ledger site and its own demo (§12, §14) |
| 26 | P `bootstrap/app.php` | middleware alias `rental.portal` (§8) |
| 27 | A `AppFlavor` | `estate-ledger` (§11.1); `exclusiveModules = ['sacco', 'rentals']`; `budgetPro.hiddenModules` gains `rentals` |
| 28 | A `ModuleRegistry` / `module_home.dart` | `rentals` → `RentalsHome()` |

Tests that already walk these (route walk, tenant isolation, module gating, `SiteTest`, `flavor_test.dart`) must stay
green and gain the new entries.

---

## 3. Data model

### 3.0 Conventions

- `id BIGINT UNSIGNED`; `company_id BIGINT UNSIGNED NOT NULL` with an index that starts every composite index.
- Money: `*_minor BIGINT` (signed where it can go negative: journal effects, allocations; else unsigned in meaning,
  enforced by the services). Rates `*_bp INT UNSIGNED`. One currency per account (`rental_settings.currency`, locked
  after the first payment).
- Business dates are `DATE` in the account's local day (`LocalDate::today($companyId)`); instants are `TIMESTAMP` UTC.
- Phone writes carry `uuid CHAR(36)`, `UNIQUE(company_id, uuid)`; `device_id VARCHAR(64)` nullable.
- `created_by_id` references `admin_users.id` (nullable when the system or a portal person did it; the actor is then in
  `rental_activities`).
- Personal identifiers (`id_number`) are stored with `DataCrypt` (encrypted at rest) and never logged or exported
  except on the tenant's own record.

### 3.1 Tables

**`rental_settings`** — one row per account; also the account's money lock (ADR 0010 §6).

| Column | Type | Meaning |
|---|---|---|
| company_id | unique | |
| mode | varchar(10) `agency`\|`owner` | KR-06 |
| currency | char(3) | from the company at setup |
| bill_days_before | tinyint, 5 | bills are raised this many days before a period starts |
| grace_days | tinyint, 5 | a charge is *overdue* after due date + grace |
| anchor | varchar(8) `start`\|`first` | default for new leases (KR-11) |
| prorate_first | tinyint(1), 1 | with anchor `first`, bill the part month |
| commission_type | varchar(8) `percent`\|`flat`\|`none` | default for new properties |
| commission_bp | int, 1000 | 10 % |
| commission_minor | bigint, 0 | flat per rent charge |
| commission_on_items | tinyint(1), 0 | default for new fee items |
| deposit_months | tinyint, 1 | move-in default deposit = rent × this |
| deposit_held_by | varchar(10) `agency`\|`landlord` | default for new properties |
| late_fee_type | varchar(8) `none`\|`flat`\|`percent` | default `none` (KR-14) |
| late_fee_minor / late_fee_bp | bigint / int | |
| late_fee_to | varchar(10) `agency`\|`landlord` | who keeps a late fee |
| payment_instructions | text | shown to tenants: MoMo number, bank account |
| receipt_footer | varchar(255) | |
| messages | json | template overrides (§16) |
| seq_receipt, seq_lease, seq_payout, seq_expense, seq_ticket, seq_tenant | int unsigned | numbering, bumped under the lock |
| setup_at | timestamp | the first-run wizard was finished |

**`rental_landlords`**: company_id, `code` (LL-001), `name`, `phone`, `phone_e164`, `email`, `address`,
`id_number` (encrypted), `payout_method` (`cash`\|`bank`\|`mobile_money`), `payout_details` (text: bank, account, MoMo
number), `commission_type`/`commission_bp`/`commission_minor` (nullable overrides), `is_self` (owner mode's "You",
KR-06), `notes`, `status` (`active`\|`inactive`), `created_by_id`, timestamps. Index (company_id, status).

**`rental_properties`**: company_id, landlord_id, `code`, `name`, `kind` (`residential`\|`commercial`\|`mixed`\|`land`),
`address`, `area`, `district`, `lat`/`lng` (decimal(10,7) nullable), `photo_uuid` (files), `commission_type`/`_bp`/`_minor`
(nullable: falls back to the landlord's, then settings), `deposit_held_by` (nullable), `water_rate_minor`,
`power_rate_minor` (per unit, for metered bills), `notes`, `status` (`active`\|`archived`), timestamps.

**`rental_property_staff`**: company_id, property_id, user_id, timestamps; unique (property_id, user_id). A caretaker
sees only these properties (§7.2).

**`rental_units`**: company_id, property_id, `code` (unique per property: "A1", "Shop 3"), `kind` (`single_room`,
`double_room`, `self_contained`, `studio`, `apartment`, `house`, `shop`, `office`, `stall`, `warehouse`, `other`),
`floor` (varchar 20), `block` (varchar 20), `bedrooms`, `bathrooms` (tinyint nullable), `size_sqm` (int nullable),
`rent_minor` (asking rent a month), `deposit_minor` (nullable: settings × rent), `cycle_months` (default 1), `status`
(`available`\|`reserved`\|`maintenance`\|`inactive`; *occupied* is derived from a live lease, never stored),
`water_meter`, `power_meter` (meter numbers), `notes`, `photo_uuid`, timestamps.

**`rental_tenants`**: company_id, `code` (T-0001), `kind` (`person`\|`company`), `name`, `phone`, `phone_e164`,
`alt_phone`, `email`, `id_type` (`national_id`\|`passport`\|`driving_permit`\|`company_reg`\|`other`), `id_number`
(encrypted), `occupation`, `employer`, `next_of_kin_name`, `next_of_kin_phone`, `photo_uuid`, `notes`, `status`
(`active`\|`former`), `created_by_id`, timestamps. Index (company_id, phone_e164).

**`rental_leases`**: company_id, `number` (L-0001), unit_id, property_id, landlord_id (copied at move-in), tenant_id,
`start_date`, `end_date` (nullable: a fixed term's last day; null = month to month), `cycle_months` (1, 3, 6 or 12),
`anchor` (`start`\|`first`), `rent_minor` (current monthly rent; history in `rental_rent_changes`),
`deposit_minor`, `deposit_held_by`, `commission_type`/`_bp`/`_minor` (resolved at move-in, editable; applies to charges
raised after the change), `late_fee` (`default`\|`off`), `billed_until` (DATE: the last day covered by a raised rent
charge), `status` (`active`\|`notice`\|`ended`\|`cancelled`), `notice_on`, `move_out_on` (planned), `ended_on`,
`end_reason`, `notes`, `created_by_id`, `ended_by_id`, timestamps. Index (company_id, status), (company_id, unit_id,
status). A unit has at most one `active`/`notice` lease (checked under the lock).

**`rental_lease_items`** (fees beside rent): company_id, lease_id, `name` (Garbage, Security, Water (flat), Service
charge…), `amount_minor` (a month), `goes_to` (`landlord`\|`agency`), `commissionable` (bool), `starts_on`, `ends_on`
(nullable), `active`, timestamps.

**`rental_rent_changes`**: company_id, lease_id, `rent_minor`, `effective_from` (DATE, a period start), `reason`,
`created_by_id`, timestamps. A period is billed at the rent effective on its start.

**`rental_charges`**: company_id, lease_id, tenant_id, unit_id, property_id, landlord_id, `kind` (`rent`, `item`,
`utility`, `deposit`, `late_fee`, `one_off`, `opening`, `refund`), `item_id` (lease item), `meter_reading_id`,
`parent_id` (a late fee's rent charge), `description`, `period_start`, `period_end` (nullable), `due_date`,
`amount_minor` (> 0), `paid_minor` (cache = Σ allocations), `goes_to` (`landlord`\|`agency`\|`deposit`\|`tenant`:
a `refund` charge, never billed),
`commission_type`/`_bp`/`_minor` (snapshot for commissionable charges; `none` otherwise), `status`
(`open`\|`paid`\|`void`), `voided_at`, `voided_by_id`, `void_reason`, `source` (`auto`\|`manual`\|`import`\|`move_out`),
`dedupe_key` (varchar 80, nullable; unique per company: `rent:{lease}:{period_start}`, `item:{item}:{period_start}`,
`late:{charge}`, `deposit:{lease}`, `meter:{reading}`), `created_by_id`, timestamps. Indexes (company_id, lease_id,
status, due_date), (company_id, landlord_id, due_date), (company_id, status, due_date).

**`rental_payments`**: company_id, `uuid`, `number` (R-000001, per account), lease_id, tenant_id, unit_id,
property_id, landlord_id, `amount_minor` (> 0), `method` (`cash`\|`bank`\|`mobile_money`\|`cheque`\|`deposit`\|`waiver`\|`opening`),
`reference` (MoMo or bank ref), `paid_on` (DATE), `received_by_id`, `channel` (`web`\|`app`\|`portal`\|`import`\|`system`),
`notes`, `unallocated_minor` (cache = the tenant's credit from this payment), `reversed_at`, `reversed_by_id`,
`reverse_reason`, `device_id`, timestamps. `deposit`, `waiver` and `opening` (credit brought in by an import) payments
move no cash (§5.4, §5.10).

`receipt_snapshot` (json, written once when the payment is posted): the receipt as issued, read back on every reprint
(names, figures, words); only a reversal adds to it.

**`rental_allocations`**: company_id, payment_id, charge_id, `source` (`direct`\|`credit`), `amount_minor` (signed:
a reversal is negative), `commission_minor`, `landlord_minor`, `agency_minor`, `deposit_minor` (the split; Σ split =
amount except for waivers, whose split is all zero), `reverses_id`, `applied_on` (DATE), `created_at`. Immutable.

**`rental_entries`** (the journal, ADR 0010): company_id, `group_id` (char 36: rows posted together), `type`
(§4), `occurred_on`, `method` (for cash rows), `cash_minor`, `landlord_minor`, `deposit_minor`, `credit_minor`,
`income_minor`, `expense_minor`, `equity_minor` (signed, default 0), landlord_id, property_id, unit_id, lease_id,
tenant_id, payment_id, allocation_id, charge_id, payout_id, expense_id, `description`, `reverses_id`, `reversed_at`,
`created_by_id`, `created_at`. Indexes (company_id, occurred_on), (company_id, landlord_id, occurred_on),
(company_id, lease_id), (company_id, payment_id), (company_id, type). Immutable except `reversed_at`.

**`rental_payouts`**: company_id, `number` (PO-0001), landlord_id, `amount_minor`, `method`, `reference`, `paid_on`,
`period_from`/`period_to` (nullable: the statement it settles), `notes`, `created_by_id`, `reversed_at`,
`reversed_by_id`, `reverse_reason`, timestamps.

**`rental_expenses`**: company_id, `number` (EX-0001), property_id (nullable), unit_id (nullable), landlord_id
(nullable: set when charged to a landlord), `category` (config list, free text allowed), `description`, `vendor`,
`amount_minor`, `method`, `paid_on`, `charged_to` (`landlord`\|`agency`), `receipt_uuid` (files), maintenance_id,
`created_by_id`, `reversed_at`, `reversed_by_id`, `reverse_reason`, timestamps.

**`rental_maintenance`**: company_id, `uuid`, `number` (MR-0001), property_id, unit_id (nullable), lease_id,
tenant_id (nullable), `title`, `details`, `priority` (`low`\|`normal`\|`high`\|`urgent`), `status`
(`open`\|`in_progress`\|`done`\|`cancelled`), `reported_by` (`staff`\|`tenant`\|`landlord`), `reported_by_id`,
`assigned_to_id` (user, nullable), `assignee_name` (a fundi or company), `photos` (json list of file uuids),
`cost_minor`, `expense_id`, `resolved_on`, `resolution`, `created_by_id`, timestamps.

**`rental_meter_readings`**: company_id, `uuid`, unit_id, lease_id, `utility` (`water`\|`power`), `reading`
(decimal(14,3): a meter value, not money), `previous` (decimal(14,3)), `used` (decimal(14,3)), `rate_minor`,
`amount_minor`, `read_on`, `reset` (meter replaced: `previous` = 0), `charge_id`, `photo_uuid`, `recorded_by_id`,
`notes`, timestamps.

**`rental_follow_ups`** (the collections log): company_id, lease_id, tenant_id, `channel`
(`whatsapp`\|`sms`\|`call`\|`visit`\|`note`), `note`, `owed_minor` (at the time), `promise_on` (DATE, nullable),
`created_by_id`, `created_at`.

**`rental_logins`** (tenants' and landlords' own sign-in, §8): company_id, `subject_type` (`tenant`\|`landlord`),
`subject_id`, `email`, `phone_e164`, `password` (nullable hash), `failures`, `locked_until`, `disabled_at`,
`last_login_at`, `created_by_id`, timestamps; unique (company_id, subject_type, subject_id). The model uses Sanctum's
`HasApiTokens` (ability `rentals-portal`).

**`rental_documents`**: company_id, `owner_type` (`tenant`\|`lease`\|`property`\|`landlord`), `owner_id`, `kind`
(`agreement`\|`id`\|`photo`\|`other`), `name`, `file_uuid`, `uploaded_by_id`, timestamps.

**`rental_activities`** (audit): company_id, `actor_type` (`staff`\|`tenant`\|`landlord`\|`system`), `actor_id`,
`action` (e.g. `payment.reversed`), `subject_type`, `subject_id`, `summary`, `meta` (json, no personal identifiers),
`created_at`.

---

## 4. Journal posting rules

Every posting is `RentalLedger::post(array $rows)` inside `RentalLedger::locked()`. Each posting must balance
(**cash = landlord + deposit + credit + income − expense + equity**), and so must each row except a transfer's two
legs. L = landlord part, C = commission, G = agency
part (fees the agency keeps), D = deposit part (ADR 0010 table).

| `type` | When | cash | landlord | deposit | credit | income | expense | equity |
|---|---|---|---|---|---|---|---|---|
| `collected` | an allocation of a cash-type payment | +a | +L | +D | | +C+G | | |
| `prepaid` | the unallocated part of a cash-type payment | +u | | | +u | | | |
| `credit_applied` | credit later allocated to a charge | | +L | +D | −a | +C+G | | |
| `deposit_applied` | a `deposit` payment's allocation, deposit held by the agency | | +L | −a | | +C+G | | |
| `deposit_applied` | the same, deposit held by the landlord (they already have the money) | | +L−a | | | +C+G | | |
| `deposit_refund` | move-out refund of a deposit held by the agency | −r | | −r | | | | |
| `deposit_refund_landlord` | refund of a deposit the landlord held, paid by the agency for them | −r | −r | | | | | |
| `credit_refund` | refund of money paid ahead (credit allocated to a `refund` charge, §5.7) | −r | | | −r | | | |
| `opening_credit` | money a tenant had paid ahead before the import | | | | +o | | | −o |
| `payout` | money paid to a landlord | −p | −p | | | | | |
| `expense_landlord` | an expense charged to a landlord | −e | −e | | | | | |
| `expense_agency` | the agency's own expense | −e | | | | | +e | |
| `transfer` | cash between methods (two rows, one group) | −t / +t | | | | | | |
| `opening_cash` | opening cash or bank | +o | | | | | | +o |
| `opening_landlord` | an opening balance owed to a landlord | | +o | | | | | −o |
| `opening_deposit` | a deposit already held at migration | | | +o | | | | −o |
| `reversal` | negates every effect of the row it `reverses_id` | − | − | − | − | − | − | − |

A deposit collected while the landlord holds deposits is an ordinary `collected` row with the deposit part in
`landlord_minor` (§5.3). Charges, waivers and voids post no journal row (a waiver's allocation has a zero split).
The cash book is the rows with `cash_minor ≠ 0`.

**Deposit held for a lease** (either holder) = deposits collected (allocations to its `deposit` charges, net of
reversals) − `deposit` payments − deposit refunds. For an agency-held deposit it also equals Σ `deposit_minor` of the
lease (checked by §5.12).

---

## 5. Algorithms (the money paths)

### 5.1 Periods (`App\Support\Rentals\Periods`, pure)

- Anchor `start` (default): period k starts at `start_date + k × cycle` months, always computed from `start_date` with
  `addMonthsNoOverflow` (31 Jan → 28 Feb → 31 Mar); it ends the day before the next start.
- Anchor `first`: if `start_date` is not the 1st, a first part period runs to the end of that month, billed at
  `rent × days_left / days_in_month` (rounded half up to the minor unit) when `prorate_first`; then periods start on
  the 1st, every `cycle` months.
- A period's rent = `rent_minor × cycle_months` at the rent effective on its start.
- Due date = the period's start (rent is paid in advance). Overdue = `due_date + grace_days < today`.
- The last period is cut at `end_date` or `ended_on` only by the move-out choice (§5.7); by default a started period is
  billed in full.

### 5.2 Billing (`Billing::raise(lease, upTo)`)

1. Run for a lease when it starts, when a rent change or item is saved, from the daily job, and on "Raise bills now".
2. `horizon = today + bill_days_before`. For each period starting ≤ horizon, on or after `start_date`, before
   `end_date`/`ended_on` when set: create the rent charge (`dedupe_key rent:{lease}:{start}`) and one charge per active
   item for that period (`item:{item}:{start}`, `amount × cycle`). `billed_until` moves to the period's end.
3. Commission snapshot on the rent charge (and commissionable items): lease → property → landlord → settings.
4. After raising, `Payments::applyCredit(lease)` (§5.5).
5. Idempotent: a unique `dedupe_key` makes a second run a no-op.

### 5.3 Receiving a payment and splitting it (`Payments::receive`)

Input: lease, amount, method, paid_on, reference, notes, uuid. Refused: amount ≤ 0, an ended or cancelled lease
(unless it still owes or has a deposit to settle), a method not in the list, a date after today, a duplicate uuid with
a different payload (`idempotency_conflict`; the same payload returns the first payment).

1. Under the lock: number = next receipt number.
2. Open charges of the lease (status `open`), ordered by `due_date`, then kind priority
   (`deposit`, `opening`, `rent`, `item`, `utility`, `one_off`, `late_fee`), then id.
3. For each, `a = min(left, remaining)` and the split:
   - commissionable (`commission_type ≠ none`): `C = target(paid + a) − target(paid)`, where
     `target(x) = round_half_up(total × x / amount)` and `total = round_half_up(amount × bp / 10000)` for `percent` or
     `min(commission_minor, amount)` for `flat`. So part payments carry a proportional share and the commissions of a
     fully paid charge sum to exactly `total`. `L = a − C`.
   - `goes_to = landlord`, not commissionable: `L = a`. `goes_to = agency`: `G = a`.
   - `goes_to = deposit`: `D = a` when the lease's deposit is held by the agency; when the landlord holds it, `L = a`
     (the landlord receives the deposit) and the lease remembers it for the refund (§5.7).
4. One allocation row and one `collected` journal row per charge touched; the rest becomes credit (`prepaid` row,
   `unallocated_minor`).
   The order above is the default. A cashier may send `split` (EL-05), lines of `charge_id` and `amount_minor`: each an
   open bill of this lease, at most what is left on it, adding up to the amount. Money stays as credit only once every
   open bill is covered. `Payments::suggestSplit` shows the split on screen and flows the rest around typed lines.
5. Charges' `paid_minor` and `status` update; the receipt is ready (§10.4).

### 5.4 Deposit and waiver payments

- `deposit` (move-out): `amount ≤` the deposit held for the lease; allocations as in §5.3 but the journal row is
  `deposit_applied` (deposit −a). Never leaves credit.
- `waiver` (rentals_bill, reason required): settles part or all of one named charge with no money: an allocation with
  a zero split, no journal row. Used for rent forgiven by the landlord, discounts, and unused days at move-out.

### 5.5 Credit (`Payments::applyCredit(lease)`)

While the lease has a non-reversed payment with `unallocated_minor > 0` (oldest first) and an open charge: allocate
from that payment (`source = credit`), journal row `credit_applied`. Runs after every charge is raised and after every
reversal. "Paid up to" on screens projects the remaining credit over the coming periods' rent.

### 5.6 Reversal (`Payments::reverse(payment, reason)`, rentals_reverse)

For every allocation made from the payment (direct and credit, not yet reversed): a negative allocation
(`reverses_id`) and the negating journal row of the row it produced; the original rows get `reversed_at`. The
payment's `prepaid` row is negated too. Charges reopen; `unallocated_minor` = 0; `reversed_at`/`reverse_reason`
set; then `applyCredit` re-runs (other credit may cover the reopened charges). A landlord balance may go below zero
(the landlord owes the agency); screens say so. Payouts and expenses reverse the same way (one negating row).

### 5.7 Move-in and move-out (`Leases`)

- **Move in** (`Leases::moveIn`): unit available (no live lease, status not `inactive`/`maintenance`), tenant (new or
  existing), start date, rent, cycle, anchor, deposit, items, commission override, end date. Creates the lease, the
  deposit charge (`deposit:{lease}`), bills (§5.2), sets the tenant `active`, the unit's status back to `available`
  if it was `reserved`; optionally records the first payment in the same lock.
- **Notice** (`Leases::notice`): `status = notice`, `notice_on`, `move_out_on`.
- **Move out** (`Leases::moveOut`): on date d with optional final meter readings (§5.9), damages (one-off charges
  to the landlord or agency), and the unused-days choice (waive the unused part of the current period, pro rata by
  days). Then: (1) apply the deposit held to what is still owed (a `deposit` payment; it never pays a deposit
  charge); (2) refund the rest of the deposit by a method, or keep it on the lease to refund later; (3) refund the
  tenant's credit (a `refund` charge settled from credit at once, journal `credit_refund`), or keep it; (4) the
  lease becomes `ended`, `ended_on = d`; billing stops; the tenant becomes `former` when they hold no other live lease.
  What is still owed after the deposit stays on the ended lease ("former tenants who owe").
  `Leases::moveOutPreview` says what these choices will do, with no writes.
- **Part-month amounts** (EL-04): the calculated amount is a suggestion. `part_rent_minor` at move-in (0 charges none)
  and `unused_minor` at move-out (spread over the period's bills, rent first) take what staff typed instead.
- **Cancel** (`Leases::cancel`): only for a lease with no payments; voids its charges.

### 5.8 Rent change, items, one-off charges, late fees

- **Rent change** (`Leases::changeRent(lease, rent, from)`): `from` is a period start not yet billed (or the next
  one); a `rental_rent_changes` row; `rent_minor` updates when `from ≤ today`, else on that day's billing.
- **Items**: add, end (`ends_on`), never edit an amount already billed; a new amount is a new item from a date.
- **One-off charge** (`Billing::charge`, rentals_bill): any amount, description, due date, `goes_to`, commissionable.
- **Void** (`Billing::void`, rentals_bill): only with `paid_minor = 0`; else reverse the payments or waive.
- **Late fees** (daily, KR-14): when the type is not `none` and the lease's `late_fee ≠ off`, each rent charge still
  open after `due_date + grace_days` gets one late fee (`late:{charge}`): flat, or `bp` of what is left on it; due
  today; to the agency or the landlord per the setting. No compounding.

### 5.9 Meter readings (`Meters::record`)

`reading ≥ previous` unless `reset`; `used = reading − previous`; `amount = round_half_up(used × rate)`; a `utility`
charge to the lease living in the unit on `read_on` (`meter:{reading}`), `goes_to = landlord` by default. A reading
for a vacant unit stores the value (the next tenant's start) and charges nothing.

### 5.10 Payouts, expenses, transfers, opening balances

- **Payout** (`Payouts::record`, rentals_landlords): amount ≤ the landlord's balance unless "pay ahead" is ticked
  (an advance to the landlord); cash must cover it per method (refused `insufficient_cash`).
- **Expense** (`Expenses::record`, rentals_expenses): `charged_to = landlord` needs a property (its landlord is
  charged); else the agency's own expense. Paid by a method (cash must cover it). From a maintenance request when it
  is done with a cost.
- **Transfer** (`CashBook::transfer`): between methods (banking cash, withdrawing from mobile money).
- **Opening balances** (`Import::opening`): cash per method, what is owed to each landlord, deposits held per lease,
  tenants' arrears (as `opening` charges) and credit (as an `import` payment) — for an account moving from another
  system (KR-22).

### 5.11 Owner mode (KR-06)

One landlord with `is_self = 1` ("You"), created at setup and hidden from menus. Commission `none`. The landlord
balance is "money in hand from the property"; a payout to self is labelled "Owner drawing". Agency-only screens
(Landlords, Payouts, Commission report) are hidden; the statement is the "Property income statement".

### 5.12 `rentals:check` (`Jobs\RentalCheck`, nightly and on demand)

Per account, it fails (and lists the ids) when:

1. a posting does not balance, or a row other than a transfer leg does not;
2. `paid_minor` ≠ Σ allocations for a charge, a charge is overpaid, or its status disagrees;
3. `unallocated_minor` ≠ amount − Σ net allocations for a payment (0 when reversed);
4. Σ `credit_minor` for a lease ≠ Σ unallocated of its payments;
5. Σ split of an allocation ≠ its amount (except waivers and `refund` charges, whose split is zero);
6. a unit has two live leases;
7. a reversed row's reversal is missing, or a reversal reverses a reversal.

---

## 6. Services (K, `App\Services\Rentals\…`)

| Class | Main methods |
|---|---|
| `RentalsModule` | `setup(Company, User, array)`, `settings(int $cid)`, `saveSettings(...)`, `isOwnerMode(int)` |
| `RentalAccess` | `authorize(User, string $perm)`, `can(User, $perm)`, `propertyIds(User): ?array` (null = all), `scope(Builder, User)` |
| `RentalAudit` | `record(action, subject, cid, meta, actor)` |
| `Ledger\RentalLedger` | `locked(int $cid, Closure)`, `post(array $rows)`, `reverseRow(RentalEntry, ...)`, `balances(int $cid)` |
| `Directory\Landlords` / `Properties` / `Units` / `Tenants` | `create`, `update`, `find`, `archive`, staff assignment (`Properties::assignStaff`) |
| `Leases\Leases` | `moveIn`, `notice`, `withdrawNotice`, `moveOut`, `cancel`, `changeRent`, `addItem`, `endItem`, `position(lease)` (owed, overdue, credit, deposit held, paid up to, next due) |
| `Billing\Billing` | `raise(lease, ?date)`, `raiseAll(cid, ?date)`, `charge(...)`, `void(...)`, `lateFees(cid, date)` |
| `Payments\Payments` | `receive(...)`, `waive(...)`, `applyCredit(lease)`, `reverse(...)`, `receipt(payment)` |
| `Payments\Splits` | pure: `split(charge, amount, paidBefore, depositHeldBy)` → `[C, L, G, D]` |
| `Landlords\LandlordAccounts` | `balance(landlord, ?asOf)`, `statement(landlord, from, to)`, `summary(cid)` |
| `Landlords\Payouts` | `record`, `reverse` |
| `Expenses` | `record`, `reverse`, `categories()` |
| `Maintenance` | `open`, `update`, `assign`, `complete(cost → expense)`, `cancel` |
| `Meters` | `record`, `last(unit, utility)` |
| `Collections\Arrears` | `aging(cid, ?propertyIds, date)`, `list(...)`, `formerOwing(...)` |
| `Collections\FollowUps` | `log`, `forLease` |
| `Messages\RentalMessages` | `text(kind, lease|payment|landlord, ...)`, `whatsappLink`, `smsLink` (§16) |
| `CashBook` | `balances(cid)`, `statement(cid, from, to, ?method)`, `transfer(...)` |
| `Reports\RentalReports` | the report registry (§17), `run(key, params)` → rows + totals; `Reports\RentalExport` CSV/XLSX/PDF |
| `Logins\RentalLogins` | `enable`, `disable`, `attempt`, `sendCode`, `verifyCode`, `resolve`, `issueToken` (§8) |
| `Import\RentalImport` | CSV templates and dry-run import of landlords, properties, units, tenants, leases and opening balances |
| `Jobs\RentalDaily` | `run(cid, date)`: raise bills, late fees, expire `reserved`, notice reminders; idempotent |
| `Jobs\RentalCheck` | §5.12 |
| `Demo\RentalsDemo`, `Demo\RentalsDemoBuilder` | §14 |

Every write method takes the acting `User` (or a portal person), checks the permission and the property scope, runs
under `RentalLedger::locked()` when it touches money, records `rental_activities`, and throws
`BusinessRuleException` with a plain message.

---

## 7. Roles and permissions

### 7.1 Permissions (`config/permissions.php`)

| Permission | Meaning |
|---|---|
| `rentals_view` | See properties, units, tenants, leases and balances |
| `rentals_manage` | Add and edit landlords, properties, units and tenants; move tenants in and out |
| `rentals_collect` | Receive payments and print receipts |
| `rentals_bill` | Add charges, change rent, waive and void charges |
| `rentals_reverse` | Reverse payments, payouts and expenses |
| `rentals_landlords` | Landlord statements and payouts |
| `rentals_expenses` | Record expenses and move cash between accounts |
| `rentals_maintenance` | Maintenance requests and meter readings |
| `rentals_reports` | Reports and exports |
| `rentals_settings` | Settings, staff property assignments, tenant and landlord sign-ins |

| Role (`module => rentals`) | Permissions |
|---|---|
| `rentals_manager` (Property manager) | all ten |
| `rentals_accountant` (Accountant) | view, collect, bill, reverse, landlords, expenses, reports |
| `rentals_caretaker` (Caretaker) | view, collect, maintenance — on assigned properties only |

`owner` (`*`) and `viewer` (`rentals_view` + `rentals_reports` on a rentals account) stay as they are.

A caretaker has no reports and never sees how a landlord is paid: on every landlord payload they can reach,
`payout_method`, `payout_details` and `notes` are null, and the landlord screens are closed to them, whatever their role
was given (EL-20).

### 7.2 Property scope

`RentalAccess::propertyIds($user)`: null for owner, manager, accountant and viewer; for a caretaker the ids in
`rental_property_staff` (an empty list sees nothing). Every list, search, report and write filters by it; a record
outside the scope is 404 (never 403, so ids cannot be probed).

## 8. Tenants' and landlords' own sign-in

They are never staff users (one business per staff login, and tenants may rent from several agencies).

- Staff with `rentals_settings` open a sign-in for a tenant or landlord (`RentalLogins::enable`: email and/or phone,
  optional first password, else the person sets one with an emailed code).
- Web: `/my/login` (login + password, or "email me a code"), `/my/choose` when one login opens several accounts or
  both a tenant and a landlord record. The session holds `(company_id, subject_type, subject_id)`; middleware
  `rental.portal` resolves it on every request and wraps it in `CompanyScope::forRequest`.
- API: `POST rentals/portal-auth/login {login, password}` → `{token, person}` or `{choices[], ticket}`;
  `POST rentals/portal-auth/token {ticket, company_id, subject_type, subject_id, device_name}`;
  `POST rentals/portal-auth/code` and `…/code/verify`; `POST rentals/me/logout`. Tokens carry `rentals-portal`.
- Five wrong passwords lock the login for 15 minutes; refusals never say whether the login exists.
- The own sign-in comes with a paid plan (`rentals_portal`, EL-12, EL-19). When an account lapses to its free plan, has no
  plan with it, or its plan ran out past the grace days, `RentalLogins::resolve` refuses on the next request, so the API
  (`RentalPortalAuth`, 401 `portal_not_on_plan`) and `/my` both stop. Only someone who proves who they are (the right
  password or code, 403 `portal_not_on_plan`) hears why. Staff screens say so, and opening a sign-in is refused.
- Every `/me` query is keyed by the session's subject, never by a request parameter.

---

## 9. API v1 (`routes/api_rentals.php`)

Staff: `Route::prefix('rentals')->middleware(['auth:sanctum','api.tenant','api.module:rentals','api.subscription','api.perm','api.idempotent'])`.
People: `Route::prefix('rentals/me')->middleware([RentalPortalAuth::class, 'throttle:60,1'])`.
Sign-in: `Route::prefix('rentals/portal-auth')->middleware(['edition:saas','throttle:10,1'])`.

Money is `*_minor` integers plus `currency`; the API refuses decimals for money. Dates are `Y-m-d`. Lists take
`?q`, `?page`, `?per_page` (≤ 100) and return `meta.pagination`. Every write takes `Idempotency-Key`; payments,
meter readings and maintenance requests also take a body `uuid`.

| Method and path | Permission | Notes |
|---|---|---|
| `GET rentals/overview` | view | KPIs: units, occupied, vacant, occupancy %, billed and collected this month, collection rate, arrears (total, leases, aging), credit held, deposits held, owed to landlords, open maintenance, leases ending in 30 days; `attention[]` (overdue leases, vacant units, expiring leases, urgent maintenance) |
| `POST rentals/setup`, `GET/PUT rentals/settings` | owner / view, settings | |
| `GET/POST rentals/landlords`, `GET/PUT rentals/landlords/{id}` | view / manage | balance on each |
| `GET rentals/landlords/{id}/statement?from&to` | landlords | json; `&format=pdf` → signed link |
| `GET/POST rentals/payouts`, `POST rentals/payouts/{id}/reverse` | landlords / reverse | |
| `GET/POST rentals/properties`, `GET/PUT rentals/properties/{id}`, `PUT rentals/properties/{id}/staff` | view / manage / settings | |
| `GET/POST rentals/units`, `GET/PUT rentals/units/{id}` | view / manage | filters `property_id`, `state=vacant\|occupied\|overdue\|notice` |
| `GET/POST rentals/tenants`, `GET/PUT rentals/tenants/{id}`, `POST rentals/tenants/{id}/login` | view / manage / settings | |
| `GET rentals/leases`, `POST rentals/leases` (move in), `GET rentals/leases/{id}` | view / manage | the show returns `position`, charges, payments, items, deposit, follow-ups |
| `POST rentals/leases/{id}/{notice,withdraw-notice,move-out,cancel,rent-change}`, `POST rentals/leases/{id}/items`, `POST rentals/leases/{id}/items/{item}/end` | manage / bill | |
| `GET rentals/charges`, `POST rentals/charges`, `POST rentals/charges/{id}/{void,waive}`, `POST rentals/billing/run` | view / bill | |
| `GET rentals/payments`, `POST rentals/payments`, `GET rentals/payments/{id}`, `POST rentals/payments/{id}/reverse`, `GET rentals/payments/{id}/receipt` | view / collect / reverse | receipt: `text` (for WhatsApp and Bluetooth printers) and a signed PDF link. The POST takes an optional `split`, a list of `{charge_id, amount_minor}` (whole numbers; §5.3, EL-05): refused `split_invalid`, `split_wrong_bill`, `split_closed`, `split_too_much`, `split_total`; the same `uuid` with another split is 409 `idempotency_conflict` |
| `GET rentals/arrears`, `GET/POST rentals/follow-ups`, `GET rentals/leases/{id}/reminder` | view / collect | reminder returns the text and the WhatsApp and SMS links |
| `GET/POST rentals/expenses`, `POST rentals/expenses/{id}/reverse`, `GET rentals/cash`, `POST rentals/cash/transfers` | expenses / reverse | |
| `POST rentals/cash/opening` | settings | what an account held when the books began (cash +, equity +); once per account, `replace` corrects it |
| `POST rentals/leases/{id}/refund-deposit`, `POST rentals/leases/{id}/refund-credit` | manage | money kept at move-out goes back later; a kept deposit settles what is still owed first; a live lease's deposit stays held (`lease_live`) |
| `GET/POST rentals/documents`, `DELETE rentals/documents/{id}` | view / manage | papers on a tenant, lease, property or landlord; the file through `POST files`, purpose `rental_document`; an ID copy only for rentals_manage |
| `GET/POST rentals/maintenance`, `GET/PUT rentals/maintenance/{id}` | maintenance | photos are `files` uuids (`POST files`, purpose `rental_photo`) |
| `GET/POST rentals/meter-readings` | maintenance | |
| `GET rentals/reports`, `GET rentals/reports/{key}?…&format=json\|csv\|xlsx\|pdf` | reports | downloads → signed link; list filters in §17 |
| `GET rentals/activity` | settings | |
| `GET rentals/me` | person | tenant: profile, leases with `position`; landlord: profile, totals |
| tenant: `GET rentals/me/charges`, `GET rentals/me/payments`, `GET rentals/me/payments/{id}/receipt`, `GET rentals/me/lease/{id}`, `GET/POST rentals/me/maintenance`, `POST rentals/me/notice` | person | own leases only |
| landlord: `GET rentals/me/properties`, `GET rentals/me/statement?month`, `GET rentals/me/payouts`, `GET rentals/me/arrears` | person | own properties only |

### 9.1 Resource shapes (the API's `data`; the app and the portal read these names)

Every `*_minor` is an integer in the account's currency (`currency` is on the overview, every lease, payment and
statement). Dates are `Y-m-d`; instants ISO 8601 UTC. Objects in `{…}` below are always present (null when empty).

```text
Position     {owed_minor, overdue_minor, overdue_days, credit_minor, deposit_held_minor, paid_up_to, next_due:{date, amount_minor}|null,
              state: paid|due|overdue|credit}          (paid = nothing owed; due = owed, not overdue; credit = paid ahead)
LeaseBrief   {id, number, status, start_date, end_date, rent_minor, cycle_months, currency, unit:{id, code}, property:{id, name},
              tenant:{id, name, phone}, position:Position}
Lease        LeaseBrief + {landlord:{id, name}, anchor, deposit_minor, deposit_held_by, commission:{type, bp, minor}, late_fee, billed_until,
              notice_on, move_out_on, ended_on, end_reason, notes, items:[Item], rent_changes:[{rent_minor, effective_from, reason}],
              charges:[Charge], payments:[PaymentBrief], follow_ups:[FollowUp]}
Item         {id, name, amount_minor, goes_to, commissionable, starts_on, ends_on, active}
Charge       {id, lease_id, kind, description, period_start, period_end, due_date, amount_minor, paid_minor, left_minor, status, goes_to,
              overdue, days_overdue}
PaymentBrief {id, uuid, number, paid_on, amount_minor, method, reference, reversed, tenant:{id, name}, unit:{id, code}, property:{id, name}}
Payment      PaymentBrief + {lease:{id, number}, currency, received_by:{id, name}|null, channel, notes, unallocated_minor,
              allocations:[{charge_id, description, source, amount_minor, commission_minor, landlord_minor, agency_minor, deposit_minor}],
              reversed_at, reverse_reason, owed_after_minor, paid_up_to, receipt:{text, pdf_url}}
Landlord     {id, code, name, phone, email, address, payout_method, payout_details, commission:{type, bp, minor}|null, is_self, status,
              properties_count, units_count, balance_minor, notes}   (payout_method, payout_details and notes are null for a caretaker, §7)
Property     {id, code, name, kind, address, area, district, lat, lng, photo_url, landlord:{id, name}, commission:{…}|null,
              deposit_held_by, water_rate_minor, power_rate_minor, status, units_count, occupied_count, vacant_count, occupancy_pct,
              arrears_minor, staff:[{user_id, name}]}
Unit         {id, property:{id, name}, code, kind, floor, block, bedrooms, bathrooms, size_sqm, rent_minor, deposit_minor, cycle_months,
              status, state: vacant|occupied|overdue|notice|reserved|maintenance|inactive, water_meter, power_meter, notes, photo_url,
              lease:LeaseBrief|null}
Tenant       {id, code, kind, name, phone, alt_phone, email, id_type, id_number_masked, occupation, employer, next_of_kin_name,
              next_of_kin_phone, photo_url, notes, status, owed_minor, credit_minor, has_login, leases:[LeaseBrief]}
Payout       {id, number, landlord:{id, name}, amount_minor, method, reference, paid_on, period_from, period_to, notes, reversed}
Expense      {id, number, property:{id, name}|null, unit:{id, code}|null, landlord:{id, name}|null, category, description, vendor,
              amount_minor, method, paid_on, charged_to, receipt_url, maintenance_id, reversed}
Maintenance  {id, uuid, number, property:{id, name}, unit:{id, code}|null, tenant:{id, name}|null, title, details, priority, status,
              reported_by, assigned_to:{id, name}|null, assignee_name, photos:[{uuid, url}], cost_minor, expense_id, resolved_on,
              resolution, created_at}
MeterReading {id, uuid, unit:{id, code}, utility, reading, previous, used, rate_minor, amount_minor, read_on, reset, charge_id}
FollowUp     {id, channel, note, owed_minor, promise_on, by:{id, name}|null, created_at}
Overview     {currency, mode, units, occupied, vacant, occupancy_pct, month:{billed_minor, collected_minor, rate_pct},
              arrears:{total_minor, leases, aging:{d0_30, d31_60, d61_90, d90_plus}}, credit_minor, deposits_minor, landlords_minor,
              maintenance_open, ending_30, cash:{cash, bank, mobile_money, cheque, total},
              attention:[{kind: overdue|vacant|ending|urgent|promise, title, detail, link:{type, id}}], today:[PaymentBrief]}
Statement    {landlord:{id, name}, from, to, currency, opening_minor, closing_minor,
              lines:[{date, type, description, property, unit, tenant, gross_minor, commission_minor, amount_minor}],
              totals:{collected_minor, commission_minor, expenses_minor, payouts_minor, deposits_minor, other_minor},
              occupancy:{units, occupied, vacant}, arrears:[{unit, tenant, owed_minor, days}]}
Person (me)  {type: tenant|landlord, account:{id, name, currency, payment_instructions}, tenant|landlord: Tenant|Landlord (own fields only)}
```

Refusal codes: `unit_taken`, `unit_unavailable`, `lease_not_live`, `amount_invalid`, `date_in_future`,
`idempotency_conflict`, `charge_paid`, `charge_void`, `nothing_to_waive`, `deposit_short`, `insufficient_cash`,
`already_reversed`, `reading_lower`, `currency_locked`, `out_of_scope`, `plan_limit_reached`, `module_exclusive`.

---

## 10. Portal (P)

### 10.1 Staff screens (`/rentals`, `module:rentals`, `App\Livewire\Shop\Rentals\…`)

| Route name | Screen | Main action |
|---|---|---|
| `rentals.overview` | `Overview` | "Receive payment". KPI row; this month (billed, collected, rate); arrears aging bar; attention list; today's payments |
| `rentals.setup` | `Setup` | First run: agency or owner, currency, defaults, first property and units (skippable) |
| `rentals.properties` / `.show` | `Properties\Index` / `Show` | Properties with occupancy and arrears; a property's units grid (status colours), landlord, staff, expenses |
| `rentals.units` / `.show` | `Units\Index` / `Show` | All units with filters (vacant, occupied, overdue, notice); a unit's current lease, history, meter readings |
| `rentals.tenants` / `.show` | `Tenants\Index` / `Show` | Tenants; a tenant's leases, position, payments, documents, sign-in |
| `rentals.leases` / `.show` | `Leases\Index` / `Show` | Leases by status; a lease: position card, charges, payments, items, rent history, deposit, follow-ups; Move out, Notice, Change rent, Add charge, Waive |
| `rentals.move-in` | `Leases\MoveIn` | A three-step move-in: unit → tenant → terms (deposit, fees, first payment) |
| `rentals.collect` | `Collect` | Find tenant or unit → amount (with what it will settle) → receipt. Keyboard-first |
| `rentals.payments` | `Payments` | Receipts list; reverse |
| `rentals.billing` | `Billing` | Bills raised, upcoming, "Raise bills now", one-off charge |
| `rentals.arrears` | `Arrears` | Aging, overdue leases with last follow-up and promise date; Remind (WhatsApp/SMS), Log a call |
| `rentals.landlords` / `.show` | `Landlords\Index` / `Show` | (agency) Balance owed; statement by month (PDF); Pay out |
| `rentals.payouts` | `Payouts` | (agency) |
| `rentals.expenses` | `Expenses` | Record (landlord or agency) |
| `rentals.cash` | `CashBook` | Balance per method; running cash book; Move money |
| `rentals.maintenance` | `Maintenance` | Board by status; open, assign, done with cost |
| `rentals.meters` | `Meters` | Readings per property for a date (a fast grid) |
| `rentals.reports` | `Reports` | The registry with filters and exports |
| `rentals.settings` | `Settings` | Account, billing, commission, deposits, late fees, payment instructions, messages, staff assignments |
| `rentals.activity` | `Activity` | The audit trail (`rental_activities`, rentals_settings as on the API): who did what and when, by person, kind and date, searchable |
| `rentals.import` | `Import` | CSV templates, dry run, import |

PDF/print controllers: receipt, landlord statement, tenant statement, lease summary (agreement), report exports,
all on signed or session-authorised routes with `throttle`.

### 10.2 Look

Light only (owner rule), flat, **square corners** (`--r: 0` on Estate Ledger pages), hairline borders, no shadows, no accent
stripes, tabular figures. Status colours carry meaning only: paid (green), due (amber), overdue (red), vacant (grey),
notice (violet). The brand ink and brass are chrome only.

### 10.3 People portal (`/my`, `App\Livewire\RentalPortal\…`)

- Tenant: Home (what I owe, paid up to, next bill, how to pay), Bills, Payments (receipts PDF), My lease, Maintenance
  (report with photo, follow status), Give notice.
- Landlord: Home (owed to me, collected this month, occupancy), Properties (units and who pays), Statements (by
  month, PDF), Payouts, Arrears (by unit, read only).

### 10.4 Receipt

Number, date, tenant, unit and property, amount in figures and words, method and reference, what it settled (per
charge), credit left, balance still owed, "paid up to", received by, the account's footer. PDF (dompdf) and a plain
text form for WhatsApp and 58 mm Bluetooth printers.

---

## 11. App (A)

### 11.1 Flavor `estate-ledger`

- Gradle `productFlavors { estate-ledger { dimension "app"; applicationId "com.solavia.estateledger"; resValue "string","app_name","Estate Ledger" } }`,
  `android/app/src/keyroll/res` (launcher icons, splash), manifest as Akiba's.
- `AppFlavor.keyroll`: name `Estate Ledger`, accent ink `#1E2B33`, slug `estate-ledger`, `home: FlavorHome.module`,
  `modules: ['rentals']`, `module: 'rentals'`, `businessType: 'rentals'`, `signUpModules: ['rentals']`,
  `demo: false`, `liveDemo: true` once the demo exists (its own, §14), `pinRequired: false`, `memberSignIn: true`
  (here: "I'm a tenant or landlord"), `wording: 'estate-ledger'`, `menuKeys: _accountMenu`, `square: true` (new flag: the
  theme draws every shape with square corners), three intro slides (collect, arrears, landlords).
- Strings `fam_*_rentals` and `kr_*` in EN and SW (LG later).

### 11.2 Screens (`lib/rentals/screens`)

- **Staff:** Home (KPIs, attention, quick actions: Receive payment, Move in, Log maintenance, Meter reading);
  Properties → property (units grid by status) → unit → lease; Tenants (search) → tenant; Arrears (sorted by amount
  or days; call, WhatsApp, SMS, log a follow-up, promise date); Receive payment (works offline, §11.3) → receipt
  (share as text or PDF, Bluetooth print); Move in; Move out; Maintenance (with photos); Meter readings (fast entry per
  property); Landlords → statement (agency); Expenses; Reports (summary figures and PDF links).
- **Tenant:** balance, paid up to, bills, receipts, how to pay, report a problem with a photo, my lease.
- **Landlord:** owed to me, properties and occupancy, statements, payouts.

### 11.3 Offline

Reads are cached (sqflite) with "as of" times. Three writes queue offline with a `uuid` and are sent when the network
returns: payments, meter readings and maintenance requests. The server's uuid idempotency makes a retry safe. A queued
payment shows a "Not sent yet" receipt; its number and split come from the server once sent. Move-in, move-out,
reversals, payouts and expenses need the network.

## 12. Site (P, `config/apps.php` entry `estate-ledger`)

`name` Estate Ledger, `full_name` "Estate Ledger – Rent & Tenants", `slug`/`key` `estate-ledger`, `domain` `env('APP_ESTATE_LEDGER_DOMAIN','')`,
`flavor` `estate-ledger`, `tagline` "Every door, every tenant, every shilling.", `audience` "Letting agencies, landlords
and property managers", `modules`/`business_types` `['rentals']`, `icon` `key`, `accent` `#1e2b33`, `accent_soft`
`#f1ece2`, `icon_image` its own, `application_id` `env('APP_ESTATE_LEDGER_ANDROID_ID','com.solavia.estateledger')`,
`play_url` `env('APP_ESTATE_LEDGER_PLAY_URL','')`, `category` `BusinessApplication`, `sub_category` "Property and tenant
management", `plan_rows` `['max_units','max_users','max_devices']`, `demo` from `APP_ESTATE_LEDGER_DEMO_USERNAME`. A full
multi-page site like the others (features with deep pages, `/for/agencies`, `/for/landlords`, `/for/caretakers`,
how it works, FAQ, pricing, get the app, help, contact, legal, demo, register, login), copy in
`resources/content/sites/estate-ledger.php`. It never names another app (KR-05).

## 13. Jobs and commands

| Command | When | What |
|---|---|---|
| `rentals:daily {--company=} {--date=}` | daily 01:40, `withoutOverlapping` | `RentalDaily` for every rentals account: bills, late fees, credit, reminders for notices ending |
| `rentals:check {--company=}` | daily 03:20 | §5.12; failures to System health and the owner alert |
| `rentals:demo {--rebuild}` | hourly :40 (the host runs the scheduler every 20 minutes) | §14 |
| `rentals:import {path} {--company=} {--as-of=} {--dry-run} {--allow-existing}` | manual | §5.10; `path`: a folder with one CSV per sheet |

A missed night catches up: `raise` bills every period up to the horizon, and late fees look back over every open
charge, so running twice never double-posts.

## 14. Demo

`Demo\RentalsDemo` (run, heal, rebuild, guards; marker `onboarding_state.rentals_demo`) and
`Demo\RentalsDemoBuilder` (day by day from 12 months ago with `Carbon::setTestNow`, all through the services). Data in
`database/data/demo_rentals.php`. Two accounts, both on paid plans so the tenant and landlord portals work: the agency,
**Ntinda Property Managers (Demo)**, with 5 landlords, 8 properties (flats, rooms, shops, an office block), 120 units,
tenants paid up, paid ahead and in every aging bucket, part payments, promises and reminders, percent and flat commission,
landlord expenses and payouts, move-outs with damage and water deductions, a deposit refunded later, a former tenant who
still owes, vacant units, meter readings and reversed payments with their reasons; and **Mukasa Rentals (Demo)**, a landlord
on his own in owner mode with his own sign-in. Logins `<role>@ntindaproperty.example` (owner, manager, accountant,
caretaker, viewer) plus a landlord and tenants on the portal; password `rentals.demo.password`. Hourly heal, rebuild every
72 h; visitors cannot change the logins, settings or plan. The portal's `/demo` on the Estate Ledger host lists the sign-ins
(`AppSite::ownDemo`).

## 15. Plans (line `estate-ledger`, `EstateLedgerPlanSeeder`)

| Slug | Units | Users | Price (UGX a month, set by the owner, EL-12) |
|---|---|---|---|
| `estate-ledger-trial` | 300 | 10 | 30-day trial |
| `estate-ledger-free` | 10 | 2 | 0 (no `rentals_portal`) |
| `estate-ledger-starter` | 60 | 4 | 35,000 |
| `estate-ledger-growth` | 250 | 10 | 95,000 |
| `estate-ledger-agency` | 2,000 | 30 | 250,000 |

Annual = 10 months' price. The quota counts units that are not `inactive`.

## 16. Messages

Kinds: `bill` (a new bill), `due` (due in 3 days), `overdue`, `receipt`, `statement` (landlord), `notice`. Each has an
English default with placeholders `{tenant} {unit} {property} {amount} {balance} {due_date} {paid_up_to} {receipt}
{account} {pay_to}`, overridable in Settings. They are sent as free links opened on the user's own phone
(WhatsApp `wa.me`, `sms:`); the server sends nothing unless platform messaging is configured and the account's
`rentals_sms` entitlement is on (KR-18). The screens say a link was prepared, never that a message was delivered. A link
someone opens is written down by `RentalMessages::recordPrepared`: a `message_log` row with status `prepared`, and with the
channel's `log` driver (development and tests) the line a simulated message writes to the log (EL-23).

## 17. Reports (`RentalReports`)

`monthly_building` (one page for a calendar month, its last day whole: income by kind, expenses, final balance, with deposits and money paid ahead apart and never income), `deposit_register` (per tenant: received, used, refunded, held), `rent_roll` (units, tenant, rent, deposit, paid up to, owed), `collections` (by day, property, method, collector),
`arrears_aging` (0–30, 31–60, 61–90, 90+), `occupancy` (occupied, vacant, vacancy days, rent lost), `landlord_statement`,
`commission` (agency), `expenses`, `deposits_held`, `cash_book`, `tenant_statement`, `lease_expiries`,
`maintenance`, `income_statement` (agency: commission + fees − own expenses; owner: rent − expenses), `tenant_list`,
`lease_list`, `receipts`. Each runs as json, CSV (BOM, formula-safe cells), XLSX (`XlsxWriter`) and PDF (dompdf), filtered
by period and property.

Caretakers have no reports: `rentals_reports` is not in their role (§7). Only if an owner grants it by a role override is a
run narrowed to their properties, with the whole-account reports refused.

A list's Export CSV opens the matching report with the list's filters, so the file holds what the list shows (EL-21):
Tenants `tenant_list` (`status`, `q`); Units `rent_roll` (`state`, `property_id`, `q`); Leases `lease_list` (`status`,
`property_id`, `q`); Payments `receipts` (`from`, `to`, `view`, `method`, `property_id`, `q`); Arrears `arrears_aging`
(`bucket`, `property_id`, `q`); Expenses `expenses` (`from`, `to`, `status`, `category`, `property_id`, `q`).

## 18. Security and data protection

- `CompanyScope` on every `Rental*` model; `TenantIsolationTest` covers every `rentals/*` `{id}` route; the portal
  person tests cover tenant A reading tenant B (same and other account) and landlord A reading landlord B.
- ID numbers encrypted (`DataCrypt`), never in logs, exports (except the tenant's own record) or the phone cache.
- Photos and documents through `files` (8 MB, jpg/png/webp/pdf), served by signed links.
- Demo accounts refuse sign-in changes, plan changes and exports of the logins.
- Portal sign-ins are throttled and locked after failures; the session is regenerated on sign-in.

## 19. Tests

| Kind | Where | Examples |
|---|---|---|
| Unit | `tests/Unit/Rentals/` | `Periods` (anchors, Feb ends, proration), `Splits` (percent and flat, part payments sum exactly), `Minor` |
| Money flows | `tests/Feature/Rentals/` | billing idempotent; payment allocation order; credit applied later; reversal reopens and re-applies; deposit at move-out; waiver; late fee once; payouts and expenses; owner mode; `rentals:check` passes after each |
| Property | in `RentalsMoneyFlowTest` | 300 random seeded operations → every row balances, caches = recomputed, `rentals:check` clean |
| Concurrency | `ConcurrentRentPaymentsTest` (`pcntl_fork`) | two payments on one lease at once → both posted once, no overpaid charge |
| API | `RentalsApiTest`, `RentalsPersonApiTest` | every endpoint, refusals, idempotency, scope (caretaker), isolation |
| Reports, import, operations | `RentalsReportsTest`, `RentalsImportTest`, `RentalsOperationsTest`, `RentalsEngineDefectsTest` | hand-computed books, exports, every import refusal, meters, late fees, commission, quotas, personal IDs |
| Portal | `tests/Feature/Shop/Rentals/*Test.php`, `RentalPortalTest` | the five proofs per screen; one browser pass for page errors at 390 and 1366 |
| App | `test/rentals/*` | offline queue, money formatting, flavor parity (`flavor_test.dart`) |

## 20. Configuration (`config/rentals.php`)

```php
return [
    'plans' => ['trial' => 'estate-ledger-trial', 'free' => 'estate-ledger-free'],
    'defaults' => ['bill_days_before' => 5, 'grace_days' => 5, 'anchor' => 'start', 'commission_bp' => 1000, 'deposit_months' => 1],
    'unit_kinds' => [...], 'expense_categories' => ['Repairs', 'Cleaning', 'Security', 'Garbage', 'Water', 'Electricity', 'Property rates', 'Rental income tax', 'Legal', 'Agency office', 'Other'],
    'item_presets' => ['Garbage', 'Security', 'Water (flat)', 'Service charge', 'Parking'],
    'demo' => ['enabled' => env('DEMO_RENTALS', true), 'password' => env('DEMO_RENTALS_PASSWORD', 'demo-estate-ledger-2026'), 'rebuild_hours' => 72, 'months' => 15, 'timezone' => 'Africa/Kampala'],
];
```
