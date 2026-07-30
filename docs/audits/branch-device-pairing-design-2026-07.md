# Branch Device Pairing — Design & Security (BRANCH-DEVICE-PAIRING-1)

Status: **implemented locally** — 2026-07. Secure, branch-bound Edge device pairing.
No bootstrap / catalog snapshot / sync / installer / entitlement lease built. Pairing
creates a device in **pending_bootstrap** only — it never activates Local POS, never
blocks cloud sales. `EDGE_FEATURE_ENABLED` stays `false`; deploy deferred.

Builds on `offline-edge-entitlement-design-2026-07.md`.

---

## 1. Why pairing lives in the MASTER DB
An Edge device has no tenant credentials and no local tenant DB *before* it pairs — it
knows only the cloud URL + a six-digit code. So the two pairing tables are in the
**master** DB. `branch_id` is a tenant-DB branch id with **no cross-DB FK**.

### `edge_devices`
`public_uuid` (unique, exposed), `tenant_id`, `branch_id`, `installation_uuid` (unique,
installer-generated once), `device_name`, `device_secret_hash` (sha256 of the client
secret — never plaintext), `status` (paired|pending_bootstrap|ready|active|revoked),
`active_slot` (nullable tinyint; **1** = current device, **NULL** = revoked/replaced),
`app_version`, `schema_version`, `paired_at`, `last_authenticated_at`, `revoked_at`,
`revoked_by_user_id`, `revoke_reason`.
**Unique (tenant_id, branch_id, active_slot)** → at most ONE active device per branch;
MySQL allows multiple NULLs so revoked history is preserved.

### `edge_pairing_codes`
`public_uuid` (unique), `tenant_id`, `branch_id`, `code_hash`, `attempts`,
`max_attempts` (5), `expires_at`, `used_at`, `paired_device_id`, `active_slot` (1 = the
branch's live code, NULL once used/cancelled/expired), `created_by_user_id`,
`cancelled_at`. **Unique (tenant_id, branch_id, active_slot)** → one live code per branch.

Neither the six digits nor the device secret is ever stored in plaintext or logged.

## 2. Pairing-code security
- Cryptographically random six digits, **15-minute** TTL, single-use, **max 5** attempts.
- Stored only as `code_hash = HMAC-SHA256(code, app key)`. Compared with `hash_equals`.
- **Queryable digest omits the public_uuid on purpose.** The installer must pair with the
  code ALONE (cloud URL + 6 digits, no subdomain, no branch id), so the server has to find
  the row from the code. To keep the lookup unambiguous, code generation guarantees the
  live code's hash is unique among all codes created in the last 24h (regenerates on the
  astronomically-rare clash). This is the Print Agent pairing pattern, hardened.
- Generation is throttled (`throttle:10,1` on the tenant action); the public exchange is
  throttled `throttle:5,1` per IP; the code's own `max_attempts` bounds per-code brute force.
- Exchange runs in a master transaction with `lockForUpdate` on the code row.
- Generating a new code cancels the branch's previous live code (single active slot).
- Generic public error codes never disclose tenant/branch existence:
  `PAIRING_CODE_INVALID` (422), `PAIRING_CODE_EXPIRED` (422), `PAIRING_CODE_EXHAUSTED`
  (422), `PAIRING_CODE_USED` (409), `PAIRING_DEVICE_CONFLICT` (409),
  `EDGE_ENTITLEMENT_REQUIRED` (403), `EDGE_DEVICE_LIMIT_REACHED` (409), `429` for rate
  limit. No 500 for an expected pairing failure.

## 3. Client-generated device secret (response-loss safe)
The cloud never mints or returns a permanent token — that pattern breaks if the pairing
response is lost. Instead:
1. The (future) installer generates locally `installation_uuid` (UUID) and `device_secret`
   (≥32 random bytes), and stores the secret **before** calling the cloud.
2. Pair request sends `pairing_code`, `installation_uuid`, `device_name`,
   `device_secret_hash = sha256(device_secret)`, optional `app_version`/`schema_version`.
3. The cloud stores only `device_secret_hash`.
4. Authenticated device requests send `X-Edge-Device-ID: <public_uuid>` +
   `Authorization: Bearer <device_secret>`; the cloud hashes the bearer and compares with
   `hash_equals`.

**Response-loss retry contract:** the same `pairing_code` + `installation_uuid` +
`device_secret_hash` always returns the SAME device and never creates a second one (proven
over real HTTP). A used code retried with a *different* installation_uuid or secret hash is
rejected `PAIRING_CODE_USED` (409).

