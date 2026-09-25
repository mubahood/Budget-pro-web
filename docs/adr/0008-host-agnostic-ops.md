# 0008 — Host-agnostic operations

**Context.** Production runs on shared hosting (no Redis, no supervisor) and `vendor/` is deployed with git.

**Decision.** Database queue drained by `queue:work --stop-when-empty` from the every-minute scheduler;
scheduled work in `app/Console/Kernel.php`; built-in error tracking and health page instead of new
packages; backups with `mysqldump` credentials via a temporary defaults file; tooling like PHPStan in
`tools/` so `vendor/` never carries development dependencies.

**Consequences.** Moving to a VPS later only adds a supervised worker and optionally Sentry/Horizon.
