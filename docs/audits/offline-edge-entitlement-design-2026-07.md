# Offline Edge — Commercial Entitlement & Access Gates (OFFLINE-EDGE-ENTITLEMENT-1)

Status: **implemented locally** — 2026-07. Tenant-level entitlement + access-control
foundation only. No installer, no pairing/bootstrap/sync, no device/branch licensing,
no lease. `EDGE_FEATURE_ENABLED` stays `false`; production deploy deferred.

Builds on `docs/audits/edge-edition-architecture-2026-07.md`.

---

## 1. What shipped
`offline_edge` is now a real, sellable module in the existing plan/module framework
(same machinery as `manufacturing`), with a gated Settings landing page and an
installer-download endpoint that safely reports "not available yet".

**No new billing engine was invented** — commercial ownership flows entirely through the
existing `plans → plan_modules → subscription` system.

## 2. Three independent gates
Access is the AND of three checks that are never collapsed together:

| Gate | Source | Enforced by | Failure response |
|---|---|---|---|
| **Entitlement** — tenant owns `offline_edge` | `plan.hasEnabledModuleKey('offline_edge')` | subscription middleware (`EnsureTenantSubscriptionAccess`) + `OfflineEdgeEntitlementService::assertTenantHasOfflineEdgeAccess()` | existing **module-disabled** page / 403 JSON |
| **Rollout** — feature released | `config('app.edge_feature_enabled')` (env `EDGE_FEATURE_ENABLED`, default false) | `OfflineEdgeEntitlementService::assertFeatureEnabled()` | **403** `EDGE_FEATURE_DISABLED` |
| **Installer** — real artifact exists | `config('app.edge_installer_*')` + file on disk | `OfflineEdgeEntitlementService::installerIsAvailable()` | **503** `EDGE_INSTALLER_NOT_AVAILABLE` |

None of these read request input — entitlement/rollout/installer state come only from
subscription and config.

## 3. Components
```
Module registry     database/seeders/MasterSeeder.php  → offline_edge
                    (key/name/category/description/route_module_keys=['tenant.offline-edge'],
                     sort_order 150). NOT attached to any plan — syncPlanModules disables
                     every unlisted module, so entitlement is granted only by explicit
                     plan-module administration once pricing/rollout is approved.
Config              config/app.php → edge_installer_path / _version / _sha256 /
                    _signature_path (all unset until EDGE-BUILD-PACKAGING-1).
Service             app/Services/Edge/OfflineEdgeEntitlementService.php
                    tenantHasOfflineEdgeAccess / assertTenantHasOfflineEdgeAccess /
                    featureIsEnabled / assertFeatureEnabled / assertSetupAccessAllowed /
                    installerIsAvailable / installerVersion. Reuses
                    TenantSubscriptionAccessService (no plan logic duplicated).
Exception           app/Exceptions/OfflineEdgeAccessException.php — self-renders
                    403 EDGE_FEATURE_DISABLED / 503 EDGE_INSTALLER_NOT_AVAILABLE
                    (JSON or friendly module-disabled shell). Never a 500.
Controller          app/Http/Controllers/Tenant/OfflineEdgeController.php
                    index() (entitled+enabled landing page, read-only branch list) +
                    download() (re-checks all gates; 503 when no real artifact).
Routes              routes/tenant.php (inside tenant.subscription.access + route.permission):
                    GET /settings/offline-edge          tenant.offline-edge.index
                    GET /settings/offline-edge/download tenant.offline-edge.download
                    → module_key derives to tenant.offline-edge → maps to offline_edge module.
Permissions         tenant.offline-edge.index / .download added to TenantProvisioner
                    Owner grant list (standard route-catalog/permission conventions).
Sidebar             resources/views/partials/sidebar.blade.php — a standalone entry shown
                    ONLY when config('app.edge_feature_enabled') AND $hasModule('offline_edge')
                    AND @can('tenant.offline-edge.index'). Hidden by default.
Landing page        resources/views/tenant/offline-edge/index.blade.php — three-gate status
                    strip, how-it-works, disabled download ("not available yet"), read-only
                    eligible-branches list.
```

