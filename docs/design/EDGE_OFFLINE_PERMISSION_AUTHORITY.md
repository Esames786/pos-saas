# Edge offline permission authority (EDGE-CONFIG-REFRESH-1 security closure)

## The ONE effective authority

On a Branch Server there is exactly **one** effective offline authorization authority:

> **`model_has_permissions` — the per-user, denormalised effective permission set the Cloud
> exports in the bootstrap/refresh `users` section.**

Everything else is identity metadata or inert catalog:

| Table | Role on the appliance |
|---|---|
| `model_has_permissions` | **THE effective authority.** Rebuilt wholesale from the authoritative payload on every initial import and every config refresh, inside the same transaction. `User::can()` resolves from here. |
| `model_has_roles` | Identity/group metadata only (for `hasRole()`, display, grouping). Rebuilt from the payload's per-user role names. Never grants a permission by itself, because… |
| `role_has_permissions` | **Deliberately EMPTY on the appliance.** Cleared by the initial import (`clearExistingConfig`) and cleared again by every config refresh. It must never act as a second, independently-stale authority: a role→permission row surviving a revision could silently re-grant a permission the Cloud revoked. |
| `permissions` | Catalog of granted permission names. A permission row survives as long as **any** user still holds it (per-user revocation is a grant removal, never a row deletion another user needs); rows granted to nobody are pruned. |
| `users.password` / PINs | Never present. Offline credentials live only in `edge_local_user_credentials` (EDGE-LOCAL-AUTH-1, Argon2id, epoch-fenced); a refresh never writes credential material. |

## Why denormalised per-user, not role-resolved

The Cloud resolves `role → permissions` at **export time** and ships each user's *effective* set
(`EdgeBootstrapService::userSection()`). The appliance therefore never needs `role_has_permissions`
to answer `can()`, and the wire contract stays exactly what EDGE-LOCAL-AUTH-1 froze. Revoking a
permission from a role on the Cloud changes the export watermark (role_has_permissions pairs are
hashed into `sourceRevision`), mints a new config revision, and the refresh rewrites every affected
user's grants — all-or-nothing, under the `edge_local_meta` refresh lock.

## Behaviour pinned by regression (EdgeConfigRefreshMySqlTest)

- Revision N: Manager role holds `tenant.pos.store` + `tenant.pos.approve`; user B holds
  `tenant.pos.approve` **directly**. Revision N+1 revokes approve from the Manager role only.
- After refresh: Manager `can('tenant.pos.approve')` is **false** — even with a stale local
  `role_has_permissions` row seeded as a hazard; user B keeps the grant; the permission **row**
  survives (B still needs it); `hasRole('Manager')` still true; unrelated permissions survive.
- A tombstoned (`status = inactive`) user fails `EdgeUserAuthz::isActive()` — the auth gate refuses
  them before any `can()` is consulted.
- Spatie's permission cache is flushed after a successful refresh so revocation takes effect
  immediately in the running process.
- Permission-graph mutation happens in the SAME refresh transaction: a late failure rolls back
  users, roles, both model_* graphs, role_has_permissions, the permission catalog, and
  `last_applied_config_revision` together.
