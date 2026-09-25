# 0003 — Money reaches the ledger per payment

**Context.** Credit sales were booked as income on the day of sale, so the books never matched the cash.

**Decision.** Income is posted when a `payments` row is recorded (sale, debt-book, refund as reversal);
supplier payments and cash refunds from suppliers are ledger rows with `source_type`/`source_id`.

**Consequences.** Cash-basis reports agree with the till and mobile money statements; "still owed" is always
the unpaid balance, never income.
