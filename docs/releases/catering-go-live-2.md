# Release: Catering & Events go-live candidate 2 (2026-08-14)

Branch `release/catering-go-live-2` = latest shared platform
(`feat/14d-2-plan-upgrade-requests` @ `74cecec`, the production lineage) +
the reviewed Catering delta (`feat/catering-events-v1`). The merge tree was
verified byte-identical to the previously certified `482e208` tree (then one
reviewed fix on top: removal of the route-less `material-issues.show`
permission). All five post-split shared platform commits are patch-id
equivalent across branches and present exactly once in this tree.

## Migration review (§7)

| Migration | Class | Risk |
|---|---|---|
| master `2026_08_13_000001_register_catering_module` | master data (module registration only; no plan attachment) | none — one `modules` row |
| tenant `2026_08_13_100001` foundation (translations, profiles, settings) | additive CREATE | none — new empty tables, named FKs/uniques |
| tenant `2026_08_13_100002` events/estimates/lines | additive CREATE | none |
| tenant `2026_08_13_100003` catering permissions | permission-seeding (+Owner grant, spatie cache clear) | none — `insertGetId` guarded by existence checks |
| tenant `2026_08_13_100004` rate book / cost snapshots | additive CREATE | none |
| tenant `2026_08_13_100005` ops (advances, releases, reminders, email logs, printer mappings) | additive CREATE | none |
| tenant `2026_08_13_100006` final invoices + permissions | additive CREATE + permission-seeding | none |
| tenant `2026_08_14_000001` finance/stock closure | additive COLUMNS (nullable, no backfill) + CREATE (material issues) + permission-seeding | none — `hasColumn` guarded, `after()` placement, no data rewrite |
| tenant `2026_08_14_000002` drop unused permission | permission cleanup (one route-less row) | none |
| seeder `MasterSeeder` | module registry + ROLLOUT GATE (`ROLLOUT_GATED_MODULE_KEYS`) | none — proven not to touch existing PlanModule grants |
| seeder `DefaultChartOfAccountsSeeder` | additive account `4160 Catering Revenue` | none — idempotent, keyed on `code` |

No data-changing/backfill migrations; every new table starts empty (zero
lock risk); every FK/unique/index is explicit and 64-char safe; all `down()`
methods exist but see rollback strategy.

## Rollback strategy (§7)

**Preferred and sufficient: forward-fix / code rollback with additive schema
retained.** All catering schema is additive; a code rollback to the previous
release leaves empty-or-catering-only tables that nothing references from
POS paths. Once ANY catering finance rows exist (journal entries reference
`catering_*` sources, stock ledgers reference material issues), tables are
financially referenced and MUST NOT be dropped — migrate:rollback of the
catering migrations is **prohibited in production**. Entitlement rollback is
instant and non-schema: disable the client's PlanModule row (fail-closed
everywhere).

## Fresh-install / upgrade proofs (§4/§5, executed on this tree)

- Fresh tenant `freshcat` via the REAL `TenantProvisioner` + `TenantOpsService::syncTenant`:
  121/121 tenant migrations in order; all catering + platform tables present;
  37 route-backed catering permissions, all Owner-granted; CoA seeded with
  4160; entitlement gate allows; event smoke-created. Zero manual SQL.
- Existing pre-Catering tenant (snapshot of the real 106-migration
  `pos_tenant_demo`): deploy sequence upgraded it cleanly — products 78→78,
  sales 50→50, lines 136→136, users 3→3, roles 3→3, stock ledgers 167→167,
  journals 47→47; permissions purely additive 527→587 (nothing removed);
  PlanModule assignments byte-identical; catering registered but FAIL-CLOSED.

## Entitlement matrix (§6, proven)

| Plan | Catering after deploy |
|---|---|
| quick_sale / retail_pro / restaurant / restaurant_pro / finance_erp | ✗ (no row) |
| enterprise (all-modules) | ✗ (rollout gate excludes gated keys) |
| `catering-client-1` (private, explicit grant) | ✓ |

Direct URL for non-entitled → 403 module-disabled; sidebar hidden;
permissions unmanaged in the Permission Center.

## First-client grant procedure (rehearsed on cateringdemo)

1. Create/confirm the private plan `catering-client-1` (`is_public=false`)
   with the client's module set **including `catering`** (central Plans UI or
   the rehearsed script) — never edit a public plan.
2. Point the client tenant's subscription at that plan.
3. Run per-tenant sync (`POST /tenants/{tenant}/sync` — migrate + permission
   grant; part of deploy.sh's loop anyway).
4. Verify: catering sidebar visible for the client, 403 for any other tenant.

## Deployment procedure (proposed — NOT executed)

1. Maintenance window not required (additive schema; no long DDL).
2. `git fetch && git checkout release/catering-go-live-2` on the server.
3. Standard `deploy.sh`: composer install → `php artisan migrate --force`
   (master: registers the module) → `db:seed MasterSeeder` (rollout gate:
   plan grants untouched) → `system:routes-sync` + publish → per-tenant loop
   (`syncTenant`: tenant migrations + permission grants) → caches
   (`route:cache`, `config:cache`, `view:cache`).
4. Per-tenant chart update where finance is used: re-run
   `DefaultChartOfAccountsSeeder` (adds 4160 only) — same ops step as MFG-FIN-A.
5. Post-deploy smoke: one non-entitled tenant → catering URL 403; POS sale on
   an existing tenant; then execute the first-client grant procedure above.
6. Rollback if needed: redeploy previous release commit; leave schema in
   place; disable any granted PlanModule row.

## Gate results

Recorded in the final acceptance report (complete MySQL suite, fast suite,
lint/format, cache compiles, catering suite counts).
