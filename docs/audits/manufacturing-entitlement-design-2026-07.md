# MANUFACTURING-ENTITLEMENT-DECISION-1 — Plan Gating Design (2026-07)

> Decision + design document only. **No code was changed.**
> Audited at `e8b7d1d` (= `v0.9.1-pilot`, prod HEAD), branch `feat/14d-2-plan-upgrade-requests`.
> Follow-up to the fail-open finding in `docs/audits/repo-ui-code-gap-audit-2026-07.md`.

---

## 1. Executive summary

Manufacturing (MANUF-1..10 + MFG-FIN A/B/C/D/G) is live for **every tenant on every
plan** because no central `Module` maps its routes. The enforcement engine already
exists and already runs on every request — the module row is simply missing, so the
middleware's deliberate "unmapped = allow" safety valve lets everything through.

**The fix is small and the design is mostly "insert one module row + attach to the
right plans + wrap one sidebar section."** The dangerous part is migration: the
moment the module row exists, every plan WITHOUT it is blocked — so plan
attachment must land in the same seeder run, and existing tenants must be audited
for manufacturing usage first.

Recommended commercial model: **Option C — included in Enterprise (and the
Finance ERP custom plan), paid add-on for lower plans** (add-on infra is Phase 2).

## 2. Current entitlement system (verified in code)

| Layer | Mechanism | File |
|---|---|---|
| A. Module definition | `modules` master table; MasterSeeder seeds 14 modules (`pos`, `catalog`, `inventory`, `stock_count`, `purchasing`, `restaurant`, `kitchen_display`, `kitchen_inventory`, `printing`, `reports`, `sales_controls`, `multi_branch`, `users_roles`, `finance`) — each with `route_module_keys` (route-name prefixes) | `database/seeders/MasterSeeder.php` L196-330 |
| B. Route → module resolution | `system:routes-sync` writes `route_catalogs.module_key` per route via `PermissionSyncService::moduleKey()` (prefix derivation + explicit overrides) | `app/Services/Permissions/PermissionSyncService.php` L34, L107 |
| C. Plan → modules | `plans` seeded with explicit module lists; pivot `plan_modules` (via `Plan::enabledModules`); **enterprise = `Module::where(is_active)->pluck('key')` at seed time** — auto-includes new modules on the next seeder run | MasterSeeder L471-592 |
| D. Tenant → plan | `tenant->subscription->plan`; usable-status checked (`active` etc.) | `TenantSubscriptionAccessService::check()` L55-75 |
| E. Sidebar gating | `$hasModule(key)` closure over the plan's module keys | `sidebar.blade.php` L14 |
| F. **Route-level gating (EXISTS)** | `EnsureTenantSubscriptionAccess` middleware runs `TenantSubscriptionAccessService::check()` on every tenant route → 403 view `tenant.errors.module-disabled` when the plan lacks the module | `app/Http/Middleware/EnsureTenantSubscriptionAccess.php` |
| G. UI vs route level | **Gating is BOTH UI and route level** — no new middleware is needed for this sprint |
| H. Permission layer | `EnsureRoutePermission` (spatie) runs independently; module access and permission are two separate gates in series |

### The precise fail-open (H)

`TenantSubscriptionAccessService::check()`:
- L77-85: route with **no** `module_key` → allowed (`no_module_key`).
- L87-98: `module_key` set but **no Module claims it** → allowed
  (`unmapped_route_module_key`) — a deliberate 14A-2B decision so one missing
  mapping never bricks a tenant.

Manufacturing state today (verified against master DB):
- **78 route_catalog rows** `tenant.manufacturing.*`, **all** with
  `module_key='tenant.manufacturing'` (prefix derivation works!);
- **no Module has `tenant.manufacturing` in `route_module_keys`** → every request
  passes as `unmapped_route_module_key`;
- sidebar Manufacturing section (sidebar L787+) is gated **only by `@canany`
  permissions** — no `$hasModule` wrapper — and Owner holds all perms, so every
  plan sees it.

## 3. Manufacturing route map (78 routes, all currently fail-open)

All under `tenant.manufacturing.*`, catalog `module_key='tenant.manufacturing'`,
permission = route name (Owner granted), **plan/module gate = none (unmapped)**:

