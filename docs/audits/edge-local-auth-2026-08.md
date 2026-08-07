# EDGE-LOCAL-AUTH-1 — device-bound offline user enrollment & local authentication

Status: **built + MySQL/artifact-proven on `feat/14d-2-plan-upgrade-requests` (deploy cloud-safe).**
First real Branch Server local authentication layer. **No login-gated POS, no sales/print/sync, no
Local Mode.** `activation_ready` stays false; `operational_stock` stays not_ready; `/pos` stays 404.
Production remains `APP_ROLE=cloud` / `EDGE_FEATURE_ENABLED=false`.

## Hard security contract (LOCKED)
The Branch Server NEVER receives `users.password`, `remember_token`, manager PIN hashes, the Cloud
`APP_KEY`, session secrets or reset tokens. The bootstrap does not ship them (importer `SECRET_FIELDS`
blocklist). Edge login uses an **Edge-specific credential** with the **same** `Tenant\User` identity,
roles, permissions, branch assignments and order types — never the Cloud password. `auth('tenant')->
attempt()` against `users.password` is NEVER used. (The old PREFLIGHT "export bcrypt/PIN hashes"
recommendation is superseded and rejected.)

## Chosen strategy (reuse, not reinvent)
- **Guard/session:** the existing `tenant` session guard. Edge login verifies the Edge credential
  explicitly (`EdgeLocalAuthService`) then `auth('tenant')->login($user)` + `session()->regenerate()`.
  No new guard, no new role system.
- **Permissions:** the bootstrap exports each user's *effective* permission names + roles. The importer
  reconstructs `permissions` + grants them **directly** (`model_has_permissions`) and attaches roles
  (`model_has_roles`), so `$user->can()`/`hasRole()` resolve LOCALLY exactly as the auth gate needs.
  Honest denormalisation: `role_has_permissions` is empty locally (roles carry no permissions), but the
  user-level `can()` result is identical. On the appliance the `tenant` connection (edge-local) is the
  default, so Spatie's `Permission`/`Role` models resolve there.
- **`users.password` / `email`:** made nullable on the edge-local DB (edge migration) and imported NULL
  — the appliance holds no Cloud credential and identifies users by `employee_code`.

## Credential storage
`edge_local_user_credentials` (edge migration path, never a Cloud tenant DB): `user_id` (unique),
`branch_id`, `activation_epoch`, `credential_hash` (**Argon2id**, `password_hash(PASSWORD_ARGON2ID)`),
`credential_type` (password|pin), `credential_version`, `status`, `failed_attempts`, `locked_until`,
`enrolled_at`, `last_authenticated_at`. No default/weak credential is permitted (`1234`/`0000`/
`password` etc. rejected; password ≥ 8; PIN ≥ 6 digits, not all-same).

## Enrollment assertion (breaks the circular first-login)
- **Crypto: Ed25519 via ext-sodium** (`EdgeEnrollmentCrypto`). The **Cloud holds the private signing
  key ONLY** (`EDGE_ENROLLMENT_SIGNING_KEY`); a **Branch Server holds the public key ONLY**
  (`EDGE_ENROLLMENT_PUBLIC_KEY`). No shared HMAC secret, no reuse of `APP_KEY`/DB/device token. Fails
  closed if sodium or the key is absent. Confirmed present on prod PHP 8.2.29 + dev.
- **Cloud issuer** (`EdgeEnrollmentIssuer`, route `tenant.offline-edge.enrollment.issue`,
  permission-gated == route name, cloud-only): authorizes device-is-current-active, epoch-current,
  entitlement, target-user-active + branch-authorized; signs `{version, purpose, tenant_id, tenant_code,
  branch_id, device_public_uuid, activation_epoch, user_id, jti(ULID), issuer_user_id, issued_at,
  expires_at}` (short TTL). Cloud-side audit logs issuer/target/tenant/branch/device/epoch/jti (never
  the credential/assertion body).
