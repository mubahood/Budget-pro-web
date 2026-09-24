# Budget Pro API v1 — Reference

Base URL: `{your-domain}/api/v1`
Auth: **Laravel Sanctum bearer tokens** — `Authorization: Bearer <token>`
Content type: `application/json`

> This document reflects the **implemented** API. All endpoints are versioned under
> `/api/v1`, tenant-scoped, and return a single consistent envelope.

---

## Response envelope

Every response uses this shape:

```json
{
  "code": 1,                 // 1 = success, 0 = error
  "message": "Human readable message",
  "data": { },               // object | array | null
  "meta": { },               // present on paginated lists
  "errors": { }              // present on validation errors (422)
}
```

HTTP status codes are meaningful:

| Status | Meaning |
|---|---|
| 200 | OK |
| 201 | Created |
| 401 | Unauthenticated (missing/invalid token) |
| 402 | Subscription expired / payment required |
| 403 | Forbidden (e.g. inactive company, not the owner) |
| 404 | Not found (also returned for records outside your tenant) |
| 422 | Validation error (see `errors`) |
| 429 | Rate limited |
| 500 | Server error (details logged, never leaked) |

---

## Authentication

### Register (creates company + owner + 14-day trial)
`POST /api/v1/auth/register`

```json
{
  "first_name": "Ada",
  "last_name": "Founder",
  "email": "ada@example.com",
  "password": "secret123",
  "company_name": "Ada Retail",
  "currency": "UGX"
}
```
→ `201` with `{ token, token_type, user, company }`. The token authenticates all
subsequent requests.

### Login
`POST /api/v1/auth/login` — `{ email, password, device_name? }` → `{ token, user, company }`

### Session
- `GET /api/v1/auth/me` — current user + company + roles + subscription
- `POST /api/v1/auth/logout` — revoke the current token
- `POST /api/v1/auth/logout-all` — revoke all tokens
- `PUT /api/v1/auth/password` — `{ current_password, new_password, new_password_confirmation }`

`register`/`login` are rate-limited (10/min per IP).

---

## Company profile
- `GET /api/v1/company` — your company
- `PUT /api/v1/company` — update profile/settings (owner only). Editable: `name`,
  `email`, `phone_number`, `phone_number_2`, `address`, `website`, `about`, `slogan`,
  `logo`, `currency`, and the five `settings_worker_can_*` flags.

## Dashboard
- `GET /api/v1/dashboard` — inventory, sales (this month), finance, and budget summaries.

## Uploads
- `POST /api/v1/uploads` — multipart `file`. Validated (max 5 MB; jpg/png/gif/webp/pdf).
  Returns `{ file_name, url }`.

---

## Resource endpoints (standard CRUD)

Every resource below supports the same verb set:

| Verb | Path | Action |
|---|---|---|
| GET | `/{resource}` | List (paginated) |
| GET | `/{resource}/{id}` | Get one |
| POST | `/{resource}` | Create |
| PUT / PATCH | `/{resource}/{id}` | Update |
| DELETE | `/{resource}/{id}` | Delete |
| GET | `/{resource}/options` | Dropdown options `[{id, text}]` (supports `?q=`) |
| GET | `/{resource}/search` | Typeahead / live search (supports `?q=`) |

### Resources
- **Inventory:** `stock-categories`, `stock-sub-categories`, `stock-items`, `stock-records`
  - `GET /stock-items/by-barcode/{code}` — POS barcode/SKU lookup
- **Sales:** `sales` (+ `POST /sales/checkout`)
- **Finance:** `financial-categories`, `financial-periods`, `financial-records`
- **Budget:** `budget-programs`, `budget-item-categories`, `budget-items`, `contribution-records`

