# 0007 — Offline-created master data is merged, not made unique in the database

**Context.** Part D asks for per-company unique names/phones/SKUs; Appendix E says two phones creating the
same customer offline must both be kept and merged.

**Decision.** Uniqueness is enforced on online creates (validation, 422). The database does not enforce it
for master data phones create offline; `DuplicateService` finds likely twins and merges them (documents,
stock, locations and batches follow; the twin is tombstoned so every phone drops it). Server-assigned
numbers stay unique in the database.

**Consequences.** Sync never fails on a duplicate; owners get one notice and a merge screen.
