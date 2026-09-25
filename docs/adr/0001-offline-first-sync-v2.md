# 0001 — One offline-first sync engine with server sequence cursors

**Context.** Shops sell without internet. Two sync engines existed (shop and poultry) with client-clock
cursors that lost rows when phone clocks were wrong.

**Decision.** One protocol (plan Appendix A): every syncable row carries `uuid`, `server_seq`, `version`,
`is_deleted`. Phones write locally and to an outbox in one transaction, push batches applied in one
server transaction, and pull by the server-assigned `server_seq` (never by timestamps). Conflicts follow the
matrix in Appendix E; completed offline sales are never rejected.

**Consequences.** Every new syncable table joins `SyncRegistry` and the phone's `SyncTables`; tests cover
replay, conflicts, soak (`sync:soak`) and convergence between devices. Pull cursors survive clock skew.