### List query parameters
| Param | Example | Notes |
|---|---|---|
| `page` | `?page=2` | Page number |
| `per_page` | `?per_page=50` | Default 20, max 100 |
| `q` | `?q=cola` | Full-text-ish search across the resource's searchable columns |
| `sort` | `?sort=-created_at,name` | `-` = descending; only allow-listed columns |
| `filter[col]` | `?filter[status]=Active` | Exact match on allow-listed columns |
| `filter[col][op]` | `?filter[amount][gte]=1000` | Operators: `gte, lte, gt, lt, ne, like, in` |

List responses include `meta`:
```json
"meta": { "current_page": 1, "per_page": 20, "total": 42, "last_page": 3,
          "from": 1, "to": 20, "has_more": true }
```

### Sale checkout (POS)
`POST /api/v1/sales/checkout`
```json
{
  "customer_name": "John",
  "payment_method": "Cash",
  "amount_paid": 6000,
  "items": [
    { "stock_item_id": 12, "quantity": 3, "unit_price": 2000 }
  ]
}
```
Creates the sale, records stock movements (deducting inventory), computes profit and
totals, and generates a receipt number. Rejects (422) if stock is insufficient or no
active financial period exists.

---

## Multi-tenancy & security notes
- Every request is scoped to the authenticated user's company. Records from other
  companies return `404` (never leaked).
- `company_id` and `created_by_id` are always set from the token — client-supplied
  values are ignored (no mass assignment).
- Computed fields (rollups, profit, balances, receipt numbers) are set by the server,
  not the client.
- Contribution records cannot be deleted (audit trail); stock records are immutable.

---

## Subscriptions & billing (Flutterwave)

New sign-ups start a 14-day trial. When a subscription/licence lapses, **product
endpoints return `402`** with `{ reason: "subscription_expired" }`; `auth/*`,
`company`, and all `subscription/*` endpoints remain reachable so the user can pay.

**Billing region:** companies whose currency is `UGX` are billed in **UGX** and
offered **mobile money, card, bank transfer, USSD**. All other companies are billed
in **USD by card**.

| Verb | Path | Auth | Notes |
|---|---|---|---|
| GET | `/api/v1/plans` | public | Purchasable plans with `price_usd` + `price_ugx` |
| GET | `/api/v1/subscription` | token | Current subscription + recent invoices |
| POST | `/api/v1/subscription/checkout` | token | `{ plan_id }` → `{ payment_link, tx_ref, amount, currency }` |
| POST | `/api/v1/subscription/verify` | token | `{ transaction_id, tx_ref }` → confirms + activates |
| POST | `/api/v1/webhooks/flutterwave` | signature | Flutterwave server callback (verified by `verif-hash`) |

**Flow:** `checkout` returns a Flutterwave hosted-payment `payment_link`; open it in a
browser/WebView. After payment, Flutterwave redirects to `FLW_REDIRECT_URL` and also
calls the webhook. Confirmation happens through **either** the client calling
`subscription/verify` **or** the webhook — both re-verify the transaction server-side
against the exact amount + currency billed, and both are idempotent (a payment is
never applied twice). On success the subscription is set active, the period is
extended by the plan interval, and `companies.license_expire` is kept in sync.

Configure via `.env`: `FLW_SECRET_KEY`, `FLW_PUBLIC_KEY`, `FLW_SECRET_HASH`,
`FLW_BASE_URL`, `FLW_REDIRECT_URL`. Set the webhook URL in the Flutterwave dashboard
to `{your-domain}/api/v1/webhooks/flutterwave` with the same secret hash.

Plan features/limits are exposed on `GET /api/v1/auth/me` under `subscription.plan`.


## Shop: sales, payments, stock movements (Phase 0)

