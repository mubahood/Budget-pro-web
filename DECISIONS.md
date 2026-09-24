# DECISIONS.md — Budget Pro (backend + mobile)

Architecture/product decisions, ADR-style. Newest at the bottom. Format: **Dn. Decision.** Because… /
Consequences. Seeded from `SHOP_ONBOARDING_OFFLINE_MASTER_PLAN.md` Appendix G (G1–G12) and the answers to
its Appendix H open questions (H1–H10). Every later decision made while executing the plan is appended here.

## Seed — Appendix G

- **G1. One sync engine, generalised from the poultry module; the model-cache and `SyncEngine`/`OfflineStore` layers are retired.** Because two competing designs guarantee drift; the uuid/event model fits POS; the poultry engine is proven in production.
- **G2. A server-assigned `server_seq` is the pull cursor; client timestamps are used only for last-writer-wins.** Because device clocks are unreliable and the timestamp cursor loses rows between devices.
- **G3. Stock is event-sourced (`stock_movements`); `current_quantity` is a server-maintained cache.** Because counters can't be merged across devices and the poultry module already proves the derived model.
- **G4. Completed offline sales are never rejected at sync.** Because money changed hands; stock exceptions are surfaced to a human, not refused.
- **G5. Ledger income is posted per payment, not per sale.** Because credit sales are common and the ledger must reflect cash reality.
- **G6. Per-company roles replace the global admin roles for tenants; platform admins keep `*`.** Because every role currently has `*`, which lets any tenant user reach billing/user administration.
- **G7. Phone-first identity with OTP; email optional.** Because the market is phone-first and email deliverability is poor.
- **G8. Legacy mobile routes (`ApiController`, `MobileApiController`) stay until the forced-upgrade window; all new work is additive and versioned under `/api/v1`.** Because a shipped app depends on them.
- **G9. WhatsApp is the primary customer/owner channel; SMS is the fallback; email is tertiary.**
- **G10. The EOQ auto-reorder code is removed; reorder is rebuilt as explainable suggestions on `product_stats`.**
- **G11. Money is `DECIMAL(20,2)` and quantity `DECIMAL(15,3)` server-side; integer minor units on the device.**
- **G12. Demo data lives in a local-only tenant (`company_id = 0`, `is_demo = 1`) that can never sync.**

## Appendix H answers (decided 2026-09-24 so execution never stalls)

- **H1. Branding: the product is "Budget Pro" everywhere** (web admin title, `APP_NAME`, emails, receipts, wizard). The Play Store listing keeps "Budget Dynamics" as the *store name* until P3-10, when it is renamed "Budget Pro" with a subtitle. "InvetoTrack"/"Inveto admin" strings are removed. Because one name is cheaper than three and "Budget Pro" is already the domain and backend name.
- **H2. Pricing: 14-day trial at signup, then automatic fall-back to a new public Free tier** (1 device, 100 products, 200 sales/month, no WhatsApp automation, no multi-branch) instead of a lockout; paid tiers unchanged. Because the informal-retail segment converts from use, not from a paywall, and §2 says never block a cashier. Implemented with quota enforcement in Phase 3 (P3-6/P3-7).
- **H3. Countries: Uganda first, Kenya second, then Tanzania/Rwanda.** Drives MoMo providers (MTN/Airtel → M-Pesa), OTP gateway coverage, tax presets (UG VAT 18%, KE VAT 16%), locales (`en`, `sw`, `lg`).
- **H4. Messaging providers: Africa's Talking for SMS/OTP; Meta WhatsApp Cloud API for WhatsApp; both behind a `MessagingChannel` interface with a log/null driver for tests.** Because AT has native UG/KE coverage and Meta is the only first-party WhatsApp route.
- **H5. Grace policy: 7 days.** During grace, devices keep selling offline and syncing; the web becomes read-only (view/export/billing only). After grace: devices read-only (sync of already-recorded sales still accepted into quarantine and applied on renewal, per Appendix E), web locked except billing.
- **H6. Multi-branch (locations/transfers) and price lists ship in Phase 4 behind the Business plan flag.**
- **H7. The budget/fundraising module moves onto the v2 sync engine in Phase 4**, after the shop; its legacy `MobileApiController` path stays untouched until then.
- **H8. Printing: generic ESC/POS Bluetooth printers first, via a `ReceiptPrinter` abstraction; Sunmi/PAX built-in printers as a second driver later.**
- **H9. Hosting: code is made host-agnostic now** (database queue driver; on the current shared host the worker runs as `queue:work --stop-when-empty` from cron every minute; schedule via cron). Migration to a VPS with a supervised worker is triggered when sync traffic or queue latency (> 60 s) demands it, not before.
- **H10. Stock conflicts can be resolved by `owner`, `manager` and `stock_keeper` roles.**

## Execution log (appended as decisions are made while implementing the plan)

- **E1** (2026-09-24, P0-7) Ledger income is posted per payment. A stand-alone `Sale` stock movement with no `SaleRecord` (legacy quick-sale/mobile paths) still posts its own income row (`source_type = stock_record`) so the ledger stays complete until those paths are retired in §B10.
- **E2** (P0-4) Reversing an *inbound* movement (undoing a receipt after the goods were sold) obeys the product's negative-stock policy like any other outbound movement; reversing an outbound movement always succeeds.
- **E3** (P0-8) Receipt/invoice numbers are assigned when a sale is finalised inside the checkout transaction; a rolled-back checkout may leave a gap in the sequence. Gaps are acceptable, duplicates are not (per-company unique index). The columns are nullable so a draft header can exist for a few milliseconds.
- **E4** (P0-9) Report summaries are cash-basis: revenue = ledger income (payments), expenses = ledger expenses + cost of goods sold of non-voided sales.
- **E5** (P0-12) Forecasting/auto-reorder stay in the codebase behind `saas.features.inventory_automation` (platform flag, default off) rather than being deleted; Phase 4 rebuilds them on the new stock ledger.
- **E6** (P0-14) Subscription checkout is allowed for the company owner only; companies with no `owner_id` on record fall back to users holding the Company Owner/admin role so legacy tenants can still pay.
- **E7** (P0-15) `BillingTest::test_lapsed_tenant_can_reach_checkout_but_not_product` now lapses the tenant by 30 days: a 1-day lapse is inside the 7-day grace decided in H5 and is correctly allowed through.
- **E8** (P0-6) Admin "delete" on a sale voids it and on a stock movement is refused with guidance to reverse; nothing in the shop ledger is hard-deleted, and system-posted ledger rows cannot be edited or deleted (`ledger_locked`).
- **E9** (P0-11) Low-stock threshold: `stock_items.min_stock` per product, else `saas.low_stock_threshold` (env `SAAS_LOW_STOCK_THRESHOLD`, default 10). Default currency for companies without one: `saas.default_currency` (env, default UGX) — never a literal in controllers or views.
