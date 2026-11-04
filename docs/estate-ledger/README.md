# Estate Ledger: Rent & Tenants

**Estate Ledger** ("Estate Ledger – Rent & Tenants") is a property and tenant management app for letting agencies and landlords:
properties, units, tenants, leases, rent bills, payments and receipts, arrears, deposits, landlords' commission and
payouts, expenses, maintenance and meter readings, with a tenant and landlord portal.

| Part | What it is |
|---|---|
| Kernel module | `rentals`, in this repo (`App\Services\Rentals`, `App\Models\Rental*`, `routes/api_rentals.php`) |
| Portal screens | `/rentals` for staff and `/my` for tenants and landlords, in `budget-pro-new` |
| Flutter flavor | `estate-ledger`, package `com.solavia.estateledger`, in `budget-pro-mobo` (`lib/rentals/`) |
| Site | `estate-ledger.solaviatech.com` (`config/apps.php` entry `estate-ledger` in `budget-pro-new`) |

The phone app keeps its unfinished `keyroll` flavor until its own phase.

There is no new repo and no second backend. Estate Ledger stands alone: nothing an Estate Ledger user sees names another app.

This is a public repo. These documents hold no secrets and no real personal data.

| File | What it is |
|---|---|
| [01-study.md](01-study.md) | A pointer: the study of the earlier estate system is kept in the private portal repo |
| [03-proposal.md](03-proposal.md) | Who it is for, the problems, the scope, plans and what is out |
| [04-architecture.md](04-architecture.md) | Tables, the money model, services, roles, API, portal, app, jobs, demo, tests |
| [../adr/0010-rentals-ledger.md](../adr/0010-rentals-ledger.md) | ADR: the rentals journal (balanced effect columns), charges and allocations, cash-basis landlord books |
| [05-roadmap.md](05-roadmap.md) | Work packages with checkboxes: where the build stands |
| [decisions.md](decisions.md) | Every choice (`KR-nn`), with why and the alternatives |

**Reading order for an engineer:** 03, then ADR 0010, then 04, then 05. Check decisions.md whenever a choice looks
arbitrary.