## 4. Central pairing API
- `POST /api/edge/pair` — unauthenticated, central domain, `throttle:5,1`, CSRF-excluded
  (`api/edge/*`). Resolves tenant + branch **from the code**; request `tenant_id`/`branch_id`
  are never trusted. Success returns only: `device_id`/`public_uuid`, `tenant_code`,
  `branch_id`, `branch_name`, `device_status = pending_bootstrap`, `cloud_base_url`,
  `paired_at`. Never returns DB/master credentials, tenant DB name, a permanent token, or
  catalog/financial/other-tenant data.
- `GET /api/edge/device/me` — behind `AuthenticateEdgeDevice` (+ `throttle:60,1`). A minimal
  proof that the client-held secret authenticates. **NOT** heartbeat/bootstrap/sync/lease.

## 5. Entitlement & rollout re-check at EXCHANGE time
Generation (tenant Owner action) requires: authenticated Owner + the generate permission +
`offline_edge` entitlement + active subscription + `EDGE_FEATURE_ENABLED=true` + eligible
branch (cloud/inactive or local_edge/pending) + available device slot + no active device.
The **public exchange re-checks** module + subscription + `offline_edge` + rollout flag +
branch eligibility + device slot + code validity — it never trusts the state captured when
the code was generated (proven: disabling entitlement after generation → 403
`EDGE_ENTITLEMENT_REQUIRED` at exchange).

## 6. Device / branch limit policy
- **Hard invariant (DB-enforced):** max ONE active device per branch — unique
  (tenant_id, branch_id, active_slot). Proven: a second active insert is rejected by the
  unique index.
- **Tenant-wide cap:** read from the `offline_edge` plan-module limits key
  **`max_active_edge_devices`** (consistent with the existing numeric-limit convention used
  by `multi_branch`/`branch_limit`). When unset/blank/non-positive it **fails closed** to a
  safe default of **1** (never silently unlimited — this is documented and deliberate; raise
  it via plan-module limits when licensing more devices). Pairing fails
  `EDGE_DEVICE_LIMIT_REACHED` **before** device creation when the cap is reached.

## 7. Branch lifecycle interaction (via BranchOperatingModeService only)
- First live code on a `cloud/inactive` branch → `inactive → pending` (pending never blocks
  cloud sales). Pair exchange keeps it **pending** — nothing in this sprint sets `active`.
- Cancelling the only code with no paired device → `pending → inactive`.
- Revocation: a **pre-activation** (pending) device with nothing else pending →
  `pending → inactive`; a future **active** device revoked → `active → suspended` (never a
  direct `active → inactive`). All transitions go through
  `BranchOperatingModeService::transition()` (the code-enforced matrix), never a raw jump.

## 8. Revocation is exempt from entitlement/flag (security control)
An Owner must be able to revoke a device even after `offline_edge` was removed, the
subscription lapsed, or `EDGE_FEATURE_ENABLED` was turned off. So the revoke route sits
**outside** the subscription/module gate — it requires only authentication, the
`tenant.offline-edge.devices.revoke` permission, and device ownership. Revoke sets
`status=revoked`, clears `active_slot` (immediately killing device auth and freeing the
slot), stamps `revoked_at/by/reason`, audits, and handles the branch lifecycle. Proven:
revoke succeeds with both entitlement and the flag OFF, and the secret stops authenticating
immediately (device/me → 401).

## 9. Device authentication middleware
`AuthenticateEdgeDevice`: reads `X-Edge-Device-ID` + bearer, loads the **active**
(active_slot=1, not revoked) device by public UUID, constant-time compares
`sha256(bearer)` with the stored hash, rejects revoked/unknown/wrong-secret with 401,
throttles the `last_authenticated_at` write (≤ once/60s), and attaches the device to the
request. Request-supplied tenant/branch fields are ignored (proven: spoofed query params
have no effect).

## 10. Permissions & route catalog
New Owner permissions: `tenant.offline-edge.pairing-code.generate`,
`tenant.offline-edge.pairing-code.cancel`, `tenant.offline-edge.devices.revoke`. Added to
the `TenantProvisioner` Owner grant list and propagated to existing Owners by the standard
`deploy.sh` step-5 mechanism (proven 21/21 ×2 idempotent, Owner-only, non-Owner roles
untouched). RouteCatalog rows are `is_published=0` — same conclusion as
ENTITLEMENT-HARDEN-1: it doesn't affect middleware/`route.permission`/the deploy grant, only
the tenant Role-editor's assignable list; left unpublished pre-rollout. The central
`edge.api.*` routes are unauthenticated/device-authenticated and require no Spatie
permission.