- **Edge consumer** (`EdgeEnrollmentConsumer`, command `edge:local:enroll`): verifies signature (public
  key) + purpose/version + expiry + binding (tenant/branch/device/epoch **must** match `EdgeBranch
  Context`) + target user exists/active/branch-authorized; consumes the `jti` **atomically** (UNIQUE +
  transaction → concurrent double-submit enrolls once); stores the Argon2id verifier bound to the
  current epoch. A second valid assertion ROTATES (version bump; old hash unusable). No master DB call.

## Local auth service
`EdgeLocalAuthService`: branch_server + bootstrap binding required; user active + branch-authorized
(`EdgeUserAuthz`, the strict "default branch OR active pivot" rule); credential epoch must equal the
bound epoch (stale generation rejected); per-user lockout (5 attempts / 300s); generic error messages.
Manager re-auth verifies the manager's OWN Edge credential and requires the permission — the approval
identity is the manager, not the requesting cashier.

## Routes / census
Branch server registers ONLY: `edge/local/{health,ready,build-info,login,login(post),logout,status}`
(+ framework `up`) — all on the explicit route allowlist; `edge.auth` guards `/status`; **`/pos` stays
404** and no cloud admin/billing/POS/pairing routes exist. On CLOUD the branch-local auth routes are
absent; only the permission-gated `enrollment.issue` route exists.

## Readiness (honest)
`local_auth`: `not_ready` (no auth schema) | `needs_enrollment` (runtime ok, no eligible credential) |
`ready` (≥1 active, epoch-current, branch-authorized credential). Foundation `/edge/local/ready`=200
still means FOUNDATION readiness ONLY — `activation_ready` stays **false** (the selling gate) and
`operational_stock`/`local_pos`/`local_print`/`sync` stay not-ready/not-implemented.

## Audit
`edge_auth_audit` (non-secret): enrollment success/reject, login success/failure, lockout, logout,
credential rotation, manager re-auth success/failure — identifiers + event metadata only; never a raw
password/PIN, hash, key, token or full assertion.

## Epoch / device replacement
Credentials are bound to the appliance generation. An epoch-N credential does not authenticate under an
N+1 binding; an assertion for epoch N is rejected once the binding is N+1; an assertion for device A is
rejected on device B. (Full compromise of a permanently-offline cloned box needs the later entitlement
lease / replacement fencing — not claimed here.)

## Proof
MySQL auth matrix **15/15** (A–X incl. a genuine **2-process** concurrent-jti race → exactly one
succeeds; master pointed at a nonexistent DB throughout; `users.password` never the verifier; roles/
permissions/order-types resolve post-login). Fast suite green. **Physical artifact login proof**
(committed release artifact, master unavailable): db-init → import → `edge:local:enroll` → HTTP wrong
cloud-password rejected, correct Edge credential logs in, `/status` 200 with roles+permissions+order
types, logout invalidates the session, `/pos` 404, `/api/edge/pair` 404.

## Closure hardening (final)
- **Lockout concurrency:** the failed-attempt counter + lockout transition run in a `tenant`
  transaction with `lockForUpdate` on the credential row — simultaneous wrong attempts serialise (no
  lost increment); the counter/lockout commit even on a failed attempt (throw happens after commit).
  Proven with a genuine two-process race (`edge_login_worker.php`): two concurrent failures from
  MAX−2 leave the account locked.
- **Durable vs best-effort audit:** enrollment/rotation and the lockout transition use
  `EdgeAuthAudit::recordDurable()` INSIDE the state-change transaction (state + audit commit
  coherently — proven: dropping `edge_auth_audit` rolls the whole enrollment back). Ordinary
  rejected-login / logout audit is explicitly best-effort (`record()`, swallows) so a logging failure
  can never become an auth-availability DoS.
- **HTTP throttle:** the local login POST is throttled `10/min` keyed by `employee_code + IP`
  (`edge-login` limiter) — even an unknown employee_code is bounded; responses stay generic (no
  existence leak). Manager re-auth is service-level this sprint and is protected by the per-user
  lockout; an HTTP manager endpoint (future POS work) will carry the same throttle.
