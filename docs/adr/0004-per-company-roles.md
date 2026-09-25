# 0004 — Per-company roles and one permission map

**Context.** Tenant admin roles carried `*`; every staff member could do everything.

**Decision.** Roles (owner, manager, cashier, stock keeper, accountant, viewer) are per company, with
per-company overrides. One table (`ApiPermissionMap`) guards the API, `SyncApplier::permissionFor` guards
pushed ops, `AdminPermission` guards web sections, and the phone caches its permissions from `auth/me`.

**Consequences.** New endpoints add one line to the map; a refused call is always 403 `forbidden` with the
missing permission. Existing staff resolve to manager so nobody lost access on upgrade (E30).