## 11. QA summary
Service/security 27/27 (generate hashing+TTL+attempts+lifecycle; exchange single-device +
pending + used/invalid/expired/exhausted + device-limit + DB unique guard; revoke
lifecycle + entitlement/flag-independence). Real HTTP: pair 200, device/me 200, wrong/
unknown/revoked → 401, tenant/branch spoof ignored, idempotent replay = same device,
exchange-time entitlement → 403, 4-parallel-same-code → exactly one device (three
`PAIRING_CODE_USED`). Permission propagation 21/21 ×2. 7/7 tenants tb=0/neg=0/dept=0, no
branch left pending/active, no device/code left, `offline_edge` on 0 plans, flag off,
installer unavailable, manufacturing regression green, Print Agent + cloud POS unaffected.

## 12. Security limitations
- The device secret lives on a customer-controlled machine; revocation + short-lived
  authenticated calls limit exposure, but a compromised box can use its secret until
  revoked (revocation is immediate on next auth).
- Single-process `php artisan serve` cannot prove true multi-worker DB concurrency; the
  DB unique index + `lockForUpdate` are the real guards. A **production-like multi-worker
  concurrency certification** remains a go-live gate (shared with the sale-idempotency gate).

## 13. HARDEN-1 (2026-07-30) — durability, race-safety, recoverability, manageability
Closed the review blockers:

1. **Failure-state now persists.** `exchange()` was mutating (`attempts++`, expired/exhausted
   burn) and then throwing *inside* the master transaction — Laravel rolled those writes
   back, so "5 attempts" was not actually enforced. Refactored to an **outcome pattern**:
   the transaction returns `['fail'=>CODE]` (after committing the mutation) or `['ok'=>meta]`,
   and the structured `EdgePairingException` is thrown **after commit**. Proven: attempts
   persist across failed exchanges; exhaustion and expiry permanently null `active_slot`.
2. **DB-enforced live-code uniqueness.** New `unique(code_hash, active_slot)` on
   `edge_pairing_codes` — two branches/tenants can never hold the same live six-digit code.
   Generation retries on the collision (bounded); no raw `UniqueConstraintViolationException`
   escapes. Proven at the DB level.
3. **Race-safe tenant device cap.** `generateCode()` and `exchange()` now
   `Tenant::whereKey($id)->lockForUpdate()` before counting/creating, so two DIFFERENT
   branches of one tenant cannot both consume the last licensed slot. **Certified with two
   independent PHP server processes** (see §11 update): two branches + `limit=1` +
   simultaneous exchange → exactly one active device, the other `EDGE_DEVICE_LIMIT_REACHED`
   (409), zero 500s.
4. **Re-pair after revoke.** Replaced `unique(installation_uuid)` with
   `unique(installation_uuid, active_slot)`: one ACTIVE device per installation globally,
   revoked rows retained (active_slot NULL), and the same installation can pair again after
   revoke. An installation already active elsewhere → `PAIRING_DEVICE_CONFLICT` (409).
5. **No plaintext code in session.** The one-time code is `Crypt::encryptString`-encrypted
   before it enters session flash (the file/database session store is not encrypted at rest),
   decrypted once for the immediate render, and never re-shown on refresh. The stored flash
   value is always ciphertext (deterministically verified over 200 codes); it never equals or
   identifiably contains the plaintext.
6. **Security controls are always reachable.** Revoke + cancel + a new **`/settings/offline-edge/security`**
   view sit OUTSIDE the subscription/module gate (permission + ownership only), so an Owner
   can revoke a device / cancel a code even after `offline_edge` is removed, the subscription
   lapses, or `EDGE_FEATURE_ENABLED` is off. The sidebar surfaces an **Edge Security** link
   whenever a live device/code exists even when the setup entry is hidden. Generation +
   download stay entitlement+rollout gated; the security view never renders them. New
   permission `tenant.offline-edge.security` (propagated to Owners via deploy.sh step 5).
7. **Cross-DB saga (no false atomicity).** Master and tenant DBs do not share a transaction.
   Ordered as a saga: **generate** creates the code in master, then transitions the branch
   `inactive→pending` in the tenant DB — if that fails, it **compensates** by burning the
   new code. **exchange** requires the branch already `pending` and performs NO tenant-DB
   branch write. **cancel/revoke** commit the master security mutation first, then reconcile
   the branch **idempotently**; if reconciliation fails, the security action stays committed
   and a `edge.branch.reconciliation_required` audit line is logged — never a 500. Retrying
   cancel/revoke re-attempts reconciliation (proven idempotent).
