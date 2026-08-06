# EDGE-RUNTIME-BOUNDARY-1 — Branch Server runtime + artifact boundary

**Date:** 2026-08 · **Branch:** `feat/14d-2-plan-upgrade-requests` · **Base:** `e788200` · **Status:** built
locally, **not deployed**, EDGE feature inactive, no Local Mode. First actual Edge *implementation*
sprint after the design chain.

## Purpose

Answers **"what exact restricted Bingoo application is allowed to exist and run on a branch server?"**
It does **not** answer "how does offline selling work" — no local DB, auth, sales, sync, or installer
were built (explicit non-goals). This is the security / packaging / runtime boundary the later Edge
sprints build inside.

## Cloud vs Branch Server runtime

One canonical runtime identity, reused from the existing foundation: **`config('app.role')`** ∈
`cloud | branch_server` (env `APP_ROLE`, default `cloud`). The new `App\Support\EdgeRuntime` is the
single low-level reader:

- `EdgeRuntime::mode()` — returns the mode; an **unrecognized** `APP_ROLE` value **throws** (fails
  closed) rather than defaulting to cloud, so a typo on a branch PC can never silently run the full
  SaaS. Empty/unset = the documented `cloud` default.
- `EdgeRuntime::isBranchServer()` / `isCloud()`.
- `EdgeRuntime::assertBootConfig()` / `bootProblems()` — **fail-closed boot** (C): a `branch_server`
  instance that cannot describe itself (missing `edge.app_version` / `bootstrap_schema` /
  `artifact_format_version`, or PHP below `edge.min_php`) throws at boot (wired in
  `AppServiceProvider::boot`, HTTP runtime only). It **never** falls back to cloud behavior. It does
  NOT require local DB / device / tenant-branch binding — those belong to later sprints.

`BranchOperatingModeService` continues to read the same `app.role` key (one source of truth; no
parallel EDGE/LOCAL/OFFLINE booleans).

## Route allowlist (default DENY)

`config/edge.php → route_allowlist` is the machine-readable manifest of route-name patterns allowed
in `branch_server`. This sprint it is deliberately **minimal**:

```
edge.local.*   # health / readiness / build-info — the only Branch Server surface today
```

`App\Http\Middleware\EnsureEdgeRuntimeRouteAllowed` (appended to the `web` group; alias
`edge.runtime.boundary`) enforces it: **no-op on cloud**, **default-DENY on branch_server** (any
route whose name is not allowlisted → controlled `404`). `IdentifyTenant` short-circuits on
`branch_server` (the appliance is single-purpose, not a multi-tenant host), so the health endpoint
answers on the local host without a registered tenant domain.

Future POS/sales/print routes are intentionally **not** allowlisted yet; each future Edge sprint
widens the allowlist on purpose.

## Cloud-only denial matrix (proven blocked on branch_server)

Representative route names asserted denied by `EdgeRuntimeBoundaryTest` (drives the real middleware):

| Area | Representative route |
|---|---|
| Central / SaaS admin, provisioning | `central.tenants.index`, `central.tenants.provision` |
| Billing / plans / subscription upgrades | `central.invoices.index`, `central.plans.index`, `central.subscription-requests.approve`, `tenant.billing.upgrade.store` |
| Backup / restore / reset / sync admin | `central.tenants.sync-all`, `central.tenants.backup-all`, `central.tenant-backups.restore` |
| Purchasing / GRN / AP | `tenant.purchase-orders.index`, `tenant.goods-receipts.store`, `tenant.supplier-payments.store` |
| AR / GL / chart of accounts / finance | `tenant.finance.accounts.index`, `tenant.finance.journal-entries.index`, `tenant.finance.general-ledger.index` |
| Manufacturing | `tenant.manufacturing.production-orders.store`, `tenant.manufacturing.bom.index` |
| Cloud-side edge admin / marketing / central pairing | `tenant.offline-edge.index`, `public.home`, `edge.api.pair` |
| Future POS surface (not yet allowlisted) | `tenant.pos.store`, `tenant.shifts.store`, `tenant.sales-orders.split-bill.store` |

Anonymous (unnamed) routes are denied. `edge.local.health|ready|build-info` are allowed.

## Defence in depth

1. **Route registration** is the design intent (only `edge.local.*` is meant to run on a branch).
2. **`EnsureEdgeRuntimeRouteAllowed`** enforces default-DENY so *accidentally registering* a future
   cloud-only route does not expose it.
3. **Fail-closed boot** prevents a misconfigured branch instance from running at all.

## Artifact builder + integrity + secret audit

`App\Services\Edge\EdgeArtifactBuilder` (+ `edge:build-artifact`, `edge:audit-artifact`) ships **only**
an explicit allowlist (`config/edge.php → artifact.include`), prunes `artifact.exclude`, and **fails
the build** if any `artifact.forbidden` pattern survives (secrets / VCS / dumps / dev / FakePrinter).
It reports paths only, never contents.

- **Included:** `app`, `bootstrap`, `config`, `database/migrations`, `lang`, `public`, `resources`,
  `routes`, `vendor`, `artisan`, `composer.json`, `composer.lock`.
