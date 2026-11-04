# 0010 — The rentals module (Estate Ledger) keeps a balanced journal beside its charges

**Context.** A letting agency holds other people's money. Rent it collects belongs to the landlord, less a commission;
a security deposit belongs to the tenant until the lease ends; money a tenant pays ahead belongs to them until a bill
is due. An earlier estate system stored balances in columns that hooks updated on every save, computed money in
floats, and let a posted payment be edited or deleted, so its landlord reports could not be proven and drifted when a
record changed. Estate Ledger needs books that always add up, that a landlord can trust, and that a phone can post to
offline without counting anything twice.

**Decision.**

1. **Money is integer minor units** (`*_minor BIGINT`) in the account's single currency. No float or decimal money is
   stored. Rates are basis points (`*_bp`, 1000 = 10 %).
2. **What a tenant owes is a sub-ledger of charges and allocations.** A charge (`rental_charges`) is a bill line: rent
   for a period, a fee, a utility reading, a deposit, a late fee, a one-off. A payment (`rental_payments`) is money a
   tenant handed over (or a deposit used, or a waiver). An allocation (`rental_allocations`) says how much of a payment
   settled which charge, and how that part splits: commission, landlord, agency, deposit. A charge's balance is its
   amount minus its allocations; a payment's unallocated part is the tenant's credit.
3. **Every movement of money is a balanced row in one journal** (`rental_entries`), with signed effect columns that
   are the module's fixed chart of accounts:

   | Column | Account | Kind |
   |---|---|---|
   | `cash_minor` (with `method`) | cash, bank, mobile money | asset |
   | `landlord_minor` (with `landlord_id`) | owed to landlords | liability |
   | `deposit_minor` (with `lease_id`) | deposits held for tenants | liability |
   | `credit_minor` (with `lease_id`) | tenants' money paid ahead | liability |
   | `income_minor` | the agency's income (commission, fees it keeps) | equity + |
   | `expense_minor` | the agency's own expenses | equity − |
   | `equity_minor` | opening balances | equity |

   Every posting (the rows posted together, one `group_id`) satisfies **cash = landlord + deposit + credit + income −
   expense + equity**, and so does every row on its own except the two legs of a transfer between cash accounts. The
   posting service refuses anything else, and `rentals:check` proves it for every posting and row, every night.
4. **Landlords' books are on the cash basis.** Billing a charge posts nothing to the journal (a charge is a memo of
   what is owed). Money reaches a landlord when it is collected: rent collected → landlord + (rent − commission),
   income + commission. A payout to a landlord is cash −, landlord −. An expense paid for a landlord's property is
   cash −, landlord −.
5. **Nothing posted is edited or deleted.** A mistake is a reversal: negated allocations and negated journal rows that
   point at the originals (`reverses_id`), with the original marked `reversed_at`. Charges are voided only while
   nothing has been paid on them; a reduction after payment is a waiver (an allocation with no money).
6. **All money writes for an account run under one lock**: `SELECT … FOR UPDATE` on the account's `rental_settings`
   row inside the database transaction (`RentalLedger::locked`). Phone writes carry a `uuid`; the same uuid twice
   is one payment.
7. **Caches are allowed only where the journal or allocations prove them**: `rental_charges.paid_minor`,
   `rental_charges.status`, `rental_payments.unallocated_minor`. `rentals:check` recomputes them.

**Why not ADR 0009's double-entry ledger?** It is built for a SACCO (a chart of accounts with periods, approvals and
regulator lines). A letting agency needs seven fixed accounts, per-landlord and per-lease sub-balances and cash-basis
statements; a fixed set of balanced columns gives the same guarantees (each row balances, sums are the balances,
nothing is edited) with far less machinery, and keeps the two modules independent.

**Consequences.**

- Every balance on every screen is a sum: a landlord's balance is Σ `landlord_minor` for that landlord; deposits held
  are Σ `deposit_minor`; the cash book is Σ `cash_minor` by method; what a tenant owes is Σ charges − Σ allocations.
- A landlord statement for any period is reproducible from the rows, and two statements for the same period always
  agree: its occupancy and arrears are worked out as of the period's last day, and the names on its lines come from the
  payments' receipts. A receipt keeps a snapshot of itself on the payment; a reprint reads that, and only a reversal adds to it.
- Commission on a part payment is proportional and exact once the charge is fully paid (04 §5.3).
- Adding an account means a new column and a new posting rule, with the invariant updated in one place.
