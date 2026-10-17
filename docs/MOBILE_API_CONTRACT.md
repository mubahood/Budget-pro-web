# Budget Pro phone app: API contract (web-shop parity)

> **Hosting note (verified 27 Sep 2026):** the production host's firewall (ModSecurity) answers **406** to a POST that has
> no body. Always send a JSON body, `{}` when there is nothing to send, with `Content-Type: application/json`. The phone
> app's `ApiService.post(endpoint, {})` already does this, so `auth/logout` and `auth/refresh` work.

Server: budget-pro (Laravel 10). Every path below is under `/api/v1`. The machine-readable spec is
`docs/openapi.json`, regenerated with `php artisan api:docs`.

## 0. Conventions (unchanged)

- **Envelope.** Every JSON response is `{ "code": 1|0, "message": "…", "data": …, "meta"?: {…}, "errors"?: {…} }`.
  Success is `code: 1` with HTTP 200 or 201. Failure is `code: 0` with HTTP 4xx and, usually, `errors.code` set to a
  machine code.
- **Auth.** Send `Authorization: Bearer <token>`. Without it the server answers `401`.
- **Tenant.** The shop is always the token's company. An id from another shop answers `404`, never its data.
- **Permissions.** Missing permission: `403 {errors: {code: "forbidden", permission: "<perm>", any_of?: [...]}}`.
  Permission names come from `config/permissions.php`: sell, discount, void, refund, approve, restock, adjust,
  stock_take, manage_products, view_cost, view_profit, view_reports, manage_finance, manage_budget,
  resolve_conflicts, manage_team, manage_settings, billing.
- **Supermarket features.** Each supermarket endpoint is gated by a StoreFeatures switch. When the switch is off:
  `403 {errors: {code: "feature_off", feature: "<key>", any_of?: [...]}}`. Read the switches from `auth/me → store`.
- **Modules.** Every endpoint belongs to a module (`x-module` in `openapi.json`): `shop`, `finance`, `budget`, `poultry`,
  `pingpin`, or `core` (every account: auth, meta, company, team, billing, onboarding, devices, sync, files, uploads,
  chat, members, dashboard, `tracking/devices/register`). An account without the module gets
  `403 {message: "Shop is not on your account.", errors: {code: "module_off", module: "shop", add: "PUT /api/v1/company/modules"}}`
  (for `pingpin`, `add` is `POST /api/v1/tracking/devices/register`). Read the account's modules from `auth/me → company.modules`.
  Sync stays core: a hidden module's rows keep syncing and are never lost.
- **Business rules.** A broken rule answers `422 {errors: {code: "<rule>", …meta}}`. Validation errors answer
  `422 {errors: {field: [messages]}}`.
- **Headers.**
  - `X-App-Version: 1.4.0`. Apps older than `mobile.min_version` get `426 {errors: {code: "upgrade_required", min_version, store_url}}`.
    A request **without** the header is still served; the server logs it once a day per caller.
  - `X-Device-Id: <device uuid>`. Required for `sync/push`. It is also used by bootstrap paging and the X report.
- **Money.** Amounts in the currency of `company.currency`. Sync rows carry money as strings (`"1500.00"`).
  Endpoint JSON uses numbers.
- **Dates.** ISO-8601 timestamps. Sync ops use milliseconds since the epoch (`occurred_at`, `client_updated_at`).

---

## 1. Auth

| Method | Path | Body | Result |
|---|---|---|---|
| POST | `auth/login` | `{identifier or email, password, device_name?}` | `{token, token_type: "Bearer", expires_at, user, company}` |
| POST | `auth/register` | (as before) | same, plus `201` |
| POST | `auth/otp/verify` (login) | (as before) | same as login |
| POST | `auth/refresh` | – | new `{token, token_type, expires_at, user, company}`; the old token is revoked |
| POST | `auth/logout` | – | revokes the **current** token |
| POST | `auth/logout-all` | – | revokes every token of the user |
| GET | `auth/me` | – | profile manifest (below) |

`GET auth/me`. The keys `store` and `expires_at` are new; everything else is as before:

```json
{
  "code": 1, "message": "Profile loaded.",
  "data": {
    "user": {…}, "company": {…CompanyResource…}, "roles": […], "subscription": {…}, "entitlements": {…},
    "role": "owner", "permissions": ["sell", "…"],
    "store": {
      "mode": true,
      "features": {"pack_barcodes": true, "weighed_items": true, "approvals": true, "held_carts": true, "fast_tender": true,
                   "offline_till": false, "promotions": true, "loyalty": true, "gift_cards": true, "cash_control": true, "…": true},
      "settings": {"cash_rounding": 50, "note_buttons": [1000, 2000, 5000, 10000, 20000, 50000],
                   "scale_prefixes": ["20","21","…"], "scale_format": "price", "scale_item_digits": 5, "scale_value_decimals": 0,
                   "override_limit_pct": 10, "waste_limit": 0, "loyalty_spend_per_point": 1000, "loyalty_point_value": 10,
                   "loyalty_silver_spend": 1000000, "loyalty_gold_spend": 5000000, "short_dated_days": 14, "age_min": 18, "tax_inclusive": true}
    },
    "expires_at": "2027-03-26T10:00:00+00:00"
  }
}
```

Refresh the token before `expires_at` with `POST auth/refresh`. A `401` means the token is gone: sign in again.

## 2. Company / business settings

`GET company` and `PUT company` work as before (owner only for PUT). CompanyResource has these additive keys:

- `currency_locked` (bool). When `true`, the currency can only change through `POST company/currency`.
- `shop.credit_terms_days` and `shop.debt_reminders_enabled`.
- `store` (the same block as in `auth/me`).

`PUT company` takes any of these fields (each one optional):

- Profile: `name, phone_number, phone_number_2, email, address, slogan, logo, website, about`
- Money: `currency` (refused once `currency_locked`), `timezone`, `tax_rate`
- Receipts: `receipt_header, receipt_footer, receipt_channels[whatsapp|print|sms], payment_methods[]`
- Stock: `low_stock_default, negative_stock_policy (flag|allow|block), require_shift`
- Customers: `credit_terms_days, debt_reminders_enabled`
- Modules: `enabled_modules[]`

### POST `company/currency/preview` (manage_settings; owner only)
Request `{to: "KES", mode: "relabel"|"convert", rate?: 0.0345}`. `rate` means 1 old unit = `rate` new units, and is
required for `convert`.

```json
{"code":1,"data":{"from":"UGX","to":"KES","mode":"convert","rate":0.0345,"tables":{"stock_items":120,"sale_records":900},"rows":1020,
  "examples":[{"name":"Sugar 1kg","before":5000,"after":172.5}]}}
```

### POST `company/currency` (manage_settings; owner only; throttled 10/min)
Request `{to, mode, rate?, password}`. Response `{change: {id, from_currency, to_currency, mode, rate, rows, …}, company: {…}}`.

Errors (422): `wrong_password`, `invalid_rate`, `same_currency`, `invalid_currency`, `invalid_mode`.
Errors (403): `owner_only`.

After a change, synced rows get new `server_seq` values. Pull again; do not bootstrap.

### POST `company/logo` (manage_settings; throttled 10/min)
Multipart form with one field, `file`: a JPG, PNG or WebP image of at most 2 MB. This POST is the one exception to
the JSON-body rule: send it as `multipart/form-data`.

The logo is stored the same way as on the web Business settings screen, in public storage as
`images/logo-{company}-{random}.{ext}`, and saved to `company.logo`.

Response `200 {logo: "images/logo-12-AbC….png", logo_url: "https://…/storage/images/logo-12-AbC….png", company: {…}}`.

- Errors (422): validation on `file` (missing, not an image, too big).
- To remove the logo, send `PUT company {logo: null}`.

## 3. Store features

### GET `store-features` (every member)
```json
{"code":1,"data":{"mode":false,"features":{"pack_barcodes":false,"…":false},"settings":{"cash_rounding":0,"note_buttons":[1000,2000,5000,10000,20000,50000],"…":"…"},
  "labels":{"pack_barcodes":"Barcodes per pack (can, 6-pack, carton)","…":"…"}}}
```

### PUT `store-features` (manage_settings)
Request: `{mode?: bool, features?: {key: true|false|null}, settings?: {key: value}}`.

