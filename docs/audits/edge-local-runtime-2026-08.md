# EDGE-LOCAL-RUNTIME-1 — branch-local database, bootstrap import & immutable binding

Status: **built + MySQL-proven on `feat/14d-2-plan-upgrade-requests` (NOT yet deployed).** Establishes
the first real Branch Server local runtime foundation. **No login, no sales, no printing, no sync, no
Local Mode activation.** Production stays `APP_ROLE=cloud`, `EDGE_FEATURE_ENABLED=false`.

## Local DB topology
- New `edge_local` connection (`config/database.php`, env `EDGE_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`,
  default `bingoo_edge_local` @ `127.0.0.1`).
- On a `branch_server` runtime the Laravel `tenant` connection is RESOLVED to this local DB
  (`App\Support\EdgeLocalDatabase::useAsTenantConnection`) — config + purge + set-default, **no eager
  reconnect** (lazy), so an uninitialised appliance still answers `/edge/local/health` and can run
  `edge:local:db-init` before the DB exists. The appliance never needs the Cloud master/tenant DBs for
  the local-runtime foundation (proven with master pointed at a nonexistent DB).

## DB safety guard (fail closed)
`EdgeLocalDatabase::unsafeReason()` refuses destructive Edge-local work unless: (1) runtime is
`branch_server`; (2) DB name matches the dedicated Edge convention (`bingoo_edge_*` / `edge_local_*` /
an *edge*…*test* name) and is never `pos_saas_master` / `pos_tenant_*` / a production DB; (3) host is
loopback (a non-loopback host needs an explicit `EDGE_DB_ALLOW_REMOTE=true` **and** a *test* DB name).

## Schema strategy — dedicated Edge migration path
- Edge-only tables live in `database/migrations/edge/` (e.g. `edge_local_meta`) — Laravel globs
  migration paths non-recursively, so `deploy.sh` (which migrates every Cloud tenant with
  `--path=database/migrations/tenant`) NEVER creates Edge tables in a Cloud tenant DB.
- `edge:local:db-init` runs the **real tenant migrations** + the Edge migrations against the Edge-local
  DB, using Laravel's `Migrator` DIRECTLY (`usingConnection('tenant', …)`), NOT `Artisan::call('migrate')`
  — so the CLI boundary keeps DENYING raw `migrate`/`migrate:fresh`/`db:wipe` on a Branch Server with no
  self-conflict. The full tenant schema exists locally; Cloud-only tables may sit empty (documented
  minimum-drift; physical schema minimisation is a later appliance-release optimisation).

## CLI + scheduler boundary
`EdgeConsoleBoundary` (default DENY) — on `branch_server` only `config('edge.cli_allowlist')` commands
run (`edge:local:*` + required framework cache ops); `system:reset`, `demo:*`, `tenants:*`, `migrate*`,
`db:wipe`, master seed, etc. are refused. The entire Cloud scheduler is gated to `isCloudSafe()` so a
Branch Server auto-runs no Cloud jobs.

## App key
A packaged Branch Server uses its OWN `EDGE_LOCAL_APP_KEY` (resolved into `app.key`) and never reuses
the Cloud `APP_KEY`; a missing local key fails the boot guard (`EdgeRuntime::bootProblems`).

## Immutable binding + singleton
`edge_local_meta` holds ONE binding row: tenant_code/tenant_id/branch_id/device_uuid/activation_epoch +
bootstrap snapshot/schema/source-revision/manifest-hash + runtime_state
(`uninitialized|importing|bootstrapped|error`). Defence-in-depth: the model forces `singleton_guard=1`
on create and forbids mutating identity fields once set; `EdgeBranchContext` rejects a corrupt
multi-row state. `EdgeBranchContext` splits a graceful probe (`tryCurrent`/`isBound`; not-initialised →
null, real DB errors propagate) from a strict `requireCurrent` (EdgeNotBoundException); `EnsureEdgeBranchBound`
uses the strict path and never trusts a request `branch_id`.

## Activation epoch (generation) — durable, Cloud-authoritative
`edge_branch_activations` (master, append-only) stores a monotonic generation per (tenant, branch).
`EdgeActivationEpochService::allocateForDevice` runs in a row-locked master transaction that
**revalidates the device** (not revoked, `active_slot=1`, still the current active device) before
allocating/reusing — so a revoked/replaced device can NEVER bump the epoch. Same device retries to the
same generation; a new authoritative device gets a strictly newer one. The snapshot carries
`activation_epoch`; reuse is scoped to the current epoch; acknowledge fences a stale generation
(`STALE_ACTIVATION`).