| Group | Routes (URI base) | Sidebar link |
|---|---|---|
| Products context | `/manufacturing/products`, `/categories?context=manufacturing` | Y |
| Customers | `/manufacturing/customers` CRUD + `/ajax/manufacturing-customers` | Y |
| BOM | `/manufacturing/bom` CRUD | Y |
| Material requisitions | `/manufacturing/material-requisitions` CRUD | Y |
| Production orders | `/manufacturing/production-orders` CRUD | Y |
| WIP | `/manufacturing/wip` (+ close action, Phase G) | Y |
| Finished goods | `/manufacturing/finished-goods` (+ post/reverse, Phase D) | Y |
| Consumption | `/manufacturing/consumption` CRUD + `post`/`reverse` (MFG-FIN-C) | Y |
| Scrap / Rejections | `/manufacturing/scrap`, `/manufacturing/rejections` | Y |
| Reports | `/manufacturing/reports/*` (MANUF-10) | Y |
| Posting settings | `/manufacturing/posting-settings` show/edit/update (MFG-FIN-A) | Y |

Note: the AJAX route `tenant.ajax.manufacturing-customers` lives under the
`tenant.ajax.*` prefix — implementation must confirm its catalog `module_key` and
add an explicit override if it derives to something else.

## 4. Business options

| Option | Model | Pros | Cons |
|---|---|---|---|
| A | Enterprise only | Simplest; one seeder line | No revenue path for mid-tier restaurants/retailers who want it |
| B | Paid add-on for any plan | Max monetization | Needs tenant-level override schema NOW; billing UI work up front |
| C | **Enterprise included + add-on for lower plans** | Commercial balance; Phase 1 ships with plan-level gating only, add-on infra later | Two-phase work |

### Recommended: **Option C**, phased

Manufacturing is advanced ERP surface (finance posting, WIP, FG costing,
valuation) — it does not belong in starter plans by default, but a
mid-tier manufacturer should be able to buy it without paying for full Enterprise.
**Phase 1 (next sprint): plan-level gating only.** **Phase 2 (separate sprint):
tenant-level add-on override + billing UI** — current schema has **no tenant-level
module override** (access is purely `plan → plan_modules`), so add-ons need a new
`tenant_module_overrides` pivot (tenant_id, module_key, status, expires_at) and an
OR-check in `TenantSubscriptionAccessService::check()` L103.

## 5. Plan matrix (Phase 1)

| Plan (code) | Manufacturing? | Reason / migration behavior |
|---|---|---|
| retail_starter | **No** | Starter tier; blocked at deploy (no known usage — verify §6 audit first) |
| inventory_store | **No** (add-on candidate, Phase 2) | Inventory-heavy but not manufacturing |
| restaurant_starter | **No** | Starter tier |
| restaurant_pro | **No** (add-on candidate, Phase 2) | Pro restaurants rarely need WIP/BOM costing; kitchen_inventory already covers recipes |
| enterprise | **Yes — automatic** | Seeder takes all active modules at seed time; no explicit edit needed, but VERIFY post-seed |
| finance_erp | **Yes — explicit add** | financedemo is the manufacturing+finance showcase; Contact-Sales custom plan |
| standard (legacy) | **Yes — explicit add** | Verified: legacy `demo` tenant runs plan code `standard` (a 7th plan outside the 6-plan public catalog). demo.bingoopos.com is the primary QA/showcase tenant incl. ALL manufacturing/consumption QA — losing access would break demo flows and the 15-case MFG QA script. Attach `manufacturing` to `standard` explicitly |
| Demo tenants | **Keep access** | Via plans: demo (standard, explicit), enterprisedemo (enterprise, auto), financedemo (finance_erp, explicit). retaildemo/inventorydemo/restaurantdemo/restaurantprodemo lose the section — correct, their industries don't demo manufacturing |

## 6. Existing tenant migration policy (do NOT skip)

1. **Usage audit first** (implementation step 0): per tenant, count rows in
   `manufacturing_*` tables + posted docs. Today's expectation: demo tenants have
   seed/QA data; the real client tenant (`mohsin`, retail) should be zero — verify.
2. **No silent removal**: any real tenant with manufacturing rows AND a plan that
   will lose access → either move to a plan that includes it or explicitly attach
   the module before the gating deploy. Grandfathering today = plan attachment
   (there is no per-tenant override until Phase 2).
3. **Single-deploy atomicity**: module row + plan attachments land in the SAME
   MasterSeeder run (deploy.sh step 3b), so there is no window where the module
   exists but no plan has it.
4. Demo tenants: access preserved via §5; nightly `demo:reset-all` re-provisions
   from plans, so plan-level attachment survives resets (per-tenant hacks would not).

