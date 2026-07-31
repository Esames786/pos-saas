# Branch Bootstrap Snapshot — Design & Security (BRANCH-BOOTSTRAP-SNAPSHOT-1)

Status: **implemented locally** — 2026-07. A paired device can download a deterministic,
immutable, **branch-scoped** bootstrap snapshot and acknowledge it (moving the DEVICE
`pending_bootstrap → ready`). No Local POS activation, no sync, no installer, no official
stock/finance posting. The **branch stays `pending`** throughout and cloud sales keep
working. `EDGE_FEATURE_ENABLED` stays `false`; deploy deferred.

Builds on `branch-device-pairing-design-2026-07.md`.

---

## 0. Preflight fixes carried in this sprint
- **Migration `..._000002` `down()` order** — the irreversible/duplicate-history check now
  runs **before any index is dropped**, so a refusal never leaves the schema partially
  modified (proven: injected revoked-history → `down()` threw with the `edge_pairing_codes`
  live-code unique still intact).
- **Concurrent-generate flash race** — under two simultaneous same-session generate requests
  the losing request's session write could clobber the winner's flashed code, so the admin
  saw nothing. The one-time code is now rendered **directly in the generate POST response**
  (no session flash) with `no-store` headers. Proven across two processes: the 200 winner's
  own response shows a code whose digest matches the sole live `edge_pairing_codes` row; the
  loser gets 409 `PAIRING_CODE_ALREADY_ACTIVE`; a GET of the setup page never shows a code.