## 4. Behaviour matrix (verified)
| State | Page (`/settings/offline-edge`) | Download |
|---|---|---|
| Not entitled (flag on) | **403** module-disabled (module middleware, even if user holds the permission) | 403 module-disabled |
| Entitled, rollout OFF | **403** `EDGE_FEATURE_DISABLED` | 403 `EDGE_FEATURE_DISABLED` |
| Entitled, rollout ON, installer absent | **200** (download button disabled) | **503** `EDGE_INSTALLER_NOT_AVAILABLE` |
| Entitled, rollout ON, installer present (future) | 200 | download the signed artifact |
| No permission (entitled+enabled) | 403 permission-denied (`route.permission`) | 403 |

Proven by 20/20 unit/service QA (rollback-controlled) + real HTTP: entitled+enabled page
200 / download 503; not-entitled page 403 module_disabled with the permission still held
(so the **module** gate, not the sidebar or the permission, is what blocks).

## 5. Explicitly NOT in this sprint
No installer built; the Print Agent installer is **never** served as the Edge installer;
no pairing/bootstrap/sync/heartbeat endpoints; no branch activation; no device limits,
license slots, pairing records, signed leases, grace enforcement or device tokens.

**Future pairing (BRANCH-DEVICE-PAIRING-1 + a later lease sprint) must additionally
enforce:** selected branch, an available licensed device/branch slot, active
subscription, pairing-code validity, and device revocation state. **Tenant entitlement is
necessary but not sufficient.**

**Paid add-on billing:** the existing upgrade-request flow is **plan-based only**
(`SubscriptionChangeRequest` targets a `Plan`, not a module/add-on). We deliberately did
not invent module-level purchase requests. A non-entitled owner is directed to the
existing plan-upgrade / contact-sales workflow; add-on billing remains a later commercial
sprint. Module entitlement itself works today through manual plan-module assignment.

## 6. Independence rule (must persist)
```
Module entitlement       = commercial ownership
EDGE_FEATURE_ENABLED     = rollout readiness
Installer availability   = whether a real package can be downloaded
```
All three stay separate. Default: `EDGE_FEATURE_ENABLED=false` in `.env.example`; installer
config unset; `offline_edge` granted to no plan.

## 7. HARDEN-1 (2026-07-29) — closed 4 gaps
1. **Entitlement now flows through the ONE canonical engine.** `OfflineEdgeEntitlementService`
   no longer queries the plan directly; `entitlementCheck()` calls
   `TenantSubscriptionAccessService::check($tenant, 'tenant.offline-edge.index')` and
   `tenantHasOfflineEdgeAccess()` returns true **only** on an explicit `module_enabled`
   verdict (fail-closed: `always_allowed` / `no_module_key` / `unmapped_route_module_key`
   never silently unlock a paid module). This reuses every subscription-status /
   plan-status / module-active rule and **fixes a latent bug** — a lapsed (past_due /
   cancelled / expired) subscription that still carried the module previously passed the
   direct check and now correctly fails (proven in QA). `assertTenantHasOfflineEdgeAccess()`
   throws a **structured** `OfflineEdgeAccessException::notEntitled()` (403 `EDGE_NOT_ENTITLED`)
   instead of a bare `abort(403)`; browser HTTP is still blocked upstream by the
   subscription middleware's module-disabled page.
2. **Existing-tenant permission propagation — REUSED, not reinvented.** `deploy.sh`
   **step 5** already reads every `route_catalogs` row `WHERE route_name LIKE 'tenant.%'`
   (no `is_published` filter), runs `Permission::findOrCreate($name,'tenant')` per active
   tenant, and `Owner->givePermissionTo($allNames)` — then step 6 flushes the Spatie
   cache in master + every tenant table. `tenant.offline-edge.index|download` are in the
   catalog, so this existing deploy-safe mechanism grants them to **every tenant's Owner
   role, idempotently, and to Owner only** (non-Owner roles are untouched). No new command
   was added (reuse-first). **Exact deploy command: `bash deploy.sh`.** Proven locally by
   running the step-5 grant logic twice across all 7 tenants: 21/21 both runs, Owner has
   both perms, exactly 2 permission rows (no dups), Cashier/Manager/Demo/Branch-Manager
   roles did NOT gain them.
   - Answers: (A) `TenantProvisioner` does **not** re-run for existing tenants on deploy —
     step 5 is the propagation path. (B) `system:routes-sync` writes only `route_catalogs`,
     not tenant Permission rows. (C) Yes — step 5 grants new route permissions to existing
     Owner roles. (D) No — `is_published=0` does **not** block step 5 (it doesn't filter on
     it). (E) Every prior new permission propagated this same way.