- **Never shipped:** `.env*`, `.git`, `tests`, `docs`, `node_modules`, `storage/logs`,
  `storage/app/backups`, `bootstrap/cache`, `tools/print-agent/dist` (FakePrinter), any
  `*.pem/*.key/*.pfx/*.p12/*.crt`, `*.sql/*.dump/*.sqlite`, SSH keys, shell/PsySH history.
- **Build manifest** `edge-build-manifest.json` (H) — product, `edge_app_version`,
  `artifact_format_version`, `bootstrap_schema` (=`edge-bootstrap-v3`), `sync_protocol`
  (`edge-sync-v0`, placeholder), `min_php`, `runtime_mode_supported`, `git_commit`,
  `build_timestamp`, `file_count`, **per-file SHA-256** and a **manifest_hash**. Input to the future
  signed updater.
- **Integrity (I):** per-file SHA-256 + manifest hash detect corruption / accidental modification /
  bad updates. This does **not** stop a fully compromised Windows administrator; a signed updater
  (later) will verify a **signed** manifest. No fake signature / committed private key exists.
- **Secret audit (O):** `edge:audit-artifact` fails non-zero on any forbidden path — usable as a CI
  gate. The real repo plan audits **clean** (10,242 files, no secrets).

> **Cloud-only source exclusion:** this sprint ships the whole Laravel `app/` + `vendor` closure so
> the appliance can boot and answer health, and defends cloud-only surface **at runtime** via the
> route boundary. Finer-grained *class-level* exclusion of cloud-only application source is deferred
> to a later Edge packaging sprint (documented here rather than half-done).

## HARDEN-1 (2026-08-07) — fail-closed artifacts + close Cloud/API exposure

Hardening pass on top of the boundary, after the production deploy of `0d8d656`:

- **Fail-closed artifact identity:** the builder writes `edge-build-manifest.json` with
  `runtime_mode_supported=branch_server` (git-ignored in the source tree). `EdgeRuntime::isPackagedEdgeArtifact()`
  detects it; if the marker is present but `APP_ROLE` is missing/cloud/invalid the boot **fails
  closed** (a packaged appliance can never silently run as Cloud). A bare Cloud working tree has no
  marker, so `APP_ROLE`-absent still means cloud there.
- **Per-mode route REGISTRATION** (`routes/web.php`): a Branch Server registers **only** the Edge
  runtime routes — the entire Cloud SaaS surface (public/central/tenant **and** the central `api/edge`
  pairing API) is not even registered. Cloud registers the full SaaS and does **not** register the
  appliance endpoints, so **`/edge/local/*` → 404 on Cloud** (no runtime/build fingerprint exposed
  publicly). The middleware remains as defence-in-depth. This closes the earlier finding that
  `/edge/local/health` was publicly reachable on the cloud domain.
- **Explicit allowlist** (no wildcard): `edge.local.health|ready|build-info` only; a future
  `edge.local.*` route is denied until deliberately added.
- **Health minimisation:** `/edge/local/health` now returns only `status`, `runtime_mode`, version/
  compat essentials + `server_epoch_ms`; the fuller fingerprint (php version, git commit, build
  timestamp) stays on `/edge/local/build-info` (which may later require local auth).
- **Artifact runtime dirs:** the builder creates the empty writable dirs a Laravel boot needs
  (`storage/framework/{cache,sessions,views}`, `storage/logs`, `bootstrap/cache`) — a file-copy omits
  empty dirs.
- **TLS SAN corrected:** verified that `New-SelfSignedCertificate -DnsName @(host,ip)` renders the IP
  as a *DNS* SAN (fails IP validation); the server-cert script now uses an explicit SAN
  `TextExtension` (`2.5.29.17={text}DNS=…&IPAddress=…`), verified to emit a real `IP Address` SAN.

**Status honesty:** the **runtime route boundary is implemented and enforced** (registration + middleware
+ fail-closed). **Physical source minimisation is PARTIAL** — Cloud route *registration* is eliminated
on a Branch Server, but the Cloud application *source/classes* are still present in the artifact. A
class-level dependency-closure minimisation remains a **RELEASE GATE** before distributing the appliance
to clients (do not treat runtime middleware as equivalent to source minimisation).

Tests: `EdgeBranchServerRegistrationTest` (real HTTP + per-mode registration, Web+API), boundary
explicit-allowlist + cloud-404 + fail-closed-marker regressions, artifact runtime-dir + marker test.

## BUILD-RELEASE-CLOSURE-1 (2026-08-07) — reproducible, physically-fail-closed artifacts

Closes build-pipeline holes found after HARDEN-1:

- **Physical recursive audit:** `EdgeArtifactBuilder::physicalForbidden($dir)` walks EVERY file under a
  built artifact (independent of the include allowlist), so a stray file that was never in the plan
  (e.g. a copied `.env`, `.pem`, `.git/`, dump) is still caught. `build()` runs it after copy, and
  `edge:audit-artifact` now uses it (was plan-based and blind to out-of-plan files). Paths only.