- A feature set to `null` stops overriding the mode.
- Validation is `StoreFeatures::rules()`. Values are typed like the defaults, so `"50"` becomes `50`.
- An unknown feature key answers `422 unknown_feature`. A bad setting answers `422 {errors: {"settings.scale_format": [...]}}`.
- The response is the same block as the GET.

Features: pack_barcodes, weighed_items, department_keys, approvals, held_carts, fast_tender, customer_display,
age_check, deposits, offline_till (opt-in only), training_mode, hardware, price_book, price_levels, promotions,
markdowns, shelf_labels, loyalty, gift_cards, exchanges, scan_receiving, fefo, aisle_counts, break_packs,
smart_reorder, supplier_prices, landed_cost, consignment, cash_control, blind_cashup, tax_classes, fiscal,
store_prices, store_scoping.

## 4. Onboarding

### GET `onboarding`
The response is as before, plus:

```json
"has_sales": false,
"first_sale_at": null,
"quota": {"used": 12, "max": 100},
"presets": {
  "business_types": {"retail": "General shop / retail", "…": "…"},
  "business_type_details": {"retail": {"label":"General shop / retail","group":"Food & everyday","icon":"fa-store","hint":"Sugar, soap, soda, airtime","modules":["shop","finance"]}}
}
```

- `business_types` stays `key → label`, because older apps show the value as text. New apps read `business_type_details`.
- `quota.max` is `null` when the plan has no product limit.

### GET `onboarding/templates?business_type=pharmacy`
Response: `{business_type, version, currency, items: [{key, name, category, sub_category, unit, selling_price, buying_price}]}`.
An unknown type answers `422 invalid_business_type`. Without `business_type`, the shop's own type is used.

### POST `onboarding/templates/apply` (manage_products)
Request `{items: [{key, selling_price?, buying_price?, opening_stock?}]}`. Response `201 {created: 5, skipped: ["Soap"]}`.
If any item has no price: `422 prices_required {items: [names]}`, and nothing is created.

### POST `onboarding/import` (manage_products)
Send multipart `file` (CSV or .xlsx) or `csv` text.

- With `dry_run: true`: `200 {rows: [...], errors: [{row, message}], count}`.
- Without it: `201 {created, skipped}`. Rows with problems answer `422 import_errors {rows}`.

## 5. Debtors and creditors

A debtor key is `c:<customer id>` (a customer account) or `n:<lower-cased name>` (credit sales that carry only a
typed name). The key contains `:`. Send it URL-encoded in the path (`debts/c%3A12/sales`), or as a `key` query or
body parameter (`debts/sales?key=n:thembo`). Use the parameter form when a name contains `/`.

### GET `debts?tab=debtors|creditors&q=` (sell, view_reports or restock; creditors need restock or view_reports)

Debtors:
```json
{"code":1,"data":[
  {"key":"n:thembo","type":"name","customer_id":null,"customer_uuid":null,"name":"Thembo","phone":null,"owed":5000,"sales":2,"oldest":"2026-09-01","last_sale":"2026-09-20"},
  {"key":"c:12","type":"account","customer_id":12,"customer_uuid":"…","name":"Amina","phone":"0772…","owed":3000,"sales":1,"oldest":"2026-09-10","last_sale":"2026-09-10"}
 ],"meta":{"tab":"debtors","total":8000,"count":2}}
```

Creditors are suppliers the shop owes:
`[{key: "s:4", type: "supplier", supplier_id, supplier_uuid, name, phone, owed}]`, with the same `meta` shape.

### GET `debts/{key}/sales` (or `debts/sales?key=`)
Returns the open sales, oldest first:
`[{id, uuid, receipt_number, sale_date, customer_name, customer_phone, total_amount, amount_paid, balance, due_date}]`,
with `meta: {key, owed}`.

### POST `debts/{key}/receive` (or `debts/receive` with `key` in the body) (sell)
Request `{amount, method, reference?, shift_id?, client_uuid?}`. `method` is any shop payment method except `credit`. `client_uuid`: §9a.4.

Response: `{received: 3500, debtor: {…row…}|null, owed: 1500}`.

How the money is applied:

- **Name debtor:** oldest sales first. Paying more than is owed answers `422 overpayment`.
- **Account debtor:** through CustomerService, so extra money stays as credit.

