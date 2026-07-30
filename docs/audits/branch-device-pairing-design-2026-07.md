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

## 13. Next
`BRANCH-BOOTSTRAP-SNAPSHOT-1` — the paired device downloads its branch catalog/settings/
users/tables/printers (still no activation until readiness).