- **Session/cache topology (chosen contract):** the appliance uses **file** sessions + **file** cache —
  LOCAL only, never master, and avoids the StartSession-before-tenant-binding ordering problem. Enforced
  by the boot guard (a connection-backed `SESSION_DRIVER` on a branch_server is a boot problem).
  Persistence in `storage/framework/sessions`, restart-durable, file-locked multi-worker-safe. The
  physical artifact proof uses this real driver.
- **Secure cookie:** `HttpOnly` + `SameSite=lax` always; production branch_server sets
  `SESSION_SECURE_COOKIE=true` (TLS-managed terminals) — readiness reports `secure_cookie`/`session_local`;
  the plain-HTTP localhost proof explicitly overrides secure=false. Proven Secure+HttpOnly+SameSite in
  an HTTP test.
- **ext-sodium is a branch runtime requirement:** readiness reports `crypto_ready` +
  `enrollment_public_key_ready`; `local_auth` returns `not_ready` (never needs_enrollment/ready) if
  sodium or the public key is absent — enrollment can't lie about being possible. (Cloud prod PHP 8.2.29
  already has sodium; the appliance installer must declare ext-sodium required.)
- **Employee-code eligibility:** an Edge-login user MUST have a non-empty `employee_code`; the Cloud
  issuer and the Edge consumer both refuse an ineligible target user, and readiness counts only
  login-eligible active users.
- **Assertion time contract:** in addition to `expires_at≥now`, the consumer rejects `issued_at` in the
  future (>60s skew), `expires_at ≤ issued_at`, and lifetime > configured TTL + skew — a malformed
  long-lived Cloud-signed assertion cannot become effectively permanent.
- **Cloud enrollment authorization:** the issue route is `route.permission`-gated (permission == route
  name); Owner (granted the permission via the deploy's Owner-grant-all) passes, a cashier without it
  is 403, unauthenticated is redirected — proven.

## Final-proof closure (EDGE-LOCAL-AUTH-FINAL-PROOF-1)
- **Canonical-HEAD artifact provenance:** the physical login/manager proof is re-run from a release
  artifact whose manifest `git_commit` equals the accepted canonical HEAD (the earlier proof predated the
  squash; provenance is a locked contract, so it is re-established against the final commit).
- **Real-HTTP Cloud authorization proof** (`EdgeEnrollmentAuthzHttpMySqlTest`): the enrollment-issue
  authorization now runs through the actual stack — a live tenant + active domain seeded in master so
  `IdentifyTenant → auth:tenant → route.permission` all execute for real on the tenant host
  (`{sub}.{tenant_base_domain}`, derived from config; only CSRF is bypassed). Proven: the route exists and
  carries `route.permission`; unauthenticated → 401; cashier without the permission → 403; authorized
  Owner passes authorization and reaches the controller, which fails CLOSED with the controlled 422 (no
  active device / no signing key) — a bare 404 is explicitly rejected as an Owner "pass". The test fails
  if the route disappears, `route.permission` is removed, or the tenant guard wiring breaks; master test
  rows are torn down deterministically. No signing key is inserted.
- **Production Owner-permission census** (read-only): 7/7 Cloud tenants have `tenant.offline-edge.
  enrollment.issue` granted to the Owner role after routes-sync/Owner-grant-all — no remediation needed.
- **Environment note:** during this closure the dev box's InnoDB DDL slowed sharply while two release
  artifacts were being copied in parallel; classified as ENVIRONMENT ISSUE / local I/O slowdown suspected
  (not a proven root cause) — it cleared once the parallel copies stopped and the full MySQL suite ran in
  ~3.4 min. No code implicated.

## Release gates still open (unchanged)
True client-appliance release still needs `composer install --no-dev` + stronger source minimisation;
installer / cert binding / CA distribution / update signing / physical printer certification remain
later gates. Credential-reset requires a NEW Cloud assertion (no offline "forgot password" bypass);
immediate offline user revocation is NOT claimed (needs the future config-refresh / entitlement lease).

Next: **EDGE-IDENTITY-1** (lock sale/payment/KOT/shift/session UUID identities across Cloud+Edge) —
NOT POS yet.
