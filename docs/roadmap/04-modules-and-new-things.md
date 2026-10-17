# 04. Modules: perfect what exists, add what users will ask for next

Each module keeps the kernel as the authority:

- services in `app/Services/*`;
- rules in `app/Support/Rules`;
- event-sourced stock;
- a ledger per payment;
- one sync engine.

New things are added only where they serve a real daily job.

---

## 1. Shop (Shelfie and Budget Pro)

**Perfect:**

- **Payment reversal route** for the app (excellence plan E6 needs it), on top of `PaymentService::reverse`, with idempotency and approval rules.
- **Finish moving the remaining lists to `AppListShell`** in the app (C3: reorder, expiring, shelf labels, stock requests, transfers).
- **The money rules on `Money`** (02 §5), with property tests on the till maths.

**New:**

- **Customer statements by WhatsApp,** sent on a schedule for owed balances, with the shop's consent rules. Debt reminders exist; this adds the statement PDF link.
- **Supplier ordering on WhatsApp:** from SmartReorder, send a purchase order to the supplier's WhatsApp as a PDF link, and mark it sent.
- **Daily summary to the owner** on WhatsApp at closing time: sales, profit, cash expected vs counted, debts, low stock. The plan feature `whatsapp_automation` exists; make it reliable, with a preview in Settings.
- **Barcode label printing from the phone** to Bluetooth label printers (ZPL exists on the web).
- **Expiry first:** FEFO batches (ADR 0006) drive a "sell these first" list on the till for short-dated items (ShortDated exists).

## 2. Supermarket tier

**Perfect:**

- **EFRIS:**
  - build T130 (goods registration), T106 and T108 (duplicate recovery);
  - certify on the URA sandbox;
  - keep the manual adapter for shops not on EFRIS.
- **Hardware:**
  - document the Chrome-only WebUSB and Web Serial support on the help pages;
  - decide on the paused desktop print helper (owner decision);
  - test the common Ugandan receipt printers and scales, and list them on the site.

**New:**

- **Multi-branch head office view:** one dashboard across stores with transfers suggested from one store's surplus to another's shortage (StockRequest and transfers exist).
- **Staff performance:** sales per cashier, voids, discounts and shift variances, visible to the owner only.

## 3. Finance

**Perfect:**

- The P&L excludes poultry correctly (done 17 October) and gains a per-module view: shop, poultry, events.
- Exports to Excel and PDF from every report, with the shop's brand.

**New:**

- **Simple tax summary** per month (VAT collected, if registered) for the accountant.
- **Bank and mobile money statement import** (CSV) matched to recorded payments, to catch missing or double records.

## 4. Pledges and budget (Pledge & Budget)

**Perfect:**

- The payment history and committee roles from 02 §6.
- Decimal money.
- The signed print links that already exist.

**New:**

- **Reminders** by WhatsApp or SMS for unpaid pledges, with the amount left. Treasurers choose who and when; consent and opt-out are honoured.
- **Thank-you message** after each payment, with the receipt link.
- **Progress shared** to the committee's WhatsApp group: pledged, paid, still owed, budget spent and balance, and days to the event.
- **Event types beyond weddings:** introduction (kwanjula), graduation, funeral, church or school fundraising, and harambee. Each has its own default budget categories.
- **Event limits per plan** (3, 10, 50 in `FAMILY_PLAN.md`) enforced by `Quotas`, with an honest upgrade message. Today nothing enforces them.
- **After the event:** a closing report (collected, spent, surplus or shortfall) as a PDF for the committee, then the event is archived, read-only.

## 5. Poultry (Kuku Tally)

**Perfect:**

- The v2 sync conflict rule (02 §7).
- The Kuku Tally PDF report (open in `FAMILY_PLAN.md`).
- Feed conversion and lay-rate charts on the web (03 §5).

**New:**

- **Vaccination reminders** from the production guide (tasks exist) as push and WhatsApp to the farmer.
- **Egg price tracking:** the price per tray recorded at each sale gives a trend and suggests a price.
- **Batch profitability at a glance:** cost per bird, cost per tray, and profit per batch to date.
- **Disease log with photos** for the vet, shareable as a link.

## 6. Ping Pin and chat

- **Ping Pin** stays a separate product inside the kernel (`app/PingPin/*`). Keep its isolation and its own plans. Do not mix it into the family's menus.
- **Chat:** keep HTTP polling (it fits shared hosting), with back-off when idle and a "new message" push once FCM exists (05 §3).

## 7. Suggestions engine for every module (P1)

`GET /api/v1/dashboard/suggestions` ("Worth a look") exists for the shop. Extend it so each module adds its own suggestions, with one message format and one link to the screen that acts:

- **Shop:** stock running low, debts overdue, a shift not closed, prices about to change.
- **Poultry:** lay rate dropped below the 7-day average, feed for under 5 days, a vaccine due tomorrow.
- **Pledges:** pledges unpaid 30 days before the event, a budget category over its target.
- **Finance:** expenses above last month's in a category.

Each suggestion has a test, and each is shown the same way on the web and in the app.