| Method | Path | Notes |
|---|---|---|
| POST | `/sales/checkout` (alias `POST /sales`) | `{ client_uuid?, items:[{stock_item_id, quantity, unit_price?, discount_amount?}], payments?:[{method, amount, reference?}], amount_paid?, discount_amount?, discount_reason?, sale_date?, customer_* }` → 201; same `client_uuid` again → 200 replay. Errors: 422 `insufficient_stock`, `period_closed`, `no_active_period`, `empty_sale` |
| POST | `/sales/{id}/payments` | `{ amount, method?, reference?, client_uuid? }` → posts one ledger Income row per payment |
| POST | `/sales/{id}/void` | `{ reason? }` → contra movements + contra payments; idempotent |
| DELETE | `/sales/{id}` | always 422 `delete_not_allowed` (use void) |
| GET | `/stock-records/types` | `{ inbound:[...], outbound:[...] }` |
| POST | `/stock-records` | `{ stock_item_id, type, quantity, client_uuid?, unit_cost?, selling_price?, date? }` — inbound types add stock |
| POST | `/stock-records/{id}/reverse` | contra movement; PUT/DELETE are 422 `immutable_movement` |

Every 422 business rejection carries `errors.code` (see `BusinessRuleException`).

## Offline sync v2 (Phase 1 — plan Appendix A)

Auth: `auth:sanctum` + tenant. **Not** behind the subscription gate: a lapsed tenant's devices keep
syncing; once the 7-day grace ends pushed batches are `held` and applied automatically on renewal.
Push requires a registered device: send `X-Device-Id: <device_id>`.

### `POST /devices/register`
`{ device_id, name?, platform?, app_version? }` → `{ device_id, number_prefix: "D1", server_time, server_seq, entitlements }`.
Re-registering keeps the prefix. `GET /devices` lists; `POST /devices/{id}/revoke` (owner) blocks pushes with 403 `device_revoked`.

### `POST /sync/push`
```json
{ "device_time": 1758700000000,
  "batches": [ { "batch_uuid": "…", "kind": "sale", "ops": [
    { "op_uuid": "…", "table": "sales", "uuid": "…", "action": "insert",
      "data": { "provisional_number": "RCP-D1-000123", "occurred_at": 1758698990000, "has_payment_ops": 1,
                "items": [ { "product_uuid": "…", "quantity": "3.000", "unit_price": "1500.00" } ] } },
    { "op_uuid": "…", "table": "payments", "uuid": "…", "action": "insert",
      "data": { "sale_uuid": "…", "method": "cash", "amount": "4500.00", "received_at": 1758698995000 } } ] } ] }
```
Per batch: one transaction; any rejected op rolls the batch back. Result per batch:
`{ batch_uuid, status: applied|replayed|rejected|conflict|held, ops:[{op_uuid, status, code?, server_seq?, server_data?, conflict_id?}],
assigned: { sales: { <uuid>: { receipt_number, invoice_number, id, server_seq } } }, derived: { products: { <uuid>: { current_quantity } } }, stock_exceptions: [] }`.

Tables (wire keys, parents first): `categories, sub_categories, financial_periods, financial_categories, products, sales, sale_items,
payments, stock_movements, financial_records, budget_programs, budget_item_categories, budget_items, contribution_records,
farm_types*, production_guide_tasks*, batches, feed_types, customers, daily_records, feed_stock, poultry_sales, expenses, egg_tx,
mortality_events, health_events, vacc_events` (*pull-only).

Rules: events (`sales, payments, stock_movements`) are insert-if-absent by uuid; updates → `immutable_event`; `action: void` on a sale voids it.
Masters: whole-row LWW with version check — a stale `version` colliding with a newer server edit → `conflict` (`stale_version`) + inbox item.
References are sent as `*_uuid`; an unknown parent → `missing_parent` (never a null FK). Offline oversell is **accepted** and flagged
(`stock_exception`, inbox item). Movement types: `purchase_receipt, stock_in, return, adjustment (signed), stock_take (signed), damage,
expired, lost, internal_use, opening, other`.

### `GET /sync/pull?table=products&since_seq=0&limit=500` (or `tables=a,b,c`)
`{ table, rows:[{…, uuid, server_seq, version, is_deleted, *_uuid}], next_seq, has_more, server_time }` — ordered by `server_seq`;
loop while `has_more`. Tombstones arrive with `is_deleted: 1`. Admin/web edits bump `server_seq`, so they flow to devices.

