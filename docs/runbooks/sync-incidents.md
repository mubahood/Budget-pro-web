# Runbook — sync incidents

**Symptoms:** phones show "N waiting" for hours, "need attention" cards, owners report missing sales.

1. **System health** (web, platform admin → System health): check *Sync (24 h)* batches by status, open
   conflicts, phones silent over 3 days and the largest pull lag. Check the scheduler heartbeat and queue.
2. **Rejected batches:** `sync_batches` rows with `status = rejected` hold the per-op result
   (`result.ops[].code`). Common codes: `forbidden` (the member's role lacks the permission — fix the role,
   the phone retries), `missing_parent` (retried automatically after the parent arrives), `validation`.
3. **Held batches** (`status = held`): the shop's plan lapsed beyond grace. They apply on renewal
   (`SubscriptionFulfillment` → `SyncApplier::applyHeld`) or when the shop falls back to the Free plan.
4. **A phone never syncs:** `devices.last_seen_at` old → ask the user to open the app online. Revoked
   devices get `device_revoked`; re-authorise under Devices.
5. **Numbers look wrong on a phone:** server is the truth for derived values. Ask the user to pull
   (Sync screen → Sync now); for a stuck cache use "Re-download everything" (bootstrap).
6. **Soak test before risky deploys:** `php artisan sync:soak --devices=5 --ops=500` against staging.

Escalate with the `X-Request-Id` from the phone's error and the `error_events` fingerprint.
