# Akiba Ledger: SACCO App

**Akiba Ledger** ("Akiba Ledger – SACCO App") is the fifth app in the Budget Pro family, for SACCOs and savings groups.

| Part | What it is |
|---|---|
| Kernel module | `sacco`, in this repo |
| Portal screens | `/sacco`, in `budget-pro-new` |
| Flutter flavor | `akibaledger`, package `com.solavia.akibaledger`, in `budget-pro-mobo` |
| Site | `akiba-ledger.solaviatech.com` |

There is no new repo and no second backend.

This is a public repo. These documents hold no secrets and no real personal data.

| File | What it is |
|---|---|
| [01-mobisave-study.md](01-mobisave-study.md) | A pointer: the full MobiSave study is kept in the private portal repo |
| [02-uganda-research.md](02-uganda-research.md) | Law (Tier 4 Act, SI 51/2020, SI 108/2024, Cooperative Societies Act, DPPA), SACCO types, money channels, loans, year-end, the market and pricing |
| [03-proposal.md](03-proposal.md) | Who it is for, the problems, the v1 scope and what is out, and pricing as `Plan` rows (the plan line and `max_members`) |
| [04-architecture.md](04-architecture.md) | Tables, services, posting rules, roles and positions, maker-checker, audit, offline rules, messaging, mobile money, reports, API, portal, app, scheduler, config |
| [../adr/0009-sacco-double-entry-ledger.md](../adr/0009-sacco-double-entry-ledger.md) | ADR: the double-entry ledger, `Amount`, the chart of accounts, posting service, immutability, hash chain, sub-ledgers, periods |
| [05-roadmap.md](05-roadmap.md) | Phases P1 to P6. P1 is item by item, with what each item reuses and its definition of done and gates. |
| [decisions.md](decisions.md) | Every choice (`AK-nn`), with why and the alternatives |

**Reading order for an engineer:** 03, then ADR 0009, then 04, then 05 (start at P1.1). Check decisions.md whenever a
choice looks arbitrary.