8. **Error-status consistency.** `EDGE_DEVICE_LIMIT_REACHED` is `409` in code, docs and QA
   (verified via `render()`), alongside INVALID/EXPIRED/EXHAUSTED=422, USED/CONFLICT=409,
   ENTITLEMENT=403, throttle=429, device-auth=401.

### Honesty corrections (superseding earlier claims)
- **max_attempts is not brute-force protection for unknown codes.** The exchange resolves a
  code row by exact hash; a totally wrong six-digit guess matches no row and cannot increment
  any counter. `max_attempts` only bounds failures where a **real** code row was resolved
  (e.g. repeated entitlement/branch/limit failures). **Unknown-code guessing is bounded by
  the IP throttle** (`throttle:5,1`) on the exchange endpoint. The six-digit-only installer UX
  is unchanged.
- **Concurrency is now certified across real processes.** The earlier "4 parallel curl"
  against a single `php artisan serve` proved only the client contract. HARDEN-1 re-ran the
  matrix against **two independent PHP server processes (ports 8899 + 8900) on the same DBs**:
  (A) same code+installation+secret → both 200, same device, 1 row, 0×500; (B) same code +
  different installations → one 200 / one 409 USED, 1 row; (C) two branches + tenant limit 1 →
  one 200 / one 409 LIMIT, exactly 1 active device; (D) two simultaneous generates → exactly 1
  live code, 0×500.

HARDEN-1 QA: 14/14 durability/race + 27/27 base (no regression) + 2-process concurrency A–D
+ session-payload encryption (200 codes) + security-page/revoke reachable with entitlement
off; 7/7 tenants tb=0/neg=0/dept=0, no branch/device/code left, `offline_edge` on 0 plans,
flag off, manufacturing green, Print Agent + POS unaffected.

## 14. HARDEN-2 (2026-07-30) — code-generation correctness + durable recovery
1. **No silent code replacement.** Generation previously auto-cancelled the branch's existing
   live code and issued a new one — under concurrency the first requester could be shown a
   code that a second request had already invalidated. Now, **inside the tenant-row lock**,
   an existing live *unexpired* code makes generation fail with **409 `PAIRING_CODE_ALREADY_ACTIVE`**;
   the owner must **Cancel** first. An already-*expired* live-slot code is burned so the owner
   is never stuck. The UI shows **Cancel code** while a live code exists and never a "New code"
   button. Certified with two independent server processes: simultaneous generate for one
   branch → exactly **one 302 success + one 409 ALREADY_ACTIVE**, one live code.
2. **One-time code response hardened.** The encrypted flash is consumed with
   `session()->pull()` (read-and-forget), and the page that renders the plaintext code sends
   `Cache-Control: no-store, private`, `Pragma: no-cache`, `Expires: 0` so a Back/bfcache/proxy
   can never re-surface it. Verified over HTTP: headers present, code shown once, absent on
   refresh; the code stays encrypted in server-side session storage.
3. **Migration rollback safety.** `2026_07_30_000002` `down()` no longer blindly recreates
   `unique(installation_uuid)` — with revoked-device history that would fail or force deleting
   history. It drops the composite indexes (safe) and restores the single-column unique **only
   when no duplicate installation_uuid exists**, otherwise throws a clear **irreversible-migration**
   error rather than destroying history. Operational note: once any device has been revoked,
   this migration is effectively irreversible without deliberately archiving history first.
4. **Durable reconciliation marker.** New master table `edge_reconciliation_markers`
   (`tenant_id, branch_id, operation, status, attempts, last_error, resolved_at`; one per
   branch). When a branch lifecycle reconciliation fails **after** a committed master security
   mutation (cancel/revoke), a marker is persisted (`pending`, attempts++, last_error) instead
   of only logging — so the pending work survives a crash/restart. A later cancel/revoke retry
   re-runs reconciliation and **deletes the marker on success**. No expected reconciliation
   failure returns a 500 (proven: forced-failure stub → revoke succeeds, device revoked, marker
   persisted; real retry → branch suspended, marker resolved). This is a minimal durable marker,
   **not** the full reconciliation engine (a future job can drain pending markers).

HARDEN-2 QA: 11/11 new + base 27/27 + HARDEN-1 14/14 (no regression) + 2-process generate
(one 302 / one 409) + no-store headers + marker persist/resolve; 7/7 tenants tb=0/neg=0/dept=0,
no branch/device/code/marker residue, `offline_edge` on 0 plans, flag off, manufacturing green.

## 15. Next
`BRANCH-BOOTSTRAP-SNAPSHOT-1` — the paired device downloads its branch catalog/settings/
users/tables/printers (still no activation until readiness).
