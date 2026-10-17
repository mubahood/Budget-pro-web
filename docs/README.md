# Budget Pro documentation map

What is current, and where it lives. Checked 17 October 2026. If a document is not listed here, treat it as
history. The 2025 set (session reports, Envato plans, v2.0.0 guides, old user pages) is in
[`archive/2025/`](archive/2025/README.md) and no longer matches the code.

Budget Pro is three repos: this one (the **kernel**, Laravel 10: schema, services, `/api/v1`, scheduler, classic
admin), `htdocs/budget-pro-new` (the **portal**, Laravel 12 + Livewire) and `Desktop/github/budget-pro-mobo`
(the **apps**, Flutter, four flavors).

## Start here

| Document | What it is for |
|---|---|
| [`../README.md`](../README.md) | The kernel: setup, tests, commands |
| [`roadmap/`](roadmap/README.md) | **The plan now.** System review (01), stabilise and secure (02), interface (03), modules (04), the four apps (05), selling and WhatsApp (06), delivery plan (07), and the Phase 0 plan |
| [`../SHOP_ONBOARDING_OFFLINE_MASTER_PLAN.md`](../SHOP_ONBOARDING_OFFLINE_MASTER_PLAN.md) | The master plan behind today's shop: roles, offline POS, event-sourced stock, ledgers, numbering. 63 of 63 items built |
| [`../CHANGELOG.md`](../CHANGELOG.md) | What shipped, newest first |
| [`../DECISIONS.md`](../DECISIONS.md) | Running log of smaller decisions and the reasons for them |
| [`adr/`](adr/README.md) | Architecture decision records: offline sync v2, event-sourced stock, ledger per payment, per-company roles, free-tier fallback, locations and batches, duplicates merge, host-agnostic ops |

## The API and the apps

| Document | What it is for |
|---|---|
| [`MOBILE_API_CONTRACT.md`](MOBILE_API_CONTRACT.md) | The contract the phone apps rely on (`/api/v1`, sync, envelopes, errors) |
| [`../API_DOCUMENTATION.md`](../API_DOCUMENTATION.md) | `/api/v1` reference for integrators |
| [`openapi.json`](openapi.json), [`postman/`](postman/) | Machine-readable spec and Postman collection, written by `php artisan api:docs` |
| `budget-pro-mobo/docs/FLAVORS.md` | The four app flavors (Budget Pro, Shelfie, Kuku Tally, Pledge & Budget) |
| `budget-pro-mobo/GOOGLE_PLAY_SUBMISSION.md`, `budget-pro-mobo/CHANGELOG.md` | App release checklist and app release history |

## Editions and operations

| Document | What it is for |
|---|---|
| [`STANDALONE_EDITION_PLAN.md`](STANDALONE_EDITION_PLAN.md) | SaaS and the one-off (standalone) edition. Built 16 October 2026; the status line lists what is still open |
| `../resources/standalone/INSTALL.md` | The buyer's install guide shipped inside the standalone package |
| [`runbooks/backups-and-restore.md`](runbooks/backups-and-restore.md) | Nightly backups, restore drill |
| [`runbooks/sync-incidents.md`](runbooks/sync-incidents.md) | When phones stop syncing |
| `budget-pro-new/docs/runbooks/key-rotation.md` | Rotating keys after a leak (kept in the private portal repo on purpose) |
| `budget-pro-new/docs/deployment.md` | Deploying the portal in lockstep with the kernel |

## The portal (`budget-pro-new`)

| Document | What it is for |
|---|---|
| `budget-pro-new/UPGRADE_PLAN.md`, `docs/POWER_PLAN.md`, `docs/SUPERMARKET_PLAN.md`, `docs/FAMILY_PLAN.md` | Portal plans, ticked against the code |
| `budget-pro-new/docs/journeys.md` | The ten daily journeys, measured on the portal and the app, with targets |
| `budget-pro-new/docs/decisions.md`, `docs/shop-conventions.md` | Portal-specific decisions and conventions |

## For owners and training

| Document | What it is for |
|---|---|
| [`quickstart.md`](quickstart.md) | One-page quick start for shop owners |
| [`VIDEO_SERIES_PLAN.md`](VIDEO_SERIES_PLAN.md) | The A-to-Z training video series |

## Kept, but not a guide

- [`../BACKEND_API_MASTER_TASKS.md`](../BACKEND_API_MASTER_TASKS.md): the August 2026 backlog, **superseded**.
  Kept because code comments cite its task numbers.
- [`agent-command.md`](agent-command.md): the brief given to the coding agent for the roadmap.
- [`../LICENSE.md`](../LICENSE.md): the end-user licence (written December 2025 for the self-hosted copy; due a
  review against the standalone edition's terms, §22 of `STANDALONE_EDITION_PLAN.md`).