## Bootstrap schema decision — v3 → **v4**
Bumped deliberately: the wire contract materially changed — the manifest now carries `activation_epoch`
+ `device_public_uuid` (both bound into `manifest_hash` via the single-source `computeManifestHash`),
and three new sections ship (`recipes`, `recipe_ingredients`, `unit_conversions`). No real appliance
consumes v3 (`EDGE_FEATURE_ENABLED=false`, none deployed) — a clean forward bump, not a compat break.

## Bootstrap import (atomicity)
`EdgeLocalBootstrapImporter` consumes the ACTUAL Cloud package (`EdgeBootstrapService::exportPackage`
= manifest + sections; the same data the authenticated Cloud download API serves). It:
- accepts schema v4 only; re-verifies the manifest hash (self-consistency) + EVERY section hash
  (corruption) + row counts;
- verifies tenant / branch / device / activation_epoch; required sections; **branch scoping** (any
  branch-scoped row for another branch → whole import rejected); no secret fields; no Cloud
  operational history;
- DDL is done beforehand by `db-init`; the data import runs in ONE InnoDB transaction, parents before
  children (categories topologically ordered), preserving Cloud IDs, **never disabling
  FOREIGN_KEY_CHECKS**; any late failure rolls back everything;
- initial bootstrap only: same package retried → idempotent no-op; a different tenant/branch/device →
  binding-immutable rejection; a different config revision → controlled `REFRESH_NOT_IMPLEMENTED`
  (never DELETE+INSERT). Config refresh is EDGE-CONFIG-REFRESH-1.

## Imported vs explicitly excluded
- **Imported (config):** branch, units, categories, products (incl. recipe raw-material products so
  the FK holds), variants, barcodes, branch prices, terminals, modifier groups/modifiers, combos,
  payment methods, restaurant floors/tables/waiters, delivery channels/riders, printers, receipt
  layouts, category-printer mappings, terminal printer settings, service charges, void reasons,
  **recipes / recipe_ingredients / unit_conversions**, roles, users (identity only) + role assignments.
- **Excluded (never imported):** password/PIN hashes, Cloud `APP_KEY`, device secrets, payment-provider
  secrets, and all Cloud operational history (sales, payments, journals, GL, COGS, FEFO layers,
  purchase orders, GRNs, manufacturing orders, previous Cloud shifts).

## Authenticity (honest boundary)
Hash verification = **integrity/corruption** detection, NOT cryptographic Cloud authenticity. There is
no Cloud signature on the snapshot; authenticity comes from the authenticated device/bootstrap download
channel. A manually-copied package file could be fabricated by a branch admin who recomputes the hashes
— signed config/update artifacts are a later security enhancement.

## Readiness states (foundation vs selling)
`/edge/local/ready` (503 until the foundation is ready): `runtime_boundary` ready, `local_database`
ready after db-init, `bootstrap_binding` ready after import; `config_ready` mirrors the binding;
**`operational_stock` is ALWAYS `not_ready`** (a stale bootstrap quantity must never become the selling
authority — that needs the future activation fence: Cloud baseline @R + generation + ack cursor);
`local_auth` / `local_pos` / `local_print` / `sync` = `not_implemented`; `activation_ready` = false.

## What remains NOT implemented (next sprints)
Local login/PIN verifier + effective permissions (**EDGE-LOCAL-AUTH-1**, next), local shifts/sales/held/
add-round/tables/direct-pay, KOT/Reminder/Receipt, Print Agent polling, sync/outbox, config refresh,
activation stock baseline/fence, Local Mode activation, installer EXE. The Branch Server is NOT
"offline ready".

## Test proof (MySQL authoritative)
Import 7/7 (real buildSections package incl. recipe config + raw materials imports FK-coherently;
cross-branch rollback; tampered-section / secret-field / history rejection; idempotent → immutable →
refresh-not-implemented; multi-binding corruption detected), epoch 2/2 (device revalidation; stale
after replacement), db-init 2/2 (schema built while raw migrate denied; local runtime works with master
unreachable). Fast suite covers the boundary/CLI/scheduler census, safety guard, app-key fail-closed,
uninitialised health 200 / ready 503.