- **`--force` never preserves stale files:** a non-empty destination is refused without `--force`;
  with `--force` the destination is **deleted and recreated empty** — but only if it is a previous
  artifact (`edge-build-manifest.json` present) and not an unsafe path (filesystem/drive root, too
  shallow, the project dir or an ancestor, the home dir).
- **Release provenance:** default (RELEASE) mode **refuses a dirty tree**, stamps
  `git_commit = actual HEAD`, sets `source_dirty=false`, `build_mode=release`, and **rejects
  `--git-commit`**. `--allow-dirty` (DEV/TEST) permits a dirty tree + commit override but records
  `source_dirty=true` / `build_mode=dev` so it can never be mistaken for a release. (This fixes the
  earlier build that stamped `--git-commit=0d8d656` onto a dirty HARDEN-1 tree.)
- **Manifest hash order:** the integrity manifest is generated LAST, after every source file + runtime
  dir is finalized; nothing mutates the artifact afterwards.
- **Exact route census:** `EdgeBranchServerRegistrationTest` enumerates EVERY registered route URI on
  a Branch Server and asserts the surface is only `up`, `edge/local/{health,ready,build-info}` — any
  new/framework/fallback route fails until deliberately approved. This caught Laravel's
  `storage/{path}` file-serving route; **public-disk `serve` is now disabled on branch_server**
  (`config/filesystems.php` → `serve = EdgeRuntime::isCloud()`), so it is not registered there.
- **Release vendor contract:** a client RELEASE artifact must be built against
  `composer install --no-dev --optimize-autoloader` (the local dev vendor ships phpunit/mockery). The
  builder does not run composer; this remains a documented **RELEASE gate** (the physical audit still
  passes, but dev packages are present in a dev build).

## LAN TLS + local name contract

Pilot contract (locked): managed Windows POS terminals; Branch Server on a DHCP-reserved IP; TLS via
a **branch-local CA + server certificate**. `config/edge.php → lan` holds hostname / reserved IP /
name mechanism. **mDNS `.local` is not reliable on every Windows LAN**, so the pilot mechanism is a
**hosts file / router DNS + reserved IP**, and the server cert SAN covers **both** hostname and IP.

`scripts/edge/` (PowerShell, AST-syntax-verified, `-WhatIf` supported, inputs validated):
`New-EdgeBranchCA.ps1` (non-exportable CA key; exports only the public CA cert), and
`New-EdgeServerCertificate.ps1` (server cert from that CA with `DNS=<hostname>` + `IP=<reserved_ip>`
SAN). Neither disables TLS verification or enables plain HTTP; nothing generated is committed.
**Remaining for the installer:** binding the cert to the web listener, CA distribution to terminals,
DPAPI/escrow key recovery, renewal automation + expiry on the health endpoint, the one-click
installer.

## Health / build-info surface

`EdgeBuildInfoService` + `EdgeRuntimeController` expose the only branch_server routes, **non-secret**
only (never `.env` / DB password / `APP_KEY` / device tokens / certs / customer data):

- `GET /edge/local/health` — liveness + build facts + `server_epoch_ms`.
- `GET /edge/local/ready` — fail-closed readiness; later-sprint checks (`local_database`,
  `local_auth`, `config_revision`, `sync_queue`, `print_agent`) reported honestly as
  `not_implemented` (nothing faked).
- `GET /edge/local/build-info` — the compatibility surface for the future signed updater
  (`supportsBootstrapSchema` / `supportsSyncProtocol`).

## Carried-forward MySQL baseline gates (S)

Absorbed here as **cloud** regressions, proven with **genuine two-process** concurrency against the
**real** services (`tests/MySql/CarriedForwardRaceTest`):

1. **print_jobs.logical_key** — two processes call the real `PrintJobService::queueReceipt(ensureOnce)`
   → exactly one logical automatic receipt job; `copy_no` does not increment.
2. **Direct-Pay resume** — two processes call the real `DirectPayPrintOrchestrator::orchestrate()` on
   the same paid sale (with KOT routing) → one receipt logical job, **one KOT batch (no new
   revision)**, `copy_no` stable, no 500.
3. **Restaurant open-check** — two processes call the real `RestaurantTableSessionController@open` on
   the same table → **exactly one open check**, one controlled loser (422), no 500.

## Test summary

- `tests/Feature/Edge/` (fast suite): `EdgeRuntimeBoundaryTest` (58), `EdgeArtifactTest` (4),
  `EdgeBuildInfoTest` (3).
- `tests/MySql/CarriedForwardRaceTest` (authoritative): 3 two-process races.
- Cloud non-regression: full MySQL suite + fast suite remain green.

## Explicit remaining blockers before EDGE-LOCAL-RUNTIME-1

- Local MariaDB + transactional bootstrap import + activation epoch (`EDGE-LOCAL-RUNTIME-1`).
- Edge-local verifier + enrollment assertion + key split (`EDGE-LOCAL-AUTH-1`).
- Cross-system identity (`EDGE-IDENTITY-1`), then local POS / print / split-brain stock / sync.
- Installer, cert binding + CA distribution, signed update channel.
- Physical LAN printer certification (still blocked); Merge Table UI still hidden pending its own
  concurrency certification.