## 7. Implementation design (MANUFACTURING-ENTITLEMENT-IMPLEMENT-1)

All in MasterSeeder + sidebar + verification — **no new middleware, no tenant migration**:

1. **MasterSeeder modules array**: add
   `key='manufacturing', name='Manufacturing', category='Operations',
   route_module_keys=['tenant.manufacturing'], sort_order≈75, is_core=false`.
   (Prefix matches all 78 routes; add `tenant.ajax.manufacturing-customers`
   explicitly if its catalog key differs.)
2. **Plans**: `finance_erp` AND `standard` (legacy demo plan) modules lists +=
   `'manufacturing'`; enterprise is automatic (verify after seed). Others
   unchanged. (`standard` may live outside the MasterSeeder 6-plan array — find
   where it is defined/seeded and attach there, or attach via pivot idempotently.)
3. **Sidebar**: wrap the Manufacturing section (L787+) with
   `@if($hasModule('manufacturing'))` … `@endif` (same pattern as restaurant L487).
4. **Route-level**: nothing to build — once the Module row exists,
   `EnsureTenantSubscriptionAccess` enforces automatically and shows the existing
   `module-disabled` view (which already renders an upgrade CTA).
5. **Public pricing site**: feature lists mention manufacturing only for
   enterprise/finance_erp (honest Available-Now wording per site convention).
6. **Deploy**: standard `deploy.sh` (MasterSeeder step 3b reseeds modules/plans
   idempotently — `updateOrCreate` on key; no duplicate-row risk verified in
   seeder pattern). `route:clear` before routes-sync already in script.
7. **QA plan**:
   - retaildemo (no manufacturing): sidebar section GONE + direct URL
     `/manufacturing/bom` → module-disabled 403 view (not 500);
   - enterprisedemo + financedemo: sidebar visible, all manufacturing pages 200,
     consumption posting QA (existing 15-case script) still green;
   - legacy demo per its plan;
   - `mohsin` client tenant unaffected (verify usage audit = 0 rows first);
   - permission layer still enforced after module pass (remove a perm → 403);
   - all-tenant smoke: tb_diff=0, neg_on_disallowed=0, department_negative=0.

## 8. Billing / add-on recommendation (Phase 2, separate sprint)

- New master pivot `tenant_module_overrides` + `check()` OR-branch + central
  admin UI (grant/revoke/expiry) + tenant billing page "Add-ons" section +
  upgrade-request flow reuse (existing plan-upgrade request pattern fits).
- Price positioning suggestion: manufacturing add-on ≈ the gap between the
  tenant's plan and enterprise, so enterprise stays the better deal at scale.

## 9. Risks

| Risk | Mitigation |
|---|---|
| **Deploy instantly blocks a plan that should have access** (biggest risk) | §6 atomic seeder + §5 matrix + usage audit BEFORE deploy |
| Sidebar hidden but routes open | Not possible here — route middleware enforces; sidebar wrap is cosmetic consistency |
| Posting-settings visible without module | Covered — `tenant.manufacturing.posting-settings.*` shares the prefix |
| Route catalog perm exists but module missing → confusing UX | Intended layering: module gate returns branded module-disabled view BEFORE perms |
| Seeder duplicate module rows | Seeder uses key-based updateOrCreate (idempotent — same pattern as 14 existing modules) |
| Demo tenants losing screens | §5: enterprisedemo/financedemo keep via plans; verify legacy demo |
| Nightly demo reset undoing attachment | Plan-level attachment survives (resets re-provision from plans) |
| Billing UI not showing add-on | Phase 2 scope; Phase 1 module-disabled view already carries upgrade CTA |
| Stale route cache hides new module mapping | deploy.sh already does route:clear before routes-sync (0f92391) |

## 10. Ready-made implementation prompt (next sprint)

**MANUFACTURING-ENTITLEMENT-IMPLEMENT-1** — scope:
1. Usage audit query across all tenants (report, no mutation).
2. MasterSeeder: module row + finance_erp list edit (§7.1-2).
3. Sidebar `$hasModule('manufacturing')` wrap.
4. Local QA per §7.7 (rollback-free — module gating is master-DB config, revert =
   remove row + reseed).
5. Explicit non-goals: no tenant_module_overrides, no billing UI, no pricing-page
   redesign beyond honest feature lists, no MFG-FIN E/F/H.
6. Deploy as its own prod prompt after QA; verify retaildemo blocked +
   enterprisedemo/financedemo working + mohsin unaffected.
