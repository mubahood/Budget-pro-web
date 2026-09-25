# 0002 — Stock is event-sourced; quantities are caches

**Context.** Stock counters edited in place drifted and oversold under concurrency.

**Decision.** Every change is an append-only `stock_records` row with a signed `quantity_delta`
(`StockService::record`). `stock_items.current_quantity`, per-location `stock_levels` and batch quantities
are caches maintained in the same transaction under row locks. Corrections are reversal rows.

**Consequences.** Property tests assert Σ movements = cache, Σ location levels = product total and batches ≤
level for random sequences. Reports read movements, not counters.
