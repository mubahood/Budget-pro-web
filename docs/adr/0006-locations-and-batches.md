# 0006 — Stock per location and FEFO batches under every movement

**Context.** Multi-branch shops (Business plan) and pharmacies/agro-vets need to know where stock is and
what expires first.

**Decision.** Every movement has a `location_id` (default "Main shop"); `LocationStock::applied()` updates
`stock_levels` and, for batch-tracked products, takes stock First-Expiry-First-Out and records which batches
moved (`stock_record_batches`), so voids and transfers move exactly the same batches back or on.

**Consequences.** Single-shop tenants see no change. Transfers never change product totals. A delivery that
arrives while a location is below zero first covers the shortfall before filling batches.
