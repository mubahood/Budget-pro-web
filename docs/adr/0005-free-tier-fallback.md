# 0005 — Trials and lapsed plans fall back to a Free plan

**Context.** Informal shops convert from use, and the plan says never block a cashier.

**Decision.** After the trial, or after a paid plan's 7-day grace, the subscription moves to the public
Free plan (1 phone, 100 products, 200 sales a month, no WhatsApp automation). Limits refuse new users,
phones and products online with `plan_limit_reached`; sales are never refused.

**Consequences.** `saas:hourly` runs the lifecycle idempotently; reminders are once-only notices; a plan
that lapsed long ago goes straight to Free without catch-up reminders.