### `POST /sync/bootstrap`
`{ tables?: [...], page?: 1, page_size?: 500 }` → `{ tables: { products: { rows, next_page } }, seq, tables_order }`. Continue incremental pulls from `seq`.

### Conflict inbox
`GET /sync/conflicts?state=open|resolved|all`; `POST /sync/conflicts/{id}/resolve { choice: mine|server|merged|counted|ignore, data?, counted_quantity? }`.
`counted` on a `stock_exception` records one adjustment to the counted quantity.

### `POST /files`
Multipart `{ uuid, purpose: product_image|receipt|avatar|logo|adjustment_photo|document|other, file (jpg/png/webp/pdf ≤ 8 MB) }` → `{ file_uuid, path, url }`. Re-uploading the same uuid returns the stored file (idempotent).

### Entitlements
`GET /auth/me` and device registration return `entitlements: { state: active|grace|expired|inactive, plan, ends_at, grace_until, limits, features, negative_stock_policy, currency, server_time }`.

## POS & inventory (Phase 2)

| Method | Path | Notes |
|---|---|---|
| CRUD | `/units` | `{ name, abbreviation, factor }` — a Crate with factor 24 sells 24 pieces of stock |
| CRUD | `/product-barcodes` | `{ stock_item_id, barcode, unit_id? }`; unique per company; `GET /stock-items/by-barcode/{code}` also matches these and returns `scanned_unit_id` |
| POST | `/sales/checkout` | now also `customer_id`, `shift_id`, `items.*.unit_id`. When `payments[]` is sent, a balance needs `customer_id` (422 `customer_required`) and must fit the credit limit (422 `credit_limit_exceeded`). A named buyer with a phone joins the debt book automatically. Products with `track_stock=false` never move stock |
| POST | `/sales/{id}/returns` | `{ items:[{sale_item_id, quantity, restock?}], reason?, refund_method?, shift_id?, client_uuid? }` — refunds only what was over-paid; status becomes `Partially Refunded` / `Refunded` |
| GET | `/sales/{id}/receipt.txt` · `/sales/{id}/receipt.pdf` | WhatsApp text (golden-file tested) and PDF |
| CRUD | `/customers` | + `GET /customers/{id}/statement?from&to`, `POST /customers/{id}/payments { amount, method?, reference?, client_uuid? }` (settles oldest sales first; surplus = account credit) |
| CRUD | `/suppliers` | + `GET /suppliers/{id}/statement`, `POST /suppliers/{id}/payments` |
| GET/POST | `/shifts`, `/shifts/current`, `/shifts/open { opening_float }`, `/shifts/{id}/close { counted_cash }` | expected cash = float + cash received − cash refunded; `variance` = counted − expected |
| CRUD | `/stock-takes` | + `POST /stock-takes/{id}/counts { counts:[{stock_item_id, counted_quantity}] }`, `POST /stock-takes/{id}/post` (on-hand set to the count) |
| POST/GET | `/goods-receipts` | `{ supplier_id?, invoice_ref?, amount_paid?, items:[{stock_item_id, quantity, unit_cost}] }` — purchase movements, cost follows, unpaid part becomes supplier balance |
| POST | `/stock-records` | adjustments accept `reason` (`damage, expired, lost, theft, internal_use, correction, gift, restock, return, other`) and `image` (a `/files` path) |

Sync wire keys added: `units, customers, suppliers, product_barcodes, shifts (insert = open, update {status: closed} = close), sale_returns, goods_receipts, stock_takes`; poultry customers moved to `poultry_customers`. Account payments are `payments` ops with `customer_uuid` and no `sale_uuid`. `auth/me` → `company.shop` carries receipt header/footer, negative-stock policy, low-stock default, require_shift.

---

_Legacy note: the pre-v1 endpoints (`/api/api/{model}`, `/api/mobile/*`, param-based
`logged_in_user_id` auth) have been removed and replaced by this versioned, token-authed API._
