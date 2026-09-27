# W-F — Heartbeat build / compatibility reporting (Team F report)

Date: 27 Sep 2026 · Branch: `feat/edge-config-refresh-v1` (worktree `pos-saas-edge`) · Not committed (the coordinator commits).
Scope: shared-POS architecture §10 / §11 W-F, as the owner approved: reporting rides the heartbeat, authority semantics are
unchanged, the report is informational and idempotent, and the Cloud writes the device row only when the build changed.

## What changed

| File | Change |
|---|---|
| `app/Services/Edge/EdgeAuthorityLeaseClient.php` | `heartbeat($seq, $state, ?array $build = null)` sends `{seq, edge_state, build}`. New `heartbeatPayload()` (exact body), `buildReport(?EdgeLocalMeta)` (never throws: returns null on any failure), `envelopeVersions()` (from the envelope builder constants). `EdgeBuildInfoService` is an optional constructor dependency. |
| `app/Services/Edge/EdgeAuthorityService.php` | One line: `heartbeat()` passes `$this->client->buildReport($meta)`. The ack handling is unchanged. |
| `app/Http/Controllers/Edge/EdgeAuthorityApiController.php` | `seq`/`edge_state` validation and every lease/refusal path are byte-for-byte as before. `build` is validated separately (`reportedBuild()`). An invalid block is dropped and logged (`notice`); it never causes a 422. After an accepted beat (outside the tenant lease transaction, after tenancy deactivation), the controller calls `recordBuildReport()`. |
| `app/Services/Edge/EdgeAuthorityLeaseService.php` | `BUILD_STRING_FIELDS` / `BUILD_LIST_FIELDS` (the allowlist and its bounds), `normalizeBuild()` (the canonical allowlisted block), `recordBuildReport()` (sha256 of `EdgeCanonicalJson` → conditional master `UPDATE … WHERE build_reported_hash IS NULL OR <> :hash`; wrapped in try/catch, so a failure only logs). `heartbeat()`, `handback()`, `release()`, `blocksCloud()` are untouched. |
| `app/Services/Edge/EdgeStandbyAdvertiser.php` | Every accepted heartbeat ACK gains `capabilities` = `CLOUD_CAPABILITIES` = `['customer_create' => false]` (additive; Team D flips it). It is present in both the branch-found and branch-missing shapes. |
| `database/migrations/2026_09_27_000001_widen_edge_devices_version_columns.php` | Master DB: `edge_devices.app_version` / `schema_version` 40 → 64; adds `build_reported_hash` char(64) NULL and `build_reported_at` timestamp NULL. `down()` drops the two columns and deliberately does not narrow the widths, because narrowing could fail or truncate. |
| `tests/MySql/EdgeHeartbeatBuildReportMySqlTest.php` | New, 6 tests / 87 assertions (below). |
| `tests/Feature/Edge/EdgeHeartbeatBuildPayloadTest.php` | New, 3 tests / 20 assertions: appliance payload builder, no DB. |

`EdgeCompatibilityService` is **not** changed. Instead, the stored manifest carries the compatibility-report aliases
(`bootstrap_schema_version`, `config_schema_version`), so the existing `classify()` reads a heartbeat-reported manifest as-is.
A test proves `classify()` returns `compatible`.

## Cloud write rule (what lands on `edge_devices`, master)

Written only on an **accepted** heartbeat, which includes the idempotent same-seq re-ack, and only when
`sha256(canonical(normalizeBuild(build)))` differs from `build_reported_hash`:

- `app_version` = `edge_app_version` (≤ 64)
- `schema_version` = `bootstrap_schema` (≤ 64). A null fact never overwrites an existing value.
- `compatibility_manifest` = the normalized build + `bootstrap_schema_version`, `config_schema_version`, `reported_via: "heartbeat"`
- `compatibility_reported_at`, `build_reported_at`, `updated_at` = now
- `build_reported_hash`

Other cases:

- **Same hash:** no query writes. The row stays byte-identical (tested).
- **Refused stale beat:** records nothing.
- **Old appliance (no `build`, or `build: null`):** records nothing, and the response is the old contract plus `capabilities`.
- **Keys the Cloud does not know** (from a newer appliance): ignored. Known keys are recorded.

Validation bounds: strings are nullable, max 64. `edge_schema_version` / `applied_edge_schema_version` are max 190.
`envelope_versions` is a list of at most 20 strings, each ≤ 64. `capabilities` is a list of at most 100 strings, each ≤ 100.

## Payload example (appliance → Cloud, no secrets; auth stays in the headers)

