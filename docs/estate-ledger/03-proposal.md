# 03. Estate Ledger: proposal

## 1. Who it is for

| Who | What they do every month | What they need |
|---|---|---|
| **Letting agency** (2–30 staff, 50–2,000 units for many landlords) | raise bills, collect rent at offices, gates and by mobile money, chase arrears, pay each landlord the rent less commission, send each landlord a statement, handle repairs, move tenants in and out | one list of who owes what; exact commission; a landlord statement that is right the first time; caretakers who can collect in the field |
| **Landlord who manages their own property** (1–100 units) | collect rent, keep receipts, know who is behind, pay for repairs | a rent book on the phone that adds up, receipts on WhatsApp, reminders that are one tap |
| **Caretaker** (one or two buildings) | collect at the gate, report repairs, read water meters | a phone screen that works without network and prints or shares a receipt |
| **Tenant** | pay, keep receipts, report a problem | their balance, "paid up to", receipts, how to pay, a way to report a repair |
| **Landlord of an agency** | wait for the payout and the statement | their statement, payouts and occupancy without phoning the agency |

## 2. The problems it solves

1. **Books that do not add up.** Balances typed or recomputed by hooks drift; an edited payment rewrites a landlord's
   past. Estate Ledger posts every shilling once, as balanced rows, and never edits them (ADR 0010).
2. **Commission arguments.** Part payments, fees and deposits make commission unclear. Estate Ledger computes it per
   charge, proportionally, and shows it on every statement line.
3. **Arrears found too late.** Estate Ledger ages every overdue bill (0–30, 31–60, 61–90, 90+), keeps a follow-up log with
   promise dates and makes each reminder a tap.
4. **Deposits that disappear.** A deposit is the tenant's money until move-out, where it settles what is owed and the
   rest is refunded, with a printed settlement.
5. **Paper in the field.** The caretaker's phone records payments, meter readings and repairs offline.
6. **The phone calls.** Tenants and landlords see their own figures in their own portal and app.

## 3. What Estate Ledger does better than the earlier estate system

(The study is in the private portal repo; see 01-study.md.)

| Earlier system | Estate Ledger |
|---|---|
| One agency per installation, staff only | A SaaS: any number of agencies and landlords, each isolated; tenant and landlord portals |
| Balances stored on rows and updated by model hooks; float money | Balances are sums of balanced journal rows; integer minor units |
| Posted payments edited or deleted | Reversals only, with a reason and an audit trail |
| A renting holds a lump sum for several months | Bills per period, raised ahead; partial payments and credit handled per bill |
| Commission on each payment record | Commission per bill, exact on full payment, proportional on part payment |
| Landlord report assembled from stored figures | Statement for any period, reproducible and the same every time |
| No arrears ageing, reminders, deposits settlement, maintenance or meters | All of them |
| Desktop admin only | Web portal plus a phone app that works offline |

## 4. Scope of the first release

Everything in 04-architecture.md: setup (agency or owner), landlords, properties, units, tenants, leases (move in,
notice, move out with deposit settlement, rent changes, fees), bills raised ahead, one-off charges, waivers, late fees
(optional), payments with receipts (PDF and text), credit, reversals, arrears with follow-ups and reminders, landlord
statements and payouts, expenses, cash book and transfers, maintenance with photos, meter readings, reports with CSV,
XLSX and PDF, tenant and landlord portals, import from another system, a full demo, the site, and the phone app
(staff, tenant and landlord).

## 5. Out of the first release

- Online rent payment by mobile money (collections are recorded, not initiated). The money channel exists in the
  kernel for shops and can be added per account later.
- E-signature of leases (the lease summary prints for signing; a signed copy is uploaded).
- Property listings and tenant screening.
- Automatic SMS from the server (links only, KR-18) until platform messaging is configured.
- Accounting exports to other packages.

## 6. Plans

On their own line (`estate-ledger`), counted in units; see 04 §15. Prices are the owner's decision.