Errors: `422 nothing_owed`, `422 invalid_key`, `404` (another shop's customer).

### POST `debts/{key}/adopt` (or `debts/adopt` with `key`) (sell)
Request `{name, phone?}`. This turns a name debtor into a customer account, and all its sales move onto the account.

Response: `{key: "c:77", customer: {id, uuid, name, phone, balance, server_seq}}`. An account key answers `422 not_a_name`.

## 6. Supermarket endpoints

All of these need the subscription group (`api.subscription`). The feature each one needs is shown in brackets.

### Approvals [approvals]
`POST approvals` (throttled 30/min). Request:

```json
{"action":"void_sale|refund|reverse_payment|price_override|line_void_after_total|no_sale|waste|cash_out","pin":"4321",
 "context":{"amount":3000,"pct":15,"original":1000,"price":850,"sale_id":55,"reason":"…"}}
```

Response `201`:
```json
{"code":1,"data":{"approval_id":91,"action":"waste","approver":{"id":3,"name":"Owner"},"valid_minutes":10,"required":true}}
```

Errors (422): `wrong_pin`, `too_many_attempts`, `no_supervisor_pin`, `invalid_action`.

Pass `approval_id` to the action that needs it: `cash-movements`, `batches/{id}/write-off`, or a sync cash-movement op.
An approval can be used once, by the same person, within 10 minutes. Otherwise the action answers `422 approval_invalid`.

### X / Z reports [cash_control]

**GET `x-report?scope=shift|day&shift_id=&date=YYYY-MM-DD&location_id=`**

- `scope=shift` (the default) needs sell or view_reports. Without `shift_id`, it uses the caller's open shift.
  Someone else's shift needs view_reports.
- `scope=day` needs view_reports.

Response (ZReportService figures):
```json
{"scope":"shift","date":"2026-09-27","location_id":null,"shift_id":8,"shift_number":"SH-…","currency":"UGX","generated_at":"…",
 "sales":{"count":12,"gross":84000,"refunded":0,"net":84000},"by_method":{…},"tax":{"rate":18,"net_sales":84000,"vat":12813.56},
 "discounts":{"count":1,"total":500},"voids":{"count":0,"total":0},"refunds":{"count":0,"value":0,"refunded":0},
 "cash_movements":{…},"no_sales":0,"cashiers":[…],"over_short":0,"open_shifts":[…],"late_sales":[],
 "shift_totals":{…only for scope=shift…}}
```
With no open shift and no `shift_id`, the response is `404`.

**POST `z-reports`** (needs view_reports **and** manage_finance). Request `{date, location_id?}`.
Response `201 {id, number: "Z-2026-000001", business_date, location_id, closed_by, closed_by_name, created_at, net_sales, sales_count, totals: {…figures…}}`.
Errors (422): `day_closed`, `invalid_date`.

**GET `z-reports?from=&to=`** (view_reports). Returns up to 60 rows, newest first, without `totals`.

**GET `z-reports/{id}`** (view_reports). Returns one row with `totals`. With `?format=pdf` it returns an
`application/pdf` receipt 80 mm wide.

### Gift cards [gift_cards]

**GET `gift-cards/{code}`** (or `gift-cards?code=`) (sell). URL-encode the code. Spaces and dashes are ignored.
```json
{"id":5,"last4":"5678","balance":50000,"status":"active|stopped|expired|empty","usable":true,"expires_at":null,"customer_id":null}
```
An unknown code answers `404 gift_card_not_found`.

**POST `gift-cards`** (sell). Sells a card. Request `{amount, method, customer_id?, code?, shift_id?, reference?, client_uuid?}`.

- `method` is cash, momo, card and so on; `credit` answers `422 gift_card_credit`.
- `client_uuid` makes the sale idempotent.

Response `201 {…card row…, code: "1234 5678 9012 3456", payment_id, payment_uuid}`. The `code` is shown only once;
it is `null` when you supplied your own.

### Held carts [held_carts] (sell)

- `GET held-carts` returns `[{id, label, count, total, at, who, stale}]` (newest first, at most 50).
- `POST held-carts` takes `{state: {lines: [...], …anything the till needs…}, label?, customer_id?, location_id?, total?}`.
  Response `201 {id, label, count, total, at}`. If `total` is missing, it is `Σ quantity×unit_price − discount_amount` over `state.lines`.
  Errors (422): `empty_cart`, `too_many_held`.
- `POST held-carts/{id}/take` returns `{id, state}`. The cart leaves the list. A second take answers `404 held_cart_gone`.
- `DELETE held-carts/{id}` discards a cart.

### Short-dated stock [fefo or markdowns]

**GET `expiring?days=&q=`** (adjust or restock). `days` defaults to the `short_dated_days` setting.
It returns batches on hand, soonest first:
```json
[{"id":14,"stock_item_id":3,"product_uuid":"…","name":"Milk 500ml","sku":null,"batch_number":"L7","expiry_date":"2026-09-30","quantity":5,
  "location_id":null,"location":null,"selling_price":2000,"days_left":3,"markdown_id":null,"markdown_price":null,"markdown_pct":null,"markdown_barcode":null,
  "unit_cost":1500,"value":7500}]
```
`unit_cost` and `value` appear only with the view_cost permission. `meta.days` gives the window used.

**POST `batches/{id}/markdown`** [markdowns] (adjust). Request `{pct: 1..95}`.
Response `201 {id, stock_batch_id, stock_item_id, barcode: "MD00000012", pct, original_price, price, quantity, status: "active", …}`.
Print the `barcode` on the label. A sale line then sends `markdown_id` (see §7.5).

**POST `batches/{id}/write-off`** (adjust). Request `{qty, reason: expired|damage|lost|internal_use, note?, approval_id?}`.
Response `201 {movement_id, movement_uuid, type, quantity}`.

- Above the shop's `waste_limit` the server answers `422 approval_required {amount}`. Call `POST approvals`
  with `action: "waste"` and `context.amount`, then retry with the `approval_id`.
- Other errors (422): `invalid_quantity`, `invalid_movement_type`, `batch_not_found`.

### Shelf labels [shelf_labels] (manage_products or restock)

- `GET label-queue` returns `[{id, stock_item_id, product_uuid, name, selling_price, barcode, unit_id, reason, at}]`.
  `reason` is one of price_change, promotion, new, manual.
- `POST label-queue/printed {ids?: [..]}` returns `{printed: n}`. Without `ids`, every waiting label is marked printed.

### Tax classes [tax_classes]

- `GET tax-classes` (every member) returns `[{id, name, code, rate, is_default, label: "Standard 18%"}]`, with
  `meta: {inclusive, codes}`. The first call creates Standard, Zero-rated and Exempt.
- `PUT tax-classes` (manage_settings) takes `{classes: [{id?, name, code: standard|reduced|zero|exempt, rate, is_default?}], delete?: [ids]}`.
  It runs in one transaction and returns the list.
  Errors (422): `tax_class_default`, `tax_class_in_use`, plus validation errors.

### Suppliers

**GET `suppliers/{id}/prices`** [supplier_prices] (restock or view_reports). For each product, the supplier's price in force today:
`[{stock_item_id, product_uuid, name, cost, since, previous, buying_price}]`.

**GET `suppliers/{id}/scorecard?days=90`** [smart_reorder] (restock or view_reports). Response:
`{orders, fill_rate, deliveries_timed, avg_days_late, avg_lead_days, on_time_pct, lead_time_days, products_priced, price_changes, price_change_pct, changes: [{name, from, to, pct}], days}`.

### Cash movements [cash_control] (sell)

**POST `cash-movements`**. Request `{type: drop|pickup|paid_in|paid_out|no_sale, amount (0 for no_sale), reason (≥3 chars), shift_id, approval_id?, category_id?, client_uuid?}`.

Response `201`:
```json
{"id":4,"uuid":"…","shift_id":8,"shift_uuid":"…","type":"paid_in","amount":2000,"reason":"Float top-up","approved_by":null,"server_seq":81234,"expected_cash":12000}
```

- Errors (422): `not_enough_cash` (a drop or paid-out larger than the drawer), `reason_required`, `shift_closed`, `invalid_amount`, `approval_invalid`.
- `approval_required` comes back for paid_out and no_sale when approvals are on. Get an approval with action `cash_out` or `no_sale`, then retry.
- `client_uuid` makes the request idempotent.
- The same data can also be pushed offline as the sync table `cash_movements` (§7.3).

---

## 7. Sync protocol additions

The existing endpoints are unchanged: `POST sync/push`, `GET sync/pull?table=&since_seq=&limit=` (or `tables=a,b`),
`POST sync/bootstrap`, and `GET/POST sync/conflicts`.

### 7.1 POST `sync/pull-many` (new)
Request:
```json
{"tables":{"products":81200,"customers":81200,"tax_classes":1234567890123,"promotions":0},"limit":500}
```
Response. Each table has the same shape as a single `sync/pull`:
```json
{"code":1,"data":{"server_time":1790476640881,"tables":{
  "products":{"table":"products","rows":[…],"next_seq":81350,"has_more":false},
  "tax_classes":{"table":"tax_classes","rows":[],"next_seq":1234567890123,"has_more":false,"snapshot":false,"unchanged":true},
  "promotions":{"table":"promotions","rows":[…],"next_seq":4411223344556,"has_more":false,"snapshot":true},
  "nope":{"table":"nope","rows":[],"next_seq":0,"has_more":false,"error":"unknown_table"}}}}
```

- Keep each table's `next_seq` as its cursor.
- Pull again while any table has `has_more: true`.
- An unknown table does not fail the others.
- `limit` is 1–1000 rows per table.

### 7.2 Snapshot tables (new, pull-only)
These tables have no `server_seq`: `tax_classes`, `locations`, `product_prices`, `location_prices`, `promotions`,
`stock_batches`, `batch_markdowns`.

**The cursor is a fingerprint of the shop's whole set.** Treat it as an opaque integer. Rules:

- If the cursor matches the current set, the response is `{rows: [], unchanged: true, snapshot: false}`.
- Otherwise the response is `{rows: [the whole set], snapshot: true, next_seq: <new fingerprint>}`. **Replace** your
  local table with those rows. This includes deleting rows you have that are not in the set. An empty `rows` with
  `snapshot: true` means "clear the table".
- `has_more` is always false. `truncated: true` appears only if a set exceeded 20 000 rows.
- Pushing to these tables answers `rejected / read_only_table`.
- Each row carries a synthetic stable `uuid` (derived from the id), plus `server_seq` (the fingerprint), `version: 1`
  and `is_deleted: 0`. Key the rows by `id`.

Rows (all columns of the table, plus the `*_uuid` references):

| Table | Rows sent | Fields |
|---|---|---|
| `tax_classes` | all | `id, company_id, name, code (standard/reduced/zero/exempt), rate, is_default, created_at, updated_at` |
| `locations` | all | `id, company_id, name, address, is_default, is_active, …` |
| `product_prices` | all | `id, stock_item_id, product_uuid, unit_id, unit_uuid, level (retail/wholesale/member/…), price, min_qty` |
| `location_prices` | all | `id, location_id, stock_item_id, product_uuid, unit_id, unit_uuid, price, is_available` |
| `promotions` | all | `id, name, type, rules (object), starts_at, ends_at, window (object), member_only, stackable, priority, per_sale_limit, is_active, code, targets: [{target_type: product/category/sub_category, target_id, target_uuid}]` |
| `stock_batches` | `quantity > 0` | `id, stock_item_id, product_uuid, location_id, batch_number, expiry_date, quantity, unit_cost` |
| `batch_markdowns` | `status = active` | `id, stock_batch_id, stock_item_id, product_uuid, barcode (MD…), pct, original_price, price, quantity, sold_qty, sold_value` |

### 7.3 `cash_movements` (new event table: push and pull)
Pulled by `server_seq` like every event table. Pull row:
`{id, uuid, company_id, shift_id, shift_uuid, type, amount, reason, created_by, approved_by, financial_record_id, created_at, server_seq, version, is_deleted}`.

Push op (permission sell; the feature `cash_control` must be on):
```json
{"op_uuid":"…","table":"cash_movements","uuid":"<movement uuid>","action":"insert",
 "data":{"shift_uuid":"<shift uuid>","type":"paid_in","amount":2000,"reason":"Change from the bank","approval_id":null,"category_id":null}}
```

- The op is idempotent on `uuid`. A second push answers `replayed`.
- It is rejected (and so is its batch) for `feature_off`, `reason_required`, `not_enough_cash`, `shift_closed`,
  `approval_required` or `approval_invalid`, and for a missing `shift_uuid` (`missing_parent`).
- Offline you cannot get an approval. Queue `paid_out` or `no_sale` only when approvals are off, or when you already
  hold an `approval_id`.
- A movement is never updated or deleted. Record a new one to correct it.

### 7.4 New fields on existing tables
Pulled rows carry every column. Columns created by the supermarket migrations appear only on servers that have run them.

**products.** New columns: `tax_class_id, plu_code, sold_by (unit/weight/volume/length), open_price, deposit_item_id, shelf_location, track_batches, consignment_supplier_id, purchase_unit_id, min_age`.
New reference fields: `deposit_item_uuid, consignment_supplier_uuid, purchase_unit_uuid`.

Devices may **push** these (permission manage_products): `tax_class_id, plu_code, sold_by, open_price, shelf_location, track_batches, min_age`,
and the uuid references `deposit_item_uuid, consignment_supplier_uuid, purchase_unit_uuid`.

They are validated like the web form:

- `tax_class_id` must be this shop's class.
- `plu_code` is alphanumeric, at most 20 characters, and unique in the shop.
- `min_age` is 0–99 and `shelf_location` is at most 40 characters.

A bad value rejects the op with `validation` and `errors`.

**customers.** New columns: `price_level, marketing_opt_in, marketing_opt_in_at, messages_opt_out, messages_opt_out_at, reminders_enabled, payment_terms_days, tin`.
Pushable: `price_level, marketing_opt_in, messages_opt_out, reminders_enabled, payment_terms_days`.
The `*_at` dates are set by the server.

A new derived, read-only field `loyalty_points` (int) is the sum of the customer's loyalty ledger. It is recomputed
whenever the customer row is pulled again, for example after their balance changes. For the exact balance at
the till, read it online.

### 7.5 Sale op: new optional fields
Every field is optional. An op without them behaves exactly as before, including the legacy `amount_paid` path.
Synced sales are **not re-priced** on the server: the till's prices, promotions and rounding are kept, and the server
only checks that they make sense.

```json
{"table":"sales","uuid":"…","action":"insert","data":{
  "occurred_at":1790476640881,"has_payment_ops":1,"customer_uuid":"…","shift_uuid":"…",
  "rounding":40,                    // cash rounding the till applied (can be negative). Part of the total. Refused (rounding_mismatch) if |rounding| > total or it would make the total negative.
  "age_checked":1,                  // the cashier confirmed the age (stored as age_checked_by = pushing user)
  "coupon_code":"SAVE",             // informational (the coupon's promotion comes in `promotions`)
  "price_level":"wholesale",        // informational (the level the till priced at)
  "promotions":[{"promotion_id":7,"amount":100}],   // stored in sale_promotions (a name from the server's promotion; an unknown id is kept under the `name` sent, or "Promotion"). amount must be ≥ 0.
  "items":[{"product_uuid":"…","quantity":2,"unit_price":1030,
            "discount_amount":0,     // manual discount only, WITHOUT the promotion share
            "promo_discount":100,    // this line's share of the promotions (added to the line discount; stored as sale_record_items.promo_discount)
            "markdown_id":12}]       // a scanned markdown label: the stock comes out of that batch first
}}
```

- A negative `discount_amount`, `promo_discount` or `unit_price` rejects the op with `validation`.
- The sale total is `Σ(qty×unit_price − discount_amount − promo_discount) − header discount_amount (+ tax on top if the shop prices exclusive) + rounding`.

### 7.6 Payment op: tenders
`method` may now be `loyalty` (or `points`), `gift_card` or `store_credit`:
```json
{"table":"payments","uuid":"…","action":"insert","data":{"sale_uuid":"…","method":"gift_card","code":"1234 5678 9012 3456","amount":500}}
{"table":"payments","uuid":"…","action":"insert","data":{"sale_uuid":"…","method":"loyalty","points":120}}
{"table":"payments","uuid":"…","action":"insert","data":{"sale_uuid":"…","method":"store_credit","amount":3000}}
```

- These are applied through TenderService exactly as at an online till: no income row, and the card, points or
  credit balance goes down. The payment is capped at what is still due and what the tender holds.
- `loyalty` and `store_credit` need the sale to have a customer.
- An op is rejected (and so is its batch) for these codes: `feature_off`, `gift_card_not_found`, `gift_card_empty`,
  `gift_card_expired`, `gift_card_inactive`, `points_short`, `no_credit`, `customer_required`, `nothing_due`.
  **Check a gift card online first** with `GET gift-cards/{code}`.
- The op is idempotent on `uuid`.

**Loyalty.** When `loyalty` is on and the sale has a customer, points are earned once per sale, after the batch's
payment ops are applied: `floor(money paid, excluding points ÷ loyalty_spend_per_point)`.

### 7.7 POST `sync/bootstrap`: keyset paging and a history window
Request (every field optional):
```json
{"tables":["products","sales","tax_classes"],"page_size":500,
 "after":{"sales":81200},       // keyset: last server_seq received for each table (new apps)
 "upto":81999,                  // the `seq` of your first page: keeps every page at the same point
 "history_days":90,             // event history to send (default 90; 0 = everything)
 "page":1}                      // older apps: page numbers still work (their next page is remembered per X-Device-Id)
```
Response:
```json
{"code":1,"data":{"seq":81999,"history_days":90,"server_time":…,"tables_order":[…],"tables":{
  "sales":{"rows":[…],"next_page":2,"has_more":true,"next_after":81450,"cursor":81999},
  "products":{"rows":[…],"next_page":null,"has_more":false,"next_after":null,"cursor":81999},
  "tax_classes":{"rows":[…],"next_page":null,"has_more":false,"next_after":null,"cursor":4411223344556,"snapshot":true}}}}
```

- **New apps:** start with no `after` and no `upto`. Then, for each table with `has_more`, send
  `after: {table: next_after}` and `upto: <first seq>` until every table is done. Afterwards set each table's pull
  cursor to its `cursor`. For seq tables that is `seq`; for snapshot tables it is their fingerprint.
- Rows written during the bootstrap (seq > `upto`) arrive with the first pull from `seq`.
- **History window.** It applies to event tables only: shifts, sales, sale_items, payments, sale_returns, goods_receipts,
  stock_takes, stock_movements, cash_movements. They send the last `history_days`, plus anything still open:
  - unpaid, non-voided sales, with their lines and payments;
  - open shifts;
  - draft stock takes.

  Master data (products, customers, categories…) is always complete.

### 7.8 Push permissions (unchanged, plus one)
`cash_movements` needs sell.

## 8. Team chat

Any **active member** of the shop may chat, whatever their role (no permission needed). The routes sit in the
subscription group like the other shop endpoints (a lapsed plan answers 402). Rules come from
`App\Services\Chat\ChatService`, the same as the web's Chat screens:

- Another shop's conversation, message or person answers **404**.
- A conversation of your own shop that you are not in answers **403**.
- A deactivated member reads nothing and cannot be messaged (`422 chat_not_member`).

Realtime is polling. While a thread is open, call `messages?after=<last id>` about every 3 s. In the background,
call `chat/unread` about every 20 s. Stop both while the app is in the background.

| Method | Path | Body | Result |
|---|---|---|---|
| GET | `chat/conversations` | – | `{conversations: [Conv], members: [Member], unread, me: {id, name}}` |
| GET | `chat/conversations/{id}/messages?before=&after=&limit=` | – | `{conversation, messages: [Msg], has_more, seen_up_to, typing: [names], others: [...], deleted_ids: [ids]}` |
| POST | `chat/conversations/{id}/messages` | `{body}`, or multipart `file` (+ optional `body`) | `201` Msg (throttled 60/min) |
| POST | `chat/direct` | `{user_id}` | `{conversation: {id, type, title, other, members}}`: finds or creates the chat |
| POST | `chat/conversations/{id}/read` | `{message_id?}` | `{unread}`: the new total for the badge |
| POST | `chat/conversations/{id}/typing` | `{}` | `{typing: true, seconds: 6}` |
| DELETE | `chat/messages/{id}` | – | Msg with `deleted: true` (only your own messages) |
| GET | `chat/unread` | – | `{unread}` |
| POST | `presence` | `{}` | `{online: true, online_minutes: 5}`. It sets `last_active_at`, written at most once a minute. |

The item shapes:

- **Conv**: `{id, type: "team"|"direct", title, other: {id, name, avatar, online, last_active_at, active}|null,
  last: {id, mine, sender, body, image, deleted, created_at}|null, unread, last_message_at}`.
  - Conversations come latest first.
  - The shop's "Whole team" group is created on the first call. It stays on top until it has a message.
- **Member**: `{id, name, avatar, online, last_active_at}`. These are the people you can chat with, online first.
  Use them for the "Online now" row and the new-chat picker.
  - "Online" means active in the last 5 minutes.
- **Msg**: `{id, conversation_id, user_id, sender, mine, body, image_url, deleted, created_at}`.
  - A deleted message keeps its row, with `body` and `image_url` set to null.

How `messages` pages work:

- **Without `before` or `after`:** the latest `limit` messages (default 30, at most 100), oldest first.
  `has_more` says whether earlier ones exist.
- **With `before=<id>`:** the page before that message ("Load earlier").
- **With `after=<id>`:** every newer message, up to 200. This is the poll.

What each answer carries besides the messages:

- `seen_up_to` is the id up to which the other people have read. Show ✓✓ on your messages up to it.
- `typing` lists who is typing now. Send `typing` at most every 4 s while the user types; it lasts 6 s, and sending
  a message ends it.
- `deleted_ids` lists messages deleted in the last 10 minutes, so an open thread can blank them.

`read` without `message_id` marks the whole conversation read. With `message_id` it marks up to that message. The
pointer never moves back.

Pictures:

- They must be JPG, PNG or WebP, at most 5 MB. The file's content decides the type, not its name.
- Errors (422): `chat_bad_file`, `chat_file_too_big`, `chat_upload_failed`.

Other errors (422): `chat_empty`, `chat_too_long` (4000 characters), `chat_self`, `chat_not_member`,
`chat_not_yours`.

## 9. Stock, team and dashboard additions

Everything here is additive. Old requests keep working and get the same answers as before. Remember the hosting
note: send `{}` as the body of a POST that has nothing to send, such as `approve` or `cancel`.

### 9.1 Goods receipts: landed costs (restock)
`POST goods-receipts` and `POST purchase-orders/{id}/receive` accept two new optional fields:

- `landed_costs: [{label, amount}]` (at most 10). Lines with amount 0 are dropped. `label` is at most 60 characters.
- `landed_split: "value"|"quantity"` (default `value`).

They go to `GoodsReceiptService` as its `$options`, the same as the web receiving form. The extra costs are spread
over the lines only when the shop's `landed_cost` feature is on. The landed unit cost values the stock and becomes
the product's cost. The supplier's invoice (`total_cost`, what is owed) does not change, and the extras are recorded
as a paid stock expense of the delivery.

The receipt in the response (`data`, or `data.goods_receipt` for a purchase order) always carries
`landed_cost_total`, the amount applied (0 when nothing was applied, for example with the feature off). When costs
were applied, it also carries `landed_costs` (as an array) and `landed_split`, and each item carries
`landed_unit_cost`.

Errors (422): `invalid_landed_cost`, or validation errors on `landed_costs.*` and `landed_split`.

### 9.2 Stock requests and transfers in transit (writes: adjust)
This is the web's warehouse-to-stores flow (budget-pro-new `StockRequests`), through `StockRequestService` and
`TransferService`:

`requested → approved → sent (a transfer "in_transit") → received`. A request can be cancelled while it is
`requested` or `approved`.

Store scope: with the shop's `store_scoping` feature on, a member who is assigned to a store works for that store
only. They see only the requests and transfers that involve their store (others answer 404). They can ask only for
their own store, and send or receive only at their own end (`422 other_store`).

| Method | Path | Body | Result |
|---|---|---|---|
| GET | `stock-requests?status=&q=&per_page=` | – | Paged `[{id, number, status, status_label, from_location_id, to_location_id, from_name, to_name, stock_transfer_id, notes, requested_by_name, lines, quantity, created_at, sent_at, received_at}]`. Open requests come first. |
| GET | `stock-requests/{id}` | – | `{request: {…, from_name, to_name, transfer_number}, items: [{stock_item_id, name, barcode, sku, quantity, approved_quantity, sent_quantity, received_quantity, available}]}`. `available` is what the sending location has on hand. |
| POST | `stock-requests` | `{from_location_id, to_location_id, lines: [{stock_item_id, quantity}], note?}` | `201`, same shape as GET `{id}` |
| POST | `stock-requests/{id}/approve` | `{lines?: [{stock_item_id, quantity}]}` | The request. Without `lines`, the asked quantities are approved. A quantity of 0 means the product is not sent. |
| POST | `stock-requests/{id}/send` | `{lines?: [{stock_item_id, quantity}]}` | The request, with `stock_transfer_id`. Without `lines`, the approved quantities are sent (or the asked ones if the request was never approved). The stock leaves the sender now. |
| POST | `stock-requests/{id}/cancel` | `{}` | The request |
| GET | `stock-transfers?status=in_transit\|received&to_location_id=&from_location_id=` | – | The old list, with `status, from_location_id, to_location_id, sent_at, received_at, stock_request_id` added. With `status=in_transit` each row also has `items: [{stock_item_id, name, quantity}]`. `status` is null for a transfer that moved stock at once (`POST stock-transfers`). |
| GET | `stock-transfers/{id}` | – | `{transfer: {…, from_name, to_name, by}, items: [{stock_item_id, name, barcode, quantity, received_quantity}]}` |
| POST | `stock-transfers/{id}/receive` | `{lines?: [{stock_item_id, received_quantity}]}` | The transfer, as in GET `{id}`. Without `lines`, everything sent arrived. With `lines`, a product left out arrived as 0. |

Receiving puts the stock on the receiving store's shelf, with its batches. What did not arrive stays off both shelves
and shows as short. A transfer made for a request is received through the request, which becomes `received` and
gets its `received_quantity` values.

Errors (422): `same_location`, `empty_request`, `invalid_line`, `too_many_lines`, `product_not_found`,
`location_not_found`, `invalid_quantity`, `request_status` (the step is not possible in the current status),
`request_sent` (cancelling a request that is already on its way), `empty_transfer` (every quantity is 0),
`insufficient_stock`, `not_on_transfer`, `invalid_received`, `transfer_not_in_transit`, `other_store`.

Reads are open to every member, like `GET stock-transfers`.

### 9.3 Stock takes: shelf (aisle) counts and recounts (writes: stock_take)
- `POST stock-takes` accepts `shelf_location` (at most 40 characters, for example `"A3"`). With the shop's
  `aisle_counts` feature on, the count covers that aisle. It is stored uppercased (`"A3"` also covers `"A3-B2"`).
  With the feature off, the field is ignored.
- `GET stock-takes/shelf-locations` returns the shelf locations in use (`["A3-B1", …]`), with
  `meta.aisle_counts` (bool).
- `POST stock-takes/{id}/counts` returns the take with `items[]` (each with `needs_recount`, a bool) and
  `recount_needed: [stock_item_id…]`. With `aisle_counts` on, a count that is more than 10% and at least 1 unit away
  from the system quantity is flagged.
- **Recount:** `POST stock-takes/{id}/recount {counts: [{stock_item_id, counted_quantity}]}`. This is the second
  count of flagged products. It stands and clears the flag. Its response has the same shape as `counts`. A product
  that is not flagged answers `422 not_flagged`. (Sending the product again to `counts` also counts as the recount,
  as on the web.)
- `POST stock-takes/{id}/post` refuses while a line waits for a recount: `422 recount_needed {count}`.
- `GET stock-takes/{id}` items also carry `needs_recount` and `first_count`.

### 9.4 GET `stock-items/{id}/supplier-prices` [supplier_prices] (restock or view_reports)
This shows what each supplier charges for this product, cheapest first (`SupplierPriceService::forProduct`):

```json
{"stock_item_id":7,"product_uuid":"…","name":"Sugar 1kg","buying_price":5200,
 "suppliers":[{"supplier_id":3,"supplier":"Cheap Ltd","cost":5000,"since":"2026-09-20","previous":4800,"change_pct":4.2,"source":"receipt",
   "history":[{"cost":5000,"valid_from":"2026-09-20","source":"receipt"},{"cost":4800,"valid_from":"2026-08-01","source":"manual"}]}]}
```

`history` is newest first, at most 20 entries. Another shop's product answers 404.

### 9.5 Till PINs [approvals]
The same rules as the web (`ApprovalService::setPin`). A PIN is 4 to 6 digits and is stored hashed. Both routes
are throttled to 10 per minute and need the shop's `approvals` feature (`403 feature_off` otherwise).

- **PUT `team/members/{id}/pin` `{pin}`.** Any member may set their own PIN. Setting someone else's needs
  manage_team (`403 forbidden`, `permission: manage_team`). Only the owner can set the owner's PIN (`403 owner_pin`).
  A member of another shop answers 404. Response `{user_id, has_pin: true}`.
- **PUT `me/pin` `{pin, password}`.** This sets your own PIN after checking your account password
  (`422 wrong_password`). Response `{user_id, has_pin: true}`.

### 9.6 GET `dashboard?from=&to=` (or `?range=`)
Without parameters, the answer is exactly as before. With `from` and/or `to` (`YYYY-MM-DD`, the shop's local days;
a single one means that one day) or `range` (`today, yesterday, week, last_week, 7d, 30d, month, last_month, quarter,
year, last_year, period`), the old keys stay unchanged and these are added (`DashboardService::range` and `kpis`,
the web dashboard's figures):

```json
{"range":{"key":"custom","label":"01 Sep – 27 Sep 2026","from":"2026-09-01","to":"2026-09-27","days":27,"prev_from":"2026-08-05","prev_to":"2026-08-31","location_id":null},
 "kpis":{"sales":250000,"count":41,"avg":6097.56,"profit":61000,"margin":24.4,"collected":230000,"on_credit":20000,"expenses":15000,"net":46000,"returns_count":1,"returns_value":3000},
 "previous":{…same keys, for prev_from…prev_to},
 "change":{"sales":12.5,"count":-3.1,"profit":8,"collected":10.2,"expenses":null,"net":7.9}}
```

- `change` values are percentages, or null when the previous figure is 0.
- `kpis`, `previous` and `change` are null for a member without view_reports, view_profit or manage_finance
  (as on the web).
- A member limited to one store (`store_scoping`) gets that store's figures (`range.location_id`).

### 9.7 Sync: `goods_receipts` op, batches
Each item of a `goods_receipts` op may carry `batch_number` (at most 60 characters) and `expiry_date`
(`YYYY-MM-DD`), as the web receiving form does. They are used for products with `track_batches` and ignored for
others. An expiry that is not a date rejects the op with `validation`. Ops without these fields work as before.

```json
{"table":"goods_receipts","uuid":"…","action":"insert","data":{"supplier_uuid":"…","amount_paid":"0",
  "items":[{"product_uuid":"…","quantity":"12","unit_cost":"550","batch_number":"LOT-7","expiry_date":"2027-03-31"}]}}
```

## 9a. Sync reliability, offline supplier payments, idempotent money calls

### 9a.1 `supplier_payments` (new event op: push only)
Money paid to a supplier on the phone, queued offline. Permission `restock`. The server applies it with
`SupplierService::pay`, exactly like `POST suppliers/{id}/payments`: a Purchase expense in the ledger whose
`uuid` is the op `uuid`, and the supplier's `balance` (what we owe) goes down.

Push op:
```json
{"batch_uuid":"5d0c…","kind":"supplier_payment","ops":[
  {"op_uuid":"9e1f…","table":"supplier_payments","uuid":"<payment uuid>","action":"insert",
   "data":{"supplier_uuid":"<supplier uuid>","amount":"25000","method":"mobile_money","reference":"MM 77812","occurred_at":1791640800000}}]}
```

| field | required | notes |
|---|---|---|
| `uuid` (op) | yes | the payment's id. A second push with it answers `replayed`, nothing is paid twice. |
| `supplier_uuid` | yes, or `supplier_id` | the supplier (`supplier_id` = server id, used only when `supplier_uuid` is absent). |
| `amount` | yes | > 0, rounded to 2 places. |
| `method` | no | any shop payment method; default `cash`. |
| `reference` | no | max 191 characters; kept in the ledger row's `receipt`. |
| `occurred_at` | no | ms timestamp; the ledger date is that day in the shop's timezone (default today). |

- Op result: `applied` (with `id` and `server_seq` of the ledger row) or `replayed`.
- Rejections (the batch is rolled back): `missing_parent` (`parent: supplier_uuid`), `invalid_amount`,
  `immutable_event` (an action other than insert/upsert), `forbidden` (no `restock`).
- There is nothing to pull from `supplier_payments` (pull returns no rows). The effect comes down with the
  `suppliers` row (new `balance`, `server_seq` bumped) and the `financial_records` row (`uuid` = the op uuid).

### 9a.2 A bad batch never fails the push
Each batch is still one transaction. If one throws something unexpected, only that batch is rolled back and
the other batches in the same request are applied. Its result is one of:
- `{"batch_uuid":"…","status":"replayed","replayed":true,"ops":[{… "status":"replayed","id":…,"server_seq":…}]}`:
  a unique-key clash where every row of the batch is already on the server (the same batch saved by a
  parallel request). Treat as done.
- `{"batch_uuid":"…","status":"rejected","code":"retry_later","retry":true,"ops":[],"message":"…"}`: a clash
  whose rows are not there, or any other server error. Keep the batch, send it again later (back off), and
  carry on with the others. Nothing is recorded for it, so a resend applies it afresh.

### 9a.3 Rate limits
`sync/push`, `sync/pull`, `sync/pull-many` and `sync/bootstrap` allow 300 requests a minute per user and
`X-Device-Id`. Other API calls keep 60 a minute per user. A `429` carries `Retry-After` (seconds) and
`X-RateLimit-Limit` / `X-RateLimit-Remaining`. Stop the round and wait that long.

### 9a.4 `client_uuid` on online money calls
Send a fresh uuid per user action, and the same one on every retry of that action. A retry whose
`client_uuid` was already used answers `200` with the original result and `"replayed": true` (where the body
is an object) instead of doing it again.
- `POST suppliers/{id}/payments {amount, method?, reference?, client_uuid?}`: `201` first time; a retry
  answers `200 {ledger, supplier, replayed: true}`. The ledger row's `uuid` is the `client_uuid`.
- `POST debts/{key}/receive {…, client_uuid?}`: a retry answers `200 {received, debtor, owed, replayed: true}`.
- `POST purchase-orders {…, client_uuid?}`: `201` first time; a retry answers `200` with the same order.

## 9b. Phase D: meta, worth a look, record pages, safe retries, exports, open on the web

From `MOBILE_EXCELLENCE_PLAN.md` Phase D (budget-pro-mobo). Tones everywhere are one of
`ok | warn | bad | info | neutral` (the web badges' success, warn, danger, info, neutral).

### 9b.1 GET `meta` (every member; outside the subscription gate)
Read after sign-in and on resume. Send `If-None-Match: <last ETag>`: an unchanged answer is `304` with no body.
A lapsed shop still gets it (with its plan nudge). `plan` is `null` on a standalone copy, `brand_colour` is
`null` on the SaaS (keep Budget Pro green). `web_url` is `null` and `web_pages` is `[]` when the server has no web portal.
```json
{"code":1,"message":"Meta.","data":{
  "api_version":1,
  "business":{"id":12,"name":"Fresh Corner Market","logo_url":"https://…/storage/images/logo-12-ab.png","brand_colour":null,
    "currency":"UGX","decimals":0,"timezone":"Africa/Kampala",
    "money":{"code":"UGX","position":"before","space":true,"decimals":0,"thousands_separator":",","decimal_separator":".","sample":"UGX 1,234,568"}},
  "edition":{"edition":"saas","registration_open":true,"name":"Budget Pro","version":"dev"},
  "plan":{"name":"Business","slug":"business","status":"trialing","state":"active","ends_at":"2026-10-30T16:19:20+00:00","days_left":14,
    "badge":"Trial: 14 days left","badge_kind":"trial","tone":"neutral",
    "nudge":"Your free trial ends in 14 days. Choose a plan to keep selling without a break.","urgent":false,"can_pay":true},
  "user":{"id":7,"name":"Test Owner","role":"owner","role_label":"Owner","permissions":["sell","discount","void","…"],"store_id":null},
  "payment_methods":[{"value":"cash","label":"Cash","pay_later":false},{"value":"mobile_money","label":"Mobile money","pay_later":false},
    {"value":"credit","label":"Credit (pay later)","pay_later":true}],
  "enums":{
    "sale_status":[{"value":"Completed","label":"Completed","tone":"ok"},{"value":"Partially Refunded","label":"Partly returned","tone":"warn"},
      {"value":"Refunded","label":"Returned","tone":"bad"},{"value":"Voided","label":"Voided","tone":"neutral"}],
    "payment_status":[{"value":"Paid","label":"Paid","tone":"ok"},{"value":"Partial","label":"Part paid","tone":"warn"},{"value":"Unpaid","label":"Not paid","tone":"bad"}],
    "payment_method":[{"value":"cash","label":"Cash","tone":"neutral"},{"value":"credit","label":"Credit (pay later)","tone":"warn"},
      {"value":"gift_card","label":"Gift card","tone":"info","tender":true}],
    "movement_type":[{"value":"Purchase","label":"Purchase","tone":"ok","direction":"in"},{"value":"Sale","label":"Sale","tone":"info","direction":"out"},
      {"value":"Damage","label":"Damage","tone":"bad","direction":"out"}],
    "stock_state":[{"value":"in_stock","label":"In stock","tone":"ok"},{"value":"low","label":"Running low","tone":"warn"},
      {"value":"out","label":"Out of stock","tone":"bad"},{"value":"oversold","label":"Oversold","tone":"bad"}],
    "shift_status":[{"value":"open","label":"Open","tone":"info"},{"value":"closed","label":"Closed","tone":"neutral"}],
    "batch_state":[{"value":"expired","label":"Expired","tone":"bad"},{"value":"soon","label":"Expires soon","tone":"warn"},{"value":"ok","label":"Fine","tone":"ok"}],
    "role":[{"value":"owner","label":"Owner","tone":"neutral"},{"value":"cashier","label":"Cashier","tone":"neutral"}]},
  "app":{"min_version":"2.0.0","latest_version":"2.0.0","download_url":"https://play.google.com/store/apps/details?id=com.budget.dynamics"},
  "web_url":"https://budget-dynamics.schooldynamics.ug",
  "web_pages":[{"key":"promotions.index","label":"Promotions","section":null,"path":"/promotions"},
    {"key":"stock.locations","label":"Transfers & locations","section":"Shop","path":"/stock/locations"}],
  "features":{"store":{"mode":false,"features":{"promotions":false,"…":false},"settings":{"cash_rounding":0,"…":0}},"modules":["shop","finance"]},
  "server_time":"2026-10-16T16:19:20+00:00"}}
```
- `payment_methods`: the shop's own choice in its configured order (every method when it chose none). `pay_later` marks credit.
- `enums.*` rows may carry extras: `direction` (movement_type), `tender` (payment_method rows that are not money in).
- `plan.badge_kind`: `suspended | read_only | expired | trial | free | ending | renew` (or null: nothing to say).
  `urgent` = tone `bad`, or 3 days or fewer left and not renewing by itself. `days_left` is null when the plan has no end.
- `web_pages`: the web portal's menu for this person (same gates as the web: permission, module, feature, edition),
  for "Open on the web" with §9b.7. `key` is the web route name.
- Money: write amounts as `<code> <number>` with `decimals` places, `,` thousands (exactly `App\Support\Money::format`).

### 9b.2 GET `dashboard/suggestions?limit=6` (every member; limit 1–20)
"Worth a look" for this person, most urgent first (bad, warn, info). Nothing to show: one item `all_clear`.
```json
{"code":1,"message":"Suggestions.","data":{"all_clear":false,"as_of":"2026-10-16T16:19:20+00:00","items":[
  {"key":"out_of_stock","tone":"bad","title":"3 products are out of stock","detail":"Customers asking for them go away empty-handed. Reorder or receive stock.",
   "route":"products.out_of_stock","action_label":"See products","count":3,"web_path":null},
  {"key":"credit_overdue","tone":"warn","title":"1 customer is late paying","detail":"UGX 4,000 is past its payment terms. Send a reminder or collect it.",
   "route":"debts.overdue","action_label":"See debtors","count":1,"web_path":null}]}}
```
| key | tone | route | who sees it | from |
|---|---|---|---|---|
| `out_of_stock` | bad | `products.out_of_stock` | restock, manage_products or view_reports | DashboardService::stock (the member's store) |
| `low_stock` | warn | `products.low_stock` | same | at or below `min_stock` / the shop default |
| `credit_overdue` | warn | `debts.overdue` | sell or view_reports | open credit sales past `due_date` or sale day + terms (customer's, else the shop's, else 30) |
| `shift_open` | warn | `shifts` | sell (own tills) or view_reports (all) | open shifts opened before today (shop's day) |
| `expiring` | warn | `stock.expiring` | adjust or restock | batches on hand expiring within `short_dated_days` (default 14) |
| `receipts_unsent` | info | `sales.receipts_unsent` | sell, when the plan sends receipts | today's sales with a phone, no receipt sent, customer not opted out |
| `devices_unsynced` | warn | `devices` | manage_team | active phones not seen for 3+ days (seen within 30) |
| `supplier_bills_due` | warn | `suppliers.due` | restock, view_reports or manage_finance | what we owe less deliveries still inside the supplier's terms |
| `owner_<notice>` | warn | `web` | the owner of a standalone copy | OwnerNotices (`licence`, `address`, `updates`, `scheduler`, `backup`, `connections`); open `web_path` with §9b.7 |
| `all_clear` | ok | `home` | everyone, when the list is empty | `action_label` is null |

`count` is how many products, customers, tills, batches, receipts, phones or suppliers the item is about. `web_path` is
null except on `route: "web"` items, where it is the web page to open. An app that does not know a `route` shows the item without a button.

### 9b.3 GET `customers/{id or uuid}/summary?from=Y-m-d` (every member)
The customer record. `from` (default 90 days back) starts the statement; what came before it is `brought_forward`.
```json
{"code":1,"message":"Customer summary.","data":{
  "customer":{"id":2059,"uuid":"a4ec…","name":"Nakato Sarah","phone":"0772123456","email":null,"address":null,"is_active":true,
    "payment_terms_days":30,"messages_opt_out":false,"last_reminded_at":null},
  "figures":{"owes":6000,"credit_limit":200000,"available":194000,"store_credit":0,"points":null,"points_value":null,"tier":null,
    "overdue":4000,"oldest_due":"2026-10-01"},
  "aging":{"total":6000,"buckets":[{"key":"0_30","label":"0–30 days","amount":2000,"sales":1},
    {"key":"31_60","label":"31–60 days","amount":4000,"sales":1},{"key":"60_plus","label":"Over 60 days","amount":0,"sales":0}]},
  "recent_sales":[{"id":13381,"uuid":"e20e…","receipt_number":"RCP-2026-000002","sale_date":"2026-10-16","created_at":"2026-10-16T16:19:20+00:00",
    "total":3000,"paid":1000,"balance":2000,"status":"Completed","payment_status":"Partial","voided":false}],
  "payments":[{"id":12410,"uuid":"f06e…","amount":2000,"method":"mobile_money","method_label":"Mobile money","reference":null,
    "received_at":"2026-10-16T16:19:20+00:00","sale_id":13380,"receipt_number":"RCP-2026-000001","on_account":false,"is_reversal":false}],
  "statement":{"from":"2026-07-18","to":"2026-10-16","brought_forward":0,"closing_balance":6000,"entries":[
    {"date":"2026-09-01","type":"sale","ref":"RCP-2026-000001","description":"Sale RCP-2026-000001","debit":6000,"credit":0,"balance":6000}]},
  "statement_pdf_url":"https://…/api/v1/customers/a4ec…/statement.pdf?from=2026-07-18"}}
```
- `owes` is net of money held on account; `store_credit` is that money (what the customer may spend). `credit_limit` and
  `available` are null without a limit. `points`, `points_value` and `tier` are null unless the shop has loyalty on.
- `aging` adds up the open sales by days since the sale (it can exceed `owes` when there is credit on account).
- `recent_sales` and `payments`: the latest 20 each, newest first. Payment `amount` is negative for a refund or reversal.
- Statement entries: `type` = sale | payment | return | refund; `balance` is the running balance after the entry.
- `statement_pdf_url`: GET it with the same `Authorization` header (`application/pdf`, inline). Optional `to=Y-m-d`.

### 9b.4 GET `stock-items/{id or uuid}/summary` (every member)
```json
{"code":1,"message":"Product summary.","data":{
  "product":{"id":11308,"uuid":"f165…","name":"Fanta 500ml","sku":"2026-8133-1","barcode":null,"unit":"pcs","current_quantity":18,
    "reorder_level":6,"stock_state":"in_stock","selling_price":1500,"buying_price":900,"track_stock":true,"track_batches":false,"is_active":true},
  "locations":[{"location_id":4471,"name":"Main shop","is_default":true,"quantity":18}],
  "movements":[{"id":49067,"uuid":"7dd2…","type":"Sale","reason":null,"delta":-2,"balance":18,"at":"2026-10-16T16:19:20+00:00",
    "who":"Test Owner","is_reversal":false,"unit_cost":900,"location_id":4471,"document":{"type":"sale","id":13381,"label":"Sale RCP-2026-000002"}}],
  "sales_30d":{"from":"2026-09-17","to":"2026-10-16","quantity":2,"value":3000,"sales":1,"profit":1200},
  "supplier_prices":null,
  "price_history":null,
  "batches":[]}}
```
- `buying_price` and `movements[].unit_cost` are null without view_cost; `sales_30d.profit` null without view_profit.
- `stock_state`: in_stock | low | out | oversold (meta `enums.stock_state`). `movements`: the latest 30, newest first;
  `balance` is the stock after that movement. `document.type`: sale | goods_receipt | sale_return | purchase_return |
  stock_take | stock_transfer | … (`label` is set for sales only).
- `sales_30d`: the shop's last 30 days, net of returns, voids left out.
- `supplier_prices`: null unless `supplier_prices` is on and the person has restock or view_reports; else
  `[{supplier_id, supplier, cost, since, previous, change_pct, source}]`, cheapest first.
- `price_history`: null unless `price_book` is on; else `{applied:[{id, field: selling|buying, old, new, at, reason, who}], scheduled:[{id, field, new, starts_at, reason, who}]}`
  (buying rows only with view_cost).
- `batches` (batch-tracked products): `[{id, batch_number, expiry_date, location, quantity, state: expired|soon|ok|null}]`, soonest first.

### 9b.5 `Idempotency-Key` on writes (every authenticated POST/PUT/PATCH/DELETE)
Send `Idempotency-Key: <1–100 of A-Z a-z 0-9 . _ : ->` on a write; resend the **same key and the same body** when the
first try got no answer. The first final answer is kept 24 hours per (shop, user, key).

| the same key again… | answer |
|---|---|
| same method, path, query and body, first one finished | the stored status and body, header `Idempotent-Replay: true` (nothing runs again) |
| a different request | `422 {"errors":{"code":"idempotency_key_reused"}}` |
| while the first one is still running | `409 {"errors":{"code":"in_progress"}}`: wait, then check or resend |
| a malformed key | `422 {"errors":{"code":"idempotency_key_invalid"}}` |

- Not stored, so a resend with the key runs again: 5xx, 401, 403, 404, 408, 409, 419, 422, 423, 425, 426, 429, and any
  answer that is not JSON (files).
- Ignored (no key handling): `auth/login`, `sync/*`, `poultry/sync/*` (they have their own op ids), bodies over 1 MB
  (uploads), and requests without the header. A "running" entry older than two minutes is treated as dead.
- Tenant, subscription and permission checks run before the key is looked at. Entries are pruned daily (`api:prune-keys`).

### 9b.6 GET `exports/{sales|products|customers}?format=csv|xlsx` (throttled 10/min)
A file (`text/csv; charset=UTF-8` with a BOM, or `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`),
`Content-Disposition: attachment; filename="sales-2026-09-17-to-2026-10-16.csv"`. The columns are the web's exports.

| kind | permission (any of) | columns | filters |
|---|---|---|---|
| sales | view_reports | Date, Time, Receipt, Customer, Phone, Total, Paid, Still owed, Status, Paid by, Sold by | `from`, `to` (default the last 30 days), `status` (Completed, Partially Refunded, Refunded, Voided), `scope` (unpaid, paid), `sold_by` (user id), `customer_id` |
| products | manage_products, view_reports | Name, SKU, Barcode, Category, Sub-category, Unit, In stock, Reorder level, Selling price, [Buying price, Stock value at cost], Stock value at selling price, Active | `q`, `category_id`, `scope` (active default, all, inactive, low, out) |
| customers | sell, view_reports | Name, Phone, Balance, Credit limit, Last sale | `q`, `scope` (all = active, owes, inactive) |

Cost columns only with view_cost. A member limited to one store exports only that store's sales. CSV cells that start
with `= + - @` are quoted with `'`. Errors are JSON: `403 forbidden`, `404` unknown kind, `422` bad filter.

### 9b.7 POST `web-link {path}` (every active member; outside the subscription gate; throttled 10/min)
```json
// POST /api/v1/web-link {"path":"/promotions"}
{"code":1,"message":"Web link ready. It works once, for two minutes.","data":{
  "url":"https://budget-dynamics.schooldynamics.ug/web-link?token=eyJ1Ijo…","expires_at":"2026-10-16T16:21:20+00:00","path":"/promotions"}}
```
Open `url` in the browser straight away: the web portal signs the person in (signing out anyone else on that browser)
and lands on `path`. It works once, within two minutes; a used, expired or tampered link lands on the sign-in page with
the reason. The sign-in page's refusals apply (switched-off account, removed member, no shop).
- `path`: a page of the web portal, starting with `/` (use `meta → web_pages[].path`, with a query if needed). No
  scheme, host, `//`, backslash or spaces; never `/login`, `/logout` or `/web-link`: `422 {"errors":{"code":"invalid_path"}}`.
- `503 {"errors":{"code":"web_link_unavailable"}}`: the server has no web portal address (`SAAS_NEW_UI_URL`).
- Server set-up: both apps need the same `SAAS_DATA_KEY` (the link is signed with it) and `SAAS_NEW_UI_URL` in the kernel.

## 10. Files for server maintainers
- `app/Services/Sync/SyncRegistry.php`: the tables, including `KIND_SNAPSHOT`, schema guards, and the product and customer store fields and rules.
- `app/Services/Sync/SyncPuller.php`: snapshots, `pullMany`, keyset bootstrap, history window, `loyalty_points`.
- `app/Services/Sync/SyncApplier.php`: the new sale fields, tender payments, cash movements, loyalty earn.
- `app/Http/Controllers/Api/V1/{StoreFeaturesController, DebtController, SupermarketController, ChatController}.php`, `app/Services/Chat/ChatService.php`
- `app/Http/Controllers/Api/V1/{StockRequestController, GoodsReceiptController, StockTakeController, TeamController, CompanyController, DashboardController}.php` (§9)
- `app/Http/Middleware/ApiPermissionMap.php`: a rule with a null permission is checked in the controller (`team/members/{id}/pin`).
- `tests/Feature/Api/{SyncSupermarketTest, StoreFeaturesApiTest, DebtsApiTest, SupermarketApiTest, ChatApiTest, StockRequestsApiTest, PhoneParityAdditionsTest}.php`
- §9b: `app/Http/Controllers/Api/V1/{MetaController, SummaryController, ExportController, WebLinkController}.php`, `DashboardController::suggestions`;
  `app/Support/{MetaEnums, ShopMenu, WebLink}.php`; `app/Services/Billing/PlanBadge.php` (also the web topbar chip);
  `app/Services/Dashboard/Suggestions.php`; `app/Services/Shop/{CustomerSummary, ProductSummary}.php`; `app/Services/Reports/ListExports.php`;
  `app/Http/Middleware/IdempotentRequests.php` (`api.idempotent`, last in every authenticated group of `routes/api.php`);
  `app/Console/Commands/PruneApiKeys.php` (`api:prune-keys`, daily 03:20, both editions); tables `api_idempotency_keys`, `web_link_tokens`;
  `resources/views/reports/customer-statement.blade.php`. Web portal: `app/Http/Controllers/Auth/WebLinkSignInController.php` (`GET /web-link`),
  `App\Shop\Navigation::sections()` reads `ShopMenu`.
- `tests/Feature/Api/{PhoneMetaTest, PhoneSummariesTest, IdempotencyKeyTest, PhoneExportsTest}.php`; web portal `tests/Feature/WebLinkSignInTest.php`.