```json
{
  "seq": 4182,
  "edge_state": "standby",
  "build": {
    "edge_app_version": "0.7.0-edge",
    "git_commit": "599c5d0",
    "artifact_version": "0.7.0-edge",
    "bootstrap_schema": "edge-bootstrap-v7",
    "config_schema": "edge-config-v1",
    "edge_schema_version": "edge-local-schema@<newest database/migrations/edge file>",
    "applied_edge_schema_version": "edge-local-schema@<edge_local_meta.edge_schema_version>",
    "envelope_versions": ["edge-sale-envelope-v1", "edge-sale-envelope-v2", "edge-return-envelope-v1",
                          "edge-purchase-return-envelope-v1", "edge-supplier-payment-envelope-v1", "edge-supplier-ap-journal-envelope-v1"],
    "capabilities": ["local_auth", "local_pos_cash_sales", "held_sales", "dine_in_tables", "kot", "local_printing", "..."]
  }
}
```

Response (accepted): the existing lease + freshness keys, unchanged, plus `"capabilities": {"customer_create": false}`.
Refusals (`409 STALE_HEARTBEAT` + `seq`, `409 EDGE_STATE_INVALID`, `403 WRONG_TENANT`) are unchanged.

`envelope_versions` lists every envelope generation this build can emit, not only the two sale ones. That is additive
and informational.

## Tests

- `EdgeHeartbeatBuildReportMySqlTest` (6 / 87):
  - (a) recorded once, an identical beat (keys reordered) writes nothing, a changed build writes again, and a repeat of the change is again a no-op;
  - (b) an old appliance (no key, and an explicit `build: null`) is served exactly as before, and the response keys are the old list + `capabilities`;
  - (c) a same-seq replay (with build, including a replay claiming `standby` while the edge holds) re-acks with the lease row byte-identical, and a stale lower seq carrying a changed build gets the exact 409 body with both the lease row and the device row untouched;
  - (d) `capabilities.customer_create === false`, with and without `build`;
  - an invalid build (oversized, nested list item, non-object) never fails the beat and is not recorded; unknown future keys are ignored;
  - end to end: the appliance's real `EdgeAuthorityService::heartbeat()` tick sends its build (with the applied schema from `edge_local_meta`), the real Cloud endpoint records it, and the holder stays `cloud`.
- `EdgeHeartbeatBuildPayloadTest` (3 / 20), covering (e): payload keys and values; unbound meta gives a null applied schema; a throwing build-info gives `null`, not an exception; the request body is `{seq, edge_state, build}`; the legacy 2-arg call sends `build: null`; no secret-like strings.
- Runs (t4 isolated DBs, PHP 8.3.16):
  - `--filter EdgeHeartbeatBuildReport`: **OK 6 / 87**.
  - `vendor/bin/phpunit tests/Feature/Edge`: **OK 141 tests / 34 814 assertions** (includes the new payload test and the artifact / dependency-closure gates).
  - `--filter 'EdgeHeartbeatBuildReport|EdgeAuthority|EdgeConnectionPartition'`: **11 of 13 green, and the 2 failures are NOT GREEN**. The green 11 include `EdgeAuthorityLeaseHttpMySqlTest` and `EdgeAuthorityWorkerLifecycleMySqlTest`. The two failures are in the multi-process partition proofs, `EdgeAuthorityPartitionTest` and `EdgeConnectionPartitionTest`. Each failure is a **wall-clock pacing assertion**: each test spawns one fresh PHP process per step, and the steps must finish inside a 20 s TTL.
    - First run: "healthy-partition checks ran well inside the lease — 26.8 s is not < 15" and "failures recorded inside the lease — 27.1 s is not < 17".
    - Re-run of only those two tests: 26 min 35 s for 2 tests. "the appliance does not yet consider the lease lapsed" and "tick 2 expected connection_unstable, got preparing_local" — the TTL lapsed between slow spawns.
    - The machine was running 15–19 concurrent `php.exe` from other teams' suites. A bare `artisan --version` took 1.4–2.6 s.
    - The only W-F code on those paths is `buildReport()` inside `EdgeAuthorityService::heartbeat()`, measured at **2.2 ms per call**. The unreachable-transport step is still connection-refused on `127.0.0.1:9`.
    - Judgement: this is load, not the change. It is still unproven. **Re-run these two tests on a quiet machine before sign-off.**

## Not done / follow-ups

- **Cloud Offline Edge page view** ("update required" / current version): this is listed under W-F in §11, but this brief excluded views. The data is now on `edge_devices` (`app_version`, `compatibility_manifest`, `build_reported_at`), and `EdgeCompatibilityService::classify($device->compatibility_manifest)` works on it.
- The appliance does not yet **store or act on** `capabilities` from the ACK. That is W-D (Team D reads `capabilities.customer_create`; an absent key must mean `false`).
- The "`compatibility/report` also called after `edge:local:update`" belt-and-braces call (§10) is not added. It is out of the owned files (updater command). Heartbeat reporting already covers it within one beat after the update.
- `EdgeDevice` model `$fillable`/`$casts` are not changed (not an owned file). The writes use the query builder, so no model change is needed. Adding `build_reported_at => datetime` to the casts would be a convenience for the page.
- Other teams' uncommitted edits in this worktree (`TenantProvisioner.php`, `tests/MySql/Support/EdgeLocalRuntimeFixture.php`, `app/Support/Pos/`) were not touched.
