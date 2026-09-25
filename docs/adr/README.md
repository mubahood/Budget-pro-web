# Architecture decision records

Short records of the decisions that shape the system. The running log of smaller choices made while
implementing the master plan lives in [`DECISIONS.md`](../../DECISIONS.md) (G = design, H = product owner
answers, E = execution); an ADR is written when a decision changes how the whole system works.

| ADR | Decision | Status |
|---|---|---|
| [0001](0001-offline-first-sync-v2.md) | One offline-first sync engine with server sequence cursors | Accepted |
| [0002](0002-event-sourced-stock.md) | Stock is event-sourced; quantities are caches | Accepted |
| [0003](0003-ledger-per-payment.md) | Money reaches the ledger per payment, not per sale | Accepted |
| [0004](0004-per-company-roles.md) | Per-company roles and one permission map for API, sync and web | Accepted |
| [0005](0005-free-tier-fallback.md) | Trials and lapsed plans fall back to a Free plan, never a lockout | Accepted |
| [0006](0006-locations-and-batches.md) | Stock per location and FEFO batches as a layer under every movement | Accepted |
| [0007](0007-duplicates-merge-not-unique.md) | Offline-created master data is merged, not made unique in the database | Accepted |
| [0008](0008-host-agnostic-ops.md) | Host-agnostic operations: database queue, cron scheduler, no vendor churn | Accepted |

Format: context → decision → consequences. Superseding a decision means a new ADR that links the old one.