## 1. Security contract (re-checked at create AND acknowledge)
Tenant + branch are resolved **only from the authenticated `EdgeDevice`** — request
`tenant_id`/`branch_id` are ignored (proven: spoofed `tenant_id=999&branch_id=999` → the
snapshot is still the device's branch). Bootstrap is allowed only when:
- device is active-slot, **not revoked**, status `pending_bootstrap` or `ready`;
- tenant subscription active + `offline_edge` entitled + `EDGE_FEATURE_ENABLED`;
- branch lifecycle is **`pending`** (else `EDGE_BOOTSTRAP_BRANCH_NOT_PENDING`).
Nothing here activates Local POS or moves the branch to `active`.

## 2. Persistence (master DB)
`edge_bootstrap_snapshots` (public_uuid, tenant_id, branch_id, edge_device_id,
schema_version=`edge-bootstrap-v1`, status `building|ready|downloaded|acknowledged|failed|
expired`, source_revision watermark, manifest_hash, section_summary JSON, generated/expires/
downloaded/acknowledged timestamps, failure_code/last_error) + `edge_bootstrap_snapshot_sections`
(snapshot_id, name, content_hash, row_count, **payload_gz** = base64(gzip(canonical JSON));
unique(snapshot_id, name)). Snapshots are immutable once `ready`; a failed build leaves a
controlled `failed` snapshot (sections deleted), never a half-ready one.

**Reuse / idempotency:** a `source_revision` watermark (per-table max(updated_at)+count over
the branch-scoped + global tables) lets the same device+revision reuse one snapshot. Concurrent
creates are serialized by a **tenant/device row lock**: the first claims a `building`
placeholder and builds outside the lock; a concurrent request reuses it (bounded wait for
`ready`). Proven across two processes: both `POST` → **same snapshot uuid**, exactly one ready
row.

## 3. API (central, device-authenticated)
```
POST /api/edge/bootstrap/snapshots                         create/reuse   → 201
GET  /api/edge/bootstrap/snapshots/{uuid}/manifest         manifest       → 200
GET  /api/edge/bootstrap/snapshots/{uuid}/sections/{name}  section bytes  → 200 (+X-Content-SHA256)
POST /api/edge/bootstrap/snapshots/{uuid}/acknowledge      acknowledge    → 200
```
All under `edge.device.auth` + `throttle:60,1`. Snapshots are addressed by **public UUID**
(never numeric id) and every access is **ownership-checked** against the authenticated device
(proven: another device → 404 `EDGE_BOOTSTRAP_SNAPSHOT_NOT_FOUND`, no existence leak). A
revoked device is rejected at the auth middleware (401). Expected failures self-render
controlled JSON, never a 500:
`EDGE_BOOTSTRAP_NOT_ALLOWED` (403), `EDGE_BOOTSTRAP_DEVICE_REVOKED` (401),
`EDGE_BOOTSTRAP_BRANCH_NOT_PENDING` (409), `EDGE_BOOTSTRAP_SNAPSHOT_NOT_FOUND` (404),
`EDGE_BOOTSTRAP_SNAPSHOT_EXPIRED` (410), `EDGE_BOOTSTRAP_HASH_MISMATCH` (422),
`EDGE_BOOTSTRAP_SCHEMA_UNSUPPORTED` (422).

## 4. Snapshot contents — strict branch scope, explicit allowlists
Every section is built with an **explicit column allowlist** (never `SELECT *`), so sensitive
columns cannot leak. Sections: `tenant` (code/name/currency only — no billing/DB creds),
`branch`, `terminals`(branch), `categories`, `units`, `products`, `product_variants`,
`product_barcodes`, `product_branch_prices`(branch), `modifier_groups`(branch)+`modifiers`,
`combos`(branch)+`combo_components`, `payment_methods`, `restaurant_floors/tables/waiters`(branch),
`delivery_channels`+`delivery_riders`(branch), `printers`(branch), `receipt_layout_settings`(branch),
`category_printer_mappings`(branch), `terminal_printer_settings`(branch terminals),
`service_charge_settings`(branch), `void_reasons`, `users`, `roles`.

**Verified exclusions:** COST columns (`products.default_purchase_price`,
`product_variants.purchase_price`), the finance link (`payment_methods.cash_bank_account_id`),
and **all** finance/journal/GL/COGS, purchasing/supplier/AP, manufacturing, official inventory
ledgers/FEFO/cost, other branches, subscription/billing, Print Agent tokens/device secrets, and
tenant/master DB credentials / `APP_KEY`. Proven: every branch-scoped row belongs to the bound
branch only; a second-branch snapshot's terminals are disjoint.

**Users:** MINIMUM staff identity only — `id, employee_code, name, default_branch_id,
default_terminal_id, status, locale, roles[]`, scoped to branch staff. **No** `password`,
`remember_token`, reset/API tokens, email or phone (proven absent). Local staff
authentication/PINs are **not** shipped in v1 — a safe Edge-specific credential mechanism is a
later sprint.

## 5. Deterministic integrity
Every section is canonically ordered (rows by id, object keys recursively `ksort`ed) and
JSON-encoded, then SHA-256 hashed. The manifest hash covers schema version, snapshot uuid,
tenant/branch and the per-section `{hash,count}`. Repeated reads of an immutable snapshot
return **identical bytes and hashes**; two fresh builds of the same data produce identical
section hashes (proven). The section endpoint returns the exact canonical JSON with
`X-Content-SHA256` = the logical hash; gzip transport (Accept-Encoding) leaves the logical
hash unchanged (proven: header == body hash == manifest hash, with and without gzip).

## 6. Download & acknowledgment
Fetching a section stamps `downloaded_at` (ready→downloaded). Acknowledgment requires the
snapshot uuid + schema version + manifest hash and verifies all three (`hash_equals`);
mismatch → 422 `HASH_MISMATCH`, wrong schema → 422 `SCHEMA_UNSUPPORTED`, expired → 410. A valid
acknowledgment moves the **device** `pending_bootstrap → ready` and is **idempotent** (second
ack is a no-op success). It **never** touches the branch (stays `pending`) and never activates
Local POS. A revoked device cannot download or acknowledge.

## 7. Consistency & failure
The tenant is activated via `TenancyManager`; sections are read from the tenant DB **outside**
the short master claim transaction (no long tenant reads under a master lock). `source_revision`
is recorded as a watermark for a future delta-refresh sprint. A build failure yields a
controlled `failed` snapshot; existing pairing cancellation/revocation reconciliation markers
remain functional and untouched.

## 8. QA summary
Service 22/22 (build, device-identity, branch-scope, cross-branch disjoint, exclusions incl.
no-password/no-cost/no-finance, determinism, reuse, ack lifecycle, tampered/schema/revoked/
not-pending/expired). Real HTTP: create 201 (spoof ignored), manifest, section integrity
(header==body==manifest, gzip-invariant), ack→ready, branch stays pending, ownership 404,
revoked 401, tampered 422; **2-process concurrent create → one converged snapshot**. Regression:
base pairing 27/27, HARDEN-1 14/14, HARDEN-2 11/11, cloud POS 200, Print Agents 200. 7/7 tenants
tb=0/neg=0/dept=0; no snapshot/device/code/marker residue; `offline_edge` on 0 plans; flag off.

## 9. HARDEN-1 (2026-07-31) — acknowledgment, complete delivery, consistency
Closed the review blockers before activation:
1. **One shared contract.** `assertContract($device)` is the single re-check used by BOTH
   create and acknowledge — device active-slot/not-revoked/status, subscription + entitlement
   + `EDGE_FEATURE_ENABLED`, the device is still the **current** active device for its
   tenant+branch, and branch is `pending`. Proven: removing entitlement / turning the flag
   off / suspending the branch / a replacement device / a revoked device **all block ack**,
   and none moves the device to `ready`.
2. **Race-safe device state.** Create and acknowledge now re-fetch the device **`lockForUpdate`**
   and re-validate the FRESH locked row (never a stale pre-lock model); acknowledge also locks
   the snapshot. Real two-process races: revoke-vs-acknowledge and revoke-vs-create → the
   revoked device is **never resurrected to `ready`**, no usable snapshot for a device revoked
   before the locked check, **zero 500s**.
3. **Complete-download tracking.** New per-section columns (`downloaded_at`, `delivered_hash`,
   `attempts`); fetching one section no longer marks the snapshot downloaded (only when EVERY
   section is fetched). Acknowledge requires a **complete section-hash receipt map** verified
   against the immutable manifest: missing/extra sections → 409 `EDGE_BOOTSTRAP_INCOMPLETE`,
   wrong hash → 422 `EDGE_BOOTSTRAP_SECTION_HASH_MISMATCH`; every required section must have
   actually been delivered. Repeated downloads are idempotent (single receipt, `attempts++`).
4. **Guaranteed tenancy cleanup.** Every `TenancyManager::activate()` is paired with a
   `finally { deactivate() }` in create and acknowledge — proven that after a FAILED and a
   SUCCESSFUL bootstrap op the default connection is `master` and no `tenant` binding remains.
5. **Consistent read boundary.** All tenant sections are built inside ONE tenant transaction
   (MySQL REPEATABLE READ) — proven a concurrently committed insert is not visible inside the
   build txn. The source revision is computed inside the same boundary; if it moved between
   the claim and the build, the build is failed as `EDGE_BOOTSTRAP_SOURCE_CHANGED` (retryable)
   rather than publishing a stale/mixed snapshot.
6. **Complete source revision.** The watermark now includes `terminal_printer_settings` and
   `model_has_roles` (+ all emitted tables) — proven a terminal-printer-mapping change yields a
   new revision / new snapshot instead of an incorrect reuse.
7. **Build-lifecycle responses.** New ready → **201**, reused ready/downloaded/acknowledged →
   **200**, still building → **202 `EDGE_BOOTSTRAP_BUILD_IN_PROGRESS`**, failed →
   **503 `EDGE_BOOTSTRAP_BUILD_FAILED`**, source changed → **409** retryable. Raw SQL/internal
   errors are never exposed.
8. **Tightened data policy.** Products = active **and** sellable **and** POS-visible only;
   variants/barcodes/branch-prices constrained to the included product set (no orphans);
   payments = **cash only** (card/wallet/bank/cheque excluded); delivery = **own only**
   (aggregator excluded); a `restrictions` section documents the blocked capabilities. Proven:
   no inactive/non-POS product, orphan variant/barcode/price, non-cash payment, or aggregator
   channel is emitted.
9. **Integrity certified.** `X-Content-SHA256` == SHA-256(response JSON) == manifest section
   hash for all sections, and **gzip transport is hash-invariant** (28/28 sections clean). A
   corrupted stored section is client-detectable (served hash ≠ manifest) and its acknowledgment
   is rejected `SECTION_HASH_MISMATCH`.

HARDEN-1 QA: service 34/34 + HTTP (201 new / 200 reused, complete ack→ready, gzip 28/28,
corruption detection, 2-process revoke-vs-ack and revoke-vs-create with 0×500) + regression
base pairing 27/27, HARDEN-1 14/14, HARDEN-2 11/11, cloud POS 200, Print Agents 200; 7/7
tenants tb=0/neg=0/dept=0, zero snapshot/section/device residue, `offline_edge` on 0 plans,
flag off.

## 10. Next
A readiness/activation sprint: after bootstrap + local receipt/KOT readiness, promote the
branch `pending → active` (Local POS) under explicit checks. Bootstrap alone never activates.
