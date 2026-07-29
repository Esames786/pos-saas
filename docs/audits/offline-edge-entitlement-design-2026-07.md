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

## 7. Next
`BRANCH-DEVICE-PAIRING-1` (entitlement + branch/device limits + pairing code → device).
