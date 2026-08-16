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

---

_Legacy note: the pre-v1 endpoints (`/api/api/{model}`, `/api/mobile/*`, param-based
`logged_in_user_id` auth) have been removed and replaced by this versioned, token-authed API._
