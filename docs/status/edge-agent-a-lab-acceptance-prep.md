# Agent A — LAB acceptance preparation: browsable LAB Cloud (Decision A) + disposable LAB admin / manager PIN (Decision B), 30 Oct 2026

Owner directive (30 Oct 2026): Decisions A and B are approved for **disposable validation and preparation only**. Everything below ran on the
DISPOSABLE dev Cloud clone (`pos_devonline_master_edge` / `pos_devonline_tenant_edge`, served on a fresh port **9705** from the worktree at head
`a6113772`). The real LAB (Cloud 9701, appliance 8443/8081/8090/8091, databases `pos_lab_*` / `bingoo_edge_lab_local`), production and the live tenants
were **not touched** (`REAL_LAB_MUTATED=NO`). Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\agent-a\` (`routing-api.txt`, `guard-refusals.txt`,
`sessions.txt`, `pids.json`, `w01\report.json` + screenshots, `cashier-flow.json`, `admin\*.json|txt`). Nothing in the evidence or in this document
contains a secret (passwords, PINs, keys are read from files into process memory only).

Code read for the reviews: `routes/tenant.php:111` (tenant mount `Route::domain('{subdomain}.'.config('tenancy.tenant_base_domain'))`),
`routes/edge.php:13` (device API mount `Route::domain(config('tenancy.central_domain'))` + `central.only`), `config/tenancy.php`, `config/session.php`,
`app/Http/Middleware/{IdentifyTenant,TenantOnly,CentralOnly,EnsureRoutePermission}.php`, `bootstrap/app.php` (CSRF exclusion `api/edge/*`; middleware
priority `IdentifyTenant` before `StartSession`), `app/Http/Controllers/Auth/AuthController.php::login`, `config/auth.php`,
`app/Http/Controllers/Tenant/TenantUserController.php` (`index/show/managerPinForm/managerPinStore`), `app/Models/Tenant/ManagerPin.php`,
`database/migrations/tenant/2026_06_05_000001_create_sales_controls_tables.php` (`manager_pins`), `resources/views/tenant/users/*.blade.php`,
`scripts\Start-LabCloud.ps1`, `scripts\Start-DevCloud.ps1`, `scripts\Create-LabApprover.php`, `scripts\lab-cloud.php` (init), `scripts\devclone-cloud.php`,
`tools/edge-dev-instance/router.php`, `tools/edge-browser-proof/shared-pos-workflows.mjs` (header + W01).

## 1. Decision A — browsable LAB Cloud

### 1.1 Findings from the code that shape the configuration

| # | finding | consequence |
|---|---|---|
| F1 | Tenant web routes mount on `'{subdomain}.' . TENANT_BASE_DOMAIN`; the device API mounts on `CENTRAL_DOMAIN`. Two independent config values. | The corrected §9A plan holds: change only `TENANT_BASE_DOMAIN`; `CENTRAL_DOMAIN` stays `127.0.0.1` so the real appliance's `EDGE_*_URL = http://127.0.0.1:9701/api/edge/…` (Host `127.0.0.1`) keeps resolving. |
| F2 | `IdentifyTenant` resolves a non-central host through the master table `tenant_domains` (`domain` = request host, `status = active`); no row → `404 Tenant not found or inactive`. | The browser host `edgehomelab.localhost` must exist as a `tenant_domains` row of tenant `edgehomelab` in the master DB. The clone master has it (`id 1, edgehomelab.localhost, kind app, is_primary 1, active`). **`lab-cloud.php init` never inserts a domain row**, and the 0.8.0 diagnosis recorded `Host: edgehomelab.localhost → 404` on the LAB Cloud (`edge-0.8.0-lab-acceptance-standby.md:19`), so the real LAB master most likely has NO row: the real switch needs one master row in addition to the env change. Prepared: `scripts\Ensure-LabTenantDomain.php` (same LAB-only PDO mechanics; not approved for the LAB yet). Verify first with `SELECT id, domain, kind, status FROM pos_lab_master_edge.tenant_domains;` (owner, read-only). |
| F3 | `php -S … public/index.php` (today's `Start-LabCloud.ps1`) sends every request, including `/assets/*`, into Laravel; `tools/edge-dev-instance/router.php` exists exactly so that `php -S` serves the theme's static files (its header says the theme never loads otherwise). | A browsable instance must use the router script; the launcher does. The API/sync behaviour is unchanged (non-files still reach `public/index.php`). |
| F4 | `config/session.php`: `files => storage_path('framework/sessions')`, cookie `laravel-session` (APP_NAME unset), `SESSION_DOMAIN` unset → host-only cookie, SameSite lax, HttpOnly. | File sessions live inside the SERVED tree. On the real LAB that tree is a private git-archive export; on the worktree it is shared with the 9704 instance, so the disposable run removed only its own files (identified by content `:9705`). |
| F5 | CSRF: `ValidateCsrfToken` with `api/edge/*` excluded (`bootstrap/app.php`); `IdentifyTenant` is ordered before `StartSession`. | A POST to `/login` without a token is 419 on the tenant host; the device API never needs a token. |
| F6 | `EnsureRoutePermission` allows `tenant.login*`, `tenant.logout`, `tenant.password*` unauthenticated; every other named tenant route is gated by its own name. | Unchanged by the switch. |

### 1.2 Before / after configuration (what the launcher sets)

| key | real LAB today (`Start-LabCloud.ps1`) | browsable (`Start-LabCloud-Browsable.ps1`, validated on 9705) | why |
|---|---|---|---|
| `CENTRAL_DOMAIN` | `127.0.0.1` | **`127.0.0.1` (unchanged)** | device API host of the real appliance |
| `TENANT_BASE_DOMAIN` | `127.0.0.1` | **`localhost`** | tenant host becomes `edgehomelab.localhost` (loopback by RFC 6761 in Chromium/Edge, Windows resolver) |
| `APP_URL` | `http://127.0.0.1:9701` | `http://edgehomelab.localhost:<port>` | generated tenant links |
| `SESSION_DRIVER` | `array` (no login can survive a request) | **`file`** → `<served tree>\storage\framework\sessions` | login + CSRF token persistence |
| `SESSION_DOMAIN` / same-site / http-only / secure | unset / lax / true / unset | unchanged (explicitly unset) | host-only cookie, plain http on loopback |
| `CACHE_STORE`, `QUEUE_CONNECTION`, `MAIL_MAILER`, `LOG_CHANNEL`, `APP_ENV`, `APP_DEBUG`, `EDGE_*` flags, DB env, secrets handling | as today | **identical** (secrets read from `secrets\*` into `$env:`, never printed; scrubbed from the launching shell after start) | — |
| listener | `php -S 127.0.0.1:9701` | `php -S 127.0.0.1:<port>` | **never 0.0.0.0**; LAN unreachable by design |
| router script | `public/index.php` | `tools/edge-dev-instance/router.php` | F3 |
| master `tenant_domains` | (expected) no `edgehomelab.localhost` row | needs `edgehomelab.localhost` (kind app, active) | F2 |
| pid / log | `cloud\cloud.pid`, `cloud\cloud.log` | `cloud\cloud-browsable-<port>.pid`, `cloud\cloud-browsable-<port>.log` | never touches the real LAB's pid file |
| guard | — | runs only with `-Disposable -Port -MasterDb -TenantDb`; refuses any name containing `lab_` that is not a `devonline` clone, any non-`devonline` name, port 9701 and every reserved port; verifies (read-only) that the master maps `edgehomelab` to `-TenantDb` | preparation only; the real switch needs owner approval and a deliberate guard change |

### 1.3 Checks on the disposable clone (port 9705) — commands and results

Start (09:35:20 UTC, after the shared-host quiet window; the session dir was snapshotted first — 1716 files):
`.\Start-LabCloud-Browsable.ps1 -Disposable -Port 9705 -MasterDb pos_devonline_master_edge -TenantDb pos_devonline_tenant_edge` → `pid=424428`,
listener `127.0.0.1:9705` (recorded in `pids.json`). Session cookie name on this build: `bingoo-pos-session` (+ `XSRF-TOKEN`).

**Guard refusals** (`guard-refusals.txt`, nothing started): no `-Disposable` → refused; missing `-Port/-MasterDb/-TenantDb` → refused;
`pos_lab_master_edge` → refused ("real LAB database name"); port 9701 → refused; `pos_master` → refused (not devonline); a `-TenantDb` that the
master does not map → refused after the read-only pre-flight.

**(a) Online POS login — harness W01** (`w01\report.json`, `W01-login-and-pos-renders-01-pos-main.png`):
`node shared-pos-workflows.mjs --mode online --base-url http://edgehomelab.localhost:9705 --user lab.cashier@edgehomelab.test --only W01 --out …\agent-a\w01 --slow 2`
(POS_SHOT_PASS from `secrets\cashier.pass` — the clone cashier's Cloud password is the LAB one; `devclone-cashier.pass` is refused by `/login`) →
**W01 PASS** (login POST 302 → `/dashboard`, `/pos` rendered: `window.POS_RUNTIME` present, product grid, status slot, terminal badge).
`http_errors`: one `GET /dashboard 403` — the LAB cashier has no `tenant.dashboard` (as on the real LAB; the harness goes to `/pos` itself).
`console_errors` 14 = that 403 + 13 "Failed to load resource 404" sub-resources referenced from CSS (`asset-404-check.txt`: all 38 assets linked from
`/pos` and `/login` HTML answer 2xx; the 404s are CSS `url()` fonts/images, the same on every `php -S` instance, not pages or API).

**(b) Tenant routing** (`routing-api.txt`, all with `curl -s -o NUL -w "%{http_code}"`; `--resolve <host>:9705:127.0.0.1` for the `.localhost` names):

| request | result | meaning |
|---|---|---|
| `GET http://edgehomelab.localhost:9705/login` | **200** | tenant host resolved through `tenant_domains` → tenant login page |
| `GET http://other.localhost:9705/login` | **404** | no such tenant (`IdentifyTenant` abort 404) |
| `GET http://127.0.0.1:9705/login` | **200** | the central domain's own login page (`routes/central.php`), not the tenant's |
| `GET http://localhost:9705/login` | **404** | neither central (`127.0.0.1`) nor a tenant domain |
| `GET http://127.0.0.1:9705/pos` | **404** | no tenant routes on the central domain |
| `GET http://edgehomelab.localhost:9705/pos` (no cookie) | **302 → /login** | tenant route, guest redirect |
| `GET http://127.0.0.1:9705/up` | **200** | health |

**(c) Appliance device API stays on the central domain** (`-X POST -H "Accept: application/json"`, no credentials):

| path (`routes/edge.php`) | Host `127.0.0.1` | Host `localhost` | Host `edgehomelab.localhost` |
|---|---|---|---|
| `/api/edge/authority/heartbeat` | **401** (route resolved; `edge.device.auth` refuses) | **404** | **404** |
| `/api/edge/sync/sales` | **401** | **404** | **404** |
| `/api/edge/config/refresh` (the standby config-refresh route; there is no `standby/config-refresh` path) | **401** | **404** | **404** |
| `/api/edge/authority/handback` | **401** | **404** | **404** |
| `/api/edge/sync/reconcile` | **401** | **404** | **404** |
| `/api/edge/pair` (public, throttled) | **422** (validation; route resolved) | **404** | **404** |

→ the central binding is exactly `127.0.0.1`: the real appliance's URLs (`http://127.0.0.1:9701/api/edge/…`) keep working after the switch, and neither
`localhost` nor the tenant host exposes the device API.

**(d) Authentication + CSRF** (`cashier-flow.json`, `admin\02-admin-flow.json`; scripted with PHP-curl, secrets in memory only):
`POST /login` without `_token` → **419** (Page Expired); `POST /login` with token + wrong password → **302 back to /login**, then `GET /dashboard` →
**302 → /login** (no session login); correct password + token → **302 → /dashboard** (cashier and admin); the harness login (with token) → PASS (a).
`POST /api/edge/authority/heartbeat` without a token → 401, not 419 (CSRF exclusion `api/edge/*` intact).

**(e) Sessions**: with the cookie, `GET /pos` → **200** twice in a row (same session), `GET /dashboard` 403 for the cashier (permission, not auth),
`POST /logout` (token from the post-login page; the token rotates at login) → 302 → /login, then `GET /pos` → 302 → /login. File sessions appeared in
`D:\laragon2\www\pos-saas-edge\storage\framework\sessions` (`sessions.txt`: 9 new files whose content references `:9705`, written 14:35–14:39 local;
8 other new files belonged to the 9704 instance and were left alone).

**(f) Revert**: `… -Stop` → `BROWSABLE_CLOUD stopped (pid 424428)`; process gone; `Get-NetTCPConnection -State Listen -LocalPort 9705` → 0; pid file
removed; the 9 session files removed (0 remaining that reference `:9705`); listeners 9701/9704/8081/8090/8091/8095/8096/8443 unchanged (`pids.json`).

**(g)** see 1.5.

`LAB_CLOUD_DISPOSABLE_CONFIG_TEST=PASS` — every check above matched its expectation.

### 1.4 Revert procedure (as executed on the disposable instance)

1. `.\Start-LabCloud-Browsable.ps1 -Disposable -Port 9705 -MasterDb pos_devonline_master_edge -TenantDb pos_devonline_tenant_edge -Stop` — stops **only** the pid in `cloud\cloud-browsable-9705.pid` (`Stop-Process -Id`), deletes the pid file.
2. Remove the file sessions the instance created: on the real LAB the served tree is a private export, so `Remove-Item <export>\storage\framework\sessions\*`; on the shared worktree only the files new since the start snapshot whose content references `:9705` were removed (`sessions.txt`).
3. Confirm the port is free: `Get-NetTCPConnection -State Listen -LocalPort 9705` → nothing.
4. (real LAB only) if the switch is rolled back permanently, the `edgehomelab.localhost` master row may stay (it only matters while `TENANT_BASE_DOMAIN=localhost`) or be removed with `php Ensure-LabTenantDomain.php pos_lab_master_edge edgehomelab.localhost remove`.

### 1.5 Second-laptop note

`*.localhost` names resolve to loopback only on the machine itself (RFC 6761; Chromium/Edge and the Windows resolver never send them to DNS). The
LAB Cloud listens on `127.0.0.1` only. Therefore the **Online POS acceptance runs in the LAB host's own browser** (`http://edgehomelab.localhost:9701`),
exactly like the 9705 validation. From the physical second laptop the LAB Cloud is not reachable at all — by design, and unchanged by this switch;
the second laptop's role stays what §10 of the install record describes (the appliance over the LAN gateway 8443). Reaching the LAB Cloud from the LAN
would require (a) binding `php -S` to a LAN address or `0.0.0.0`, (b) a name the laptop resolves to the LAB host (hosts-file entry `edgehomelab.<name>`
+ `TENANT_BASE_DOMAIN=<name>` + a matching `tenant_domains` row), (c) a firewall rule, and (d) a different `CENTRAL_DOMAIN` story for the appliance —
**none of which is approved**, and all of which contradict the standing "private loopback only" rule.

### 1.6 Real-LAB procedure (prepared, NOT executed; owner approval required for every step)

Preconditions: the appliance is `STANDBY_READY`, no acceptance run in progress, host quiet (no MySQL/browser gates).

1. Read-only check of the master domain table (owner): `mysql --host=127.0.0.1 --user=root -e "SELECT id, tenant_id, domain, kind, status FROM pos_lab_master_edge.tenant_domains;"`.
   If no active `edgehomelab.localhost` row (kind `app`) exists: `php C:\Users\Dell\BingooEdgeLab\scripts\Ensure-LabTenantDomain.php pos_lab_master_edge edgehomelab.localhost` (one master row; prints ids only).
2. Stop the LAB Cloud: `.\Start-LabCloud.ps1 -Stop` (its own pid file). Heartbeats fail for the restart seconds only (lease TTL 120 s).
3. Start browsable from the SAME export the LAB serves today (`cloud\src-<sha>`): either a real-LAB mode of `Start-LabCloud-Browsable.ps1` (after the owner
   approves relaxing its guard to `pos_lab_master_edge` / `pos_lab_tenant_edge` / port 9701 — a code change, deliberately not a switch) or the equivalent four
   env edits in `Start-LabCloud.ps1` (`TENANT_BASE_DOMAIN=localhost`, `SESSION_DRIVER=file`, `APP_URL=http://edgehomelab.localhost:9701`, router
   `tools/edge-dev-instance/router.php`; `CENTRAL_DOMAIN` untouched; listener untouched).
4. Prove the appliance is unaffected within 60 s: the appliance health shows the lease renewing (`last_heartbeat_at` advancing), the authority worker
   ticks `current`; `curl -s -o NUL -w "%{http_code}" -X POST -H "Accept: application/json" http://127.0.0.1:9701/api/edge/authority/heartbeat` → `401` (route resolved, no device credentials), and the same with `-H "Host: localhost"` → `404`.
5. Prove the tenant host: `curl --resolve edgehomelab.localhost:9701:127.0.0.1 -s -o NUL -w "%{http_code}" http://edgehomelab.localhost:9701/login` → `200`; `http://other.localhost:9701/login` → `404`.
6. Online POS acceptance in the LAB host's browser as `lab.cashier@edgehomelab.test` (password from `secrets\cashier.pass`, harness
   `--mode online --base-url http://edgehomelab.localhost:9701`).
7. Revert as in 1.4 (stop, delete `<export>\storage\framework\sessions\*`, start `Start-LabCloud.ps1` as before, re-check heartbeats).

## 2. Decision B — disposable LAB admin + manager PIN

### 2.1 The five route permissions, one by one

`EnsureRoutePermission` (`route.permission` on the tenant group at `routes/tenant.php:203`) lets a request through when the user `can()` the route's
own name (or an alias; none of these five has one), after `auth:tenant` and `tenant.subscription.access`. Views only hide buttons with `@can`; they do
not add gates. The controller methods below have **no** authorization of their own beyond the route permission (checked: `TenantUserController` has
no constructor middleware, no `authorize()`, no `abort(403)`).

| permission | route | what it does | why the admin needs it | what it does NOT grant |
|---|---|---|---|---|
| `tenant.dashboard` | `GET /dashboard` (`DashboardController`) | renders the landing page; `AuthController::login` redirects every login to `/dashboard`, and `/` redirects there | without it every login ends in `403 Permission denied` | the dashboard's detail panels are gated separately by `tenant.dashboard.details` (view line 191: hidden); no POS, no reports, no settings |
| `tenant.users.index` | `GET /users` (`index`) | paginated user list (name, e-mail, code, branch, roles; a search box); calls `TenantSubscriptionAccessService::checkLimit` for the usage badge | to find the approver (user 2) and the link to its page | create/edit/delete/reset/activate buttons are `@can`-hidden and their routes are separate permissions |
| `tenant.users.show` | `GET /users/{user}` (`show`) | one user's page (branches, terminals, roles) | the PIN form link lives here (`@can('tenant.users.manager-pin')`, show.blade:191) and `managerPinStore` redirects back here | edit, reset-password, activate, destroy are separate permissions (`@can`-hidden, 403 on direct access) |
| `tenant.users.manager-pin` | `GET /users/{user}/manager-pin` (`managerPinForm`) | shows the PIN form; `$hasPin` tells whether an active PIN exists | the only UI to set a PIN | reading the PIN is impossible (only `pin_hash` is stored) |
| `tenant.users.manager-pin.store` | `POST /users/{user}/manager-pin` (`managerPinStore`) | validates `pin` (string 4–64) + `pin_confirmation`, `ManagerPin::updateOrCreate(['user_id'], ['pin_hash' => Hash::make(pin), 'is_active' => true])`, flashes "Manager PIN set successfully", redirects to `/users/{user}` | sets the approver's PIN (W07/W08 approvals) | cannot change the user's password/roles/permissions; works on any user id of THIS tenant (the admin could set any user's PIN — acceptable for a disposable LAB tenant with two users; the row's `updated_at` records it) |

Pages render without a further permission: `layouts.app` carries no `@can` abort; the `tenant.subscription.access` middleware passes the LAB tenant (it
already serves the cashier); the `/ajax/*` pickers are authenticated-only. Verified empirically in 2.3 (all five → 200/302 as designed, everything
else → 403).

### 2.2 Mechanism (the owner-approved provisioning path, nothing new)

`Create-LabAdmin.php` / `Disable-LabAdmin.php` mirror `Create-LabApprover.php` exactly: a PDO connection to the named tenant DB (root, password from
`secrets\dbpw.txt`), `password_hash(PASSWORD_BCRYPT)` (what Laravel's `Hash::check` verifies), guard `tenant` rows in `permissions` (created if missing —
the clone's `permissions` table had none of the five) and `model_has_permissions` (`App\Models\Tenant\User`), ids/codes/names printed only. Differences,
all deliberate: the DB name is a **parameter** refused unless it contains `lab` or `devonline`; the password comes from a file (default `secrets\admin.pass`,
refused if missing or < 12 chars; never argv, never printed); after granting, every other direct grant/role of the admin is removed so the account holds
**exactly** the five; an existing row is re-enabled (status active, password re-set from the file) so Disable → Create is repeatable.

**Finding on the disable semantics.** `AuthController::login` uses the plain Eloquent provider (`config/auth.php`): `auth('tenant')->attempt()` does not
look at `users.status`, and nothing else in the app logs an inactive user out. `status = inactive` alone would therefore NOT refuse a login.
`Disable-LabAdmin.php` consequently does three things: `status = 'inactive'` (the visible record), **password replaced by a random discarded bcrypt
hash** (the same technique `Create-LabApprover.php` uses for the approver's Cloud password) + `remember_token = NULL`, and deletion of all
`model_has_permissions` / `model_has_roles` rows (an already-open session is 403 everywhere). The user row and the approver's `manager_pins` row are kept.

### 2.3 Validation on the disposable clone — commands and results

All on `pos_devonline_tenant_edge` through the 9705 instance; evidence under `evidence\agent-a\admin\` (`01-create-labadmin.txt`, `02-admin-flow.json`,
`03-disable-labadmin.txt`, `04-after-disable-sql.txt`, `05-admin-disabled-flow.json`). The CLONE admin password was generated by this agent into
`secrets\devclone-admin.pass` (32 random chars, never printed). State before: users 1 (cashier) and 2 (approver) only; the five permission rows absent;
`manager_pins` row `id 1, user_id 2, is_active 1, updated_at 2026-10-29 09:31:37`.

| step | command / request | result |
|---|---|---|
| create | `php Create-LabAdmin.php pos_devonline_tenant_edge C:\…\secrets\devclone-admin.pass` | `user id 3 (created), LABADM1, default_branch_id 1, status active`; permissions (5) = the five names (rows `id 54–58`, guard tenant, created 09:37:56); other grants removed 0, roles none; exit 0 |
| CSRF | `POST /login` without `_token` | **419** |
| wrong password | `POST /login` + token + wrong password; then `GET /dashboard` | **302 → /login**; **302 → /login** (not logged in) |
| login | `POST /login` + token + the file password | **302 → /dashboard** |
| `tenant.dashboard` | `GET /dashboard` | **200** |
| `tenant.users.index` | `GET /users` | **200** |
| `tenant.users.show` | `GET /users/2` | **200** |
| `tenant.users.manager-pin` | `GET /users/2/manager-pin` | **200**, PIN form present (`pin` + `pin_confirmation`) |
| `tenant.users.manager-pin.store` | `POST /users/2/manager-pin` (`pin` = the clone approver's existing PIN from `secrets\devclone-approver.pass`, re-set to itself so other sessions' W07/W08 on the shared clone keep working; value never printed) | **302 → /users/2**; `GET /users/2` → 200 with flash "Manager PIN set successfully" |
| manager_pins after | SQL (ids only) | `id 1, user_id 2, is_active 1, created_at 2026-10-02 20:23:45, updated_at 2026-10-29 09:31:37 → **2026-10-30 09:38:25**`, `last_used_at` unchanged; `password_verify(<file value>, pin_hash)` = **true**, hash prefix `$2y$` (bcrypt) |
| outside the five | `GET /users/create`, `GET /users/2/edit`, `GET /settings/offline-edge/security`, `GET /pos`, `GET /roles`, `POST /users/2/reset-password`, `POST /users/2/activate` | **403 Permission denied** — all seven |
| logout | `POST /logout`; `GET /users` | 302 → /login; **302 → /login** |
| disable | `php Disable-LabAdmin.php pos_devonline_tenant_edge` | `status active -> inactive, updated_at 09:38:49`; password replaced (random, discarded), remember_token cleared; grants removed 5, remaining **0**; exit 0 |
| after disable (SQL) | `04-after-disable-sql.txt` | user 3 `inactive`, `token_null 1`, `grants_user3 0`; approver's `manager_pins` row kept; approver's own 4 grants untouched (`mhp_user2 4`) |
| login refused | `POST /login` with the former admin password + token; `GET /dashboard` | **302 → /login** (refused); **302 → /login** |

`LAB_ADMIN_PROVISIONING_REVIEW=PASS` on the clone — the mechanism works end to end with exactly the five permissions, and the disable step leaves
zero grants and no usable credential.

### 2.4 Audit semantics

| what changed | where it is recorded |
|---|---|
| admin created / re-enabled | `users` row (`id`, `employee_code LABADM1`, `status`, `updated_at`); script output saved under evidence |
| grants | `model_has_permissions` rows (permission ids ↔ user id); `permissions.created_at` for the five names if the script had to create them |
| PIN set | `manager_pins` row of user 2: `pin_hash` (bcrypt), `is_active`, `updated_at` (changes on every store); `last_used_at` is written by the approval verify path, not by the store |
| HTTP trail | the browsable Cloud's stderr log (`cloud\cloud-browsable-<port>.log`: method, path, status per request) |
| disable | `users.status = inactive`, `users.updated_at`, zero `model_has_permissions` rows; the password hash is unusable by anyone |

### 2.5 Production isolation

- Both scripts hard-refuse any database name without `lab`/`devonline` (`guard-refusals.txt`); the launcher refuses non-`devonline` names outright.
- The LAB tenant DB (`pos_lab_tenant_edge`) is a separate schema; the Cloud process only knows the LAB master/tenant (`lab.env`); production tenants
  (Khatri/Kashif/Tawakal) live in other databases on other hosts with credentials that do not exist in the LAB configuration. A tenant-guard permission
  row in one tenant DB cannot authorize anything in another tenant.
- The admin's five permissions contain no POS, no settings, no user-edit, no role or system-reset route; the Edge pairing/security routes
  (`tenant.offline-edge.*`) are separate permissions (verified 403).

### 2.6 Real-LAB procedure (prepared, NOT executed; owner approval required)

1. Precondition: the browsable LAB Cloud (1.6 steps 1–5) is up.
2. The owner writes `C:\Users\Dell\BingooEdgeLab\secrets\admin.pass` privately (≥ 12 chars).
3. `php C:\Users\Dell\BingooEdgeLab\scripts\Create-LabAdmin.php pos_lab_tenant_edge` → prints the admin id, `LABADM1`, the five permission names (save the output under evidence).
4. In the LAB host's browser: `http://edgehomelab.localhost:9701/login` as `lab.admin@edgehomelab.test` → `/users` → user 2 "Lab Approver" → "Manager PIN" →
   enter the approver PIN twice (the owner's private value) → "Manager PIN set successfully". Record only `SELECT id, user_id, is_active, updated_at FROM pos_lab_tenant_edge.manager_pins WHERE user_id = 2;`.
5. Immediately: `php C:\Users\Dell\BingooEdgeLab\scripts\Disable-LabAdmin.php pos_lab_tenant_edge` → `status inactive`, 0 grants (exit 0 only when zero remain); delete the served tree's session files.
6. The next config refresh carries `may_approve_pos` for the approver to the appliance (bootstrap users section) — observe on the appliance health as in the 0.8.1 record.

## 3. Files written (none under app/, routes/, resources/, tests/; nothing committed)

| file | purpose |
|---|---|
| `C:\Users\Dell\BingooEdgeLab\scripts\Start-LabCloud-Browsable.ps1` | guarded browsable launcher (1.2) |
| `C:\Users\Dell\BingooEdgeLab\scripts\Create-LabAdmin.php` | disposable admin provisioning (2.2) |
| `C:\Users\Dell\BingooEdgeLab\scripts\Disable-LabAdmin.php` | disable (2.2) |
| `C:\Users\Dell\BingooEdgeLab\scripts\Ensure-LabTenantDomain.php` | the master `tenant_domains` row the real switch needs (F2); validated idempotently on the clone |
| `C:\Users\Dell\BingooEdgeLab\secrets\devclone-admin.pass` | the CLONE admin's random password (32 chars, never printed) |
| `C:\Users\Dell\BingooEdgeLab\evidence\agent-a\**` | evidence (see top) |
| `docs/status/edge-agent-a-lab-acceptance-prep.md` | this document |