3. **RouteCatalog `is_published=0` — intentional, left unchanged.** Default is `false` for
   every newly-synced route. It does **not** affect: subscription-middleware module
   resolution (reads `route_catalogs.module_key` directly), `route.permission` (checks the
   Spatie assignment), or the deploy step-5 Owner grant. It **only** gates
   `PermissionSyncService::syncTenantPermissions()`, which the **tenant Role-editor UI**
   (`RoleController`) uses to list *assignable* permissions for custom roles. Keeping it
   `0` while the feature is pre-rollout is correct: Owner still has the perms and the gates
   all work, but the permission isn't offered in the role editor for arbitrary custom roles
   until a deliberate central-admin publish at rollout. Not blindly changed.
4. **Installer availability hardened.** `installerIsAvailable()` now rejects: unset/empty/
   whitespace path, missing path, a **directory**, a **zero-byte** file, and an
   **unreadable** file — path from config only, never request input. This is an
   existence/readability check, **NOT** cryptographic verification; `EDGE_INSTALLER_SHA256`
   + `_SIGNATURE_PATH` validation is reserved for `EDGE-BUILD-PACKAGING-1`. `.env.example`
   now carries `EDGE_INSTALLER_PATH/_VERSION/_SHA256/_SIGNATURE_PATH` (all empty → 503
   `EDGE_INSTALLER_NOT_AVAILABLE`; empty config resolves to unavailable). Print Agent
   Setup.exe is never a fallback.

HARDEN-1 QA: entitlement/installer service 19/19 (incl. lapsed-subscription block +
6-way installer probe); permission propagation 21/21 ×2 (idempotent); real-HTTP 6-scenario
matrix (not-entitled 403 module_disabled / flag-off 403 EDGE_FEATURE_DISABLED / entitled
page 200 + download 503 EDGE_INSTALLER_NOT_AVAILABLE / non-Owner 403 permission-denied /
download-bypass gated / POS 200); 7/7 tenants tb=0/neg=0/dept=0, manufacturing regression
green, no plan auto-entitled, `EDGE_FEATURE_ENABLED=false`.

## 8. Next
`BRANCH-DEVICE-PAIRING-1` (entitlement + branch/device limits + pairing code → device).

## 9. Follow-on — BRANCH-DEVICE-PAIRING-1 (2026-07)
Pairing is built on this entitlement foundation: three new Owner permissions
(`tenant.offline-edge.pairing-code.generate|cancel`, `tenant.offline-edge.devices.revoke`)
propagate via the same deploy.sh step-5 path. Generation/cancel reuse
`assertSetupAccessAllowed()` (entitlement + rollout); the public exchange re-checks
`featureIsEnabled()` + `tenantHasOfflineEdgeAccess()` at exchange time; **revocation is
deliberately outside the subscription/module gate** so it works after entitlement is removed
or the flag is off. Full design: `docs/audits/branch-device-pairing-design-2026-07.md`.

## 10. Follow-on — BRANCH-DEVICE-PAIRING-HARDEN-1 (2026-07-30)
Security actions are now reachable independent of entitlement/rollout: a permission-only
**`/settings/offline-edge/security`** page (perm `tenant.offline-edge.security`) plus the
cancel + revoke routes sit OUTSIDE the subscription/module gate, so an Owner can always
revoke a device / cancel a code after `offline_edge` is removed, the subscription lapses, or
`EDGE_FEATURE_ENABLED` is off. Generation + download stay entitlement+rollout gated. The
one-time pairing code is stored encrypted in session flash (never plaintext). Detail:
`docs/audits/branch-device-pairing-design-2026-07.md` §13.
