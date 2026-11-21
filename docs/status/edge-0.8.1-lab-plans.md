# 0.8.1 — LAB plans requested by the owner (prepared only; nothing on the real LAB changed), 24 Oct 2026

## 1. LAB Online POS — browsable configuration plan (owner §7)

### What is actually configured today (inspected)

- `scripts/Start-LabCloud.ps1` launches `php -S 127.0.0.1:9701` with `CENTRAL_DOMAIN=127.0.0.1`, `TENANT_BASE_DOMAIN=127.0.0.1`, `SESSION_DRIVER=array`,
  `CACHE_STORE=array`, `APP_URL=http://127.0.0.1:9701`.
- Tenant web routes are mounted on `Route::domain('{subdomain}.' . config('tenancy.tenant_base_domain'))` (`routes/tenant.php:111`) → the LAB tenant's
  POS lives at host `edgehomelab.<tenant_base_domain>`. With the base `127.0.0.1` that host is `edgehomelab.127.0.0.1`, which no browser resolves
  (and which the app answers 400/404 for); `/pos` on the bare IP is a central-domain request → 404. An `array` session cannot carry a login
  across requests (419 on the login POST). So the LAB Cloud is today an API/sync target only — that is why the 0.7.0 acceptance used the appliance
  in Local Mode for every cashier step.
- The Edge device API is mounted on the CENTRAL domain (`routes/edge.php:13` `Route::domain(config('tenancy.central_domain'))->prefix('api/edge')`,
  `central.only`). The appliance's `appliance.env` endpoints all point at `http://127.0.0.1:9701` (`EDGE_CLOUD_BASE_URL`, `EDGE_SYNC_*_URL`, …).
- The disposable dev clone (`Start-DevCloud.ps1`, :9704) is the PROVEN template for "browsable AND an appliance target": `APP_URL=http://edgehomelab.localhost:9704`,
  `CENTRAL_DOMAIN=localhost`, `TENANT_BASE_DOMAIN=localhost`, `SESSION_DRIVER=file`, `CACHE_STORE=array`; the paired appliance 8096 talks to it on
  `127.0.0.1:9704` while browsers use `edgehomelab.localhost:9704` (Chromium/Edge resolve `*.localhost` to loopback by RFC 6761 without a hosts entry).

### Plan (reversible, LAB-only, private loopback — no LAN exposure needed)

1. `Start-LabCloud.ps1` gets two switches, default OFF so the current behaviour is unchanged: `-Browsable` → `CENTRAL_DOMAIN=localhost`,
   `TENANT_BASE_DOMAIN=localhost`, `APP_URL=http://edgehomelab.localhost:9701`, `SESSION_DRIVER=file` (persistent LAB-only file sessions under the
   export's `storage/framework/sessions` — never a shared store), `SESSION_SAME_SITE=lax` (default), no `SESSION_DOMAIN` (host-only cookie);
   `-NotBrowsable` restores today's block. The listener stays `127.0.0.1:9701` (never `0.0.0.0`).
2. Appliance compatibility: the device API accepts requests whose Host matches `CENTRAL_DOMAIN`. The appliance sends Host `127.0.0.1:9701`
   (from its `EDGE_*_URL`s), so with `CENTRAL_DOMAIN=localhost` the `central.only` match must be verified BEFORE the switch: on the dev clone the
   paired appliance 8096 already works against `CENTRAL_DOMAIN=localhost` with `127.0.0.1:9704` URLs (FB2/FB3 runs) — the same combination; the plan's
   first step is to prove it once more on the pair after this hotfix and, if the match is strict, to add `127.0.0.1` as an accepted central host
   alias in the LAB launch only (never the appliance env — its URLs are not touched).
3. Browser URL for the Online POS: `http://edgehomelab.localhost:9701/login` → `/pos` (cashier `lab.cashier@edgehomelab.test`, password in
   `secrets\cashier.pass`, read into the harness env, never printed). Tenant isolation, authentication and CSRF stay exactly as the app implements them
   (file sessions carry the CSRF token; `tenant.only` still binds the host to the tenant).
4. Execution order when approved: stop the LAB Cloud (`-Stop`), start with `-Browsable` from the same export, re-check appliance heartbeats resume
   (lease `last_heartbeat_at` moving, authority worker ticks `current`), then the Online acceptance run. Rollback = `-Stop` + start without the switch.
5. Not done in this directive: no LAB Cloud restart, no config change.

```
LAB_ONLINE_BROWSER_CONFIG_PLAN=Start-LabCloud.ps1 -Browsable (CENTRAL_DOMAIN/TENANT_BASE_DOMAIN=localhost, SESSION_DRIVER=file, APP_URL=http://edgehomelab.localhost:9701, listener stays 127.0.0.1:9701) → browser URL http://edgehomelab.localhost:9701/pos; appliance endpoints unchanged (127.0.0.1:9701); verify the central-host match on the dev pair first; reversible by restarting without the switch
```

## 2. LAB manager-PIN — disposable LAB admin provisioning plan (owner §8)

### Mechanism (existing, authorized LAB provisioning path)

The LAB tenant was provisioned by `scripts/lab-cloud.php` (`init`/seed, P5C) and extended by the owner-approved `scripts/Create-LabApprover.php`
(26 Sep 2026): both create tenant users through the LAB-only PDO path with `bcrypt`/`password_hash(PASSWORD_BCRYPT)` (the application's own hasher,
`Hash::make` = bcrypt) and `permissions` + `model_has_permissions` rows with guard `tenant`, refusing any database whose name lacks "lab". The planned
`Create-LabAdmin.php` mirrors `Create-LabApprover.php` exactly, differing only in the user and the permission list.

### The disposable admin (not created in this directive)

| Item | Value |
|---|---|
| user | `lab.admin@edgehomelab.test`, name "Lab Admin (disposable)", employee code `LABADM1`, status active, default branch 1 |
| password | read from a NEW owner-provided secrets file `secrets\admin.pass` (the owner writes it privately; the script bcrypts it; never argv/log/report) |
| permissions (minimum) | `tenant.dashboard` (landing), `tenant.users.index`, `tenant.users.show`, `tenant.users.manager-pin` (GET form), `tenant.users.manager-pin.store` (POST) — all route-name permissions the `route.permission` middleware checks; NOTHING else (no users.store/edit/destroy, no role management, no POS) |
| what the owner then does privately | log in at the browsable LAB Cloud URL → `/users/2/manager-pin` → enter the approver PIN twice → the controller validates (string 4–64, confirmation), bcrypt-hashes (`Hash::make`) and `ManagerPin::updateOrCreate(['user_id' => 2], [...is_active => true])`; audit = the `manager_pins` row timestamps + the `users` row untouched; the next config refresh carries `may_approve_pos = true` to the appliance (bootstrap v8 users section) |
| after acceptance | `Disable-LabAdmin.php`: set `users.status = 'inactive'` for `lab.admin@…` and remove its `model_has_permissions` rows; the user row and the `manager_pins` row are kept (audit history) |
| guards | script refuses non-"lab" DBs; refuses to run if `secrets\admin.pass` is missing or shorter than 12 chars; prints ids, codes and permission names only |

Preconditions: the LAB Cloud must be browsable (§1) — the manager-PIN page is a tenant-subdomain route. Without §1, STOP: there is no supported
way to reach the UI, and the owner forbids the service path outside the route authorization.

```
LAB_ADMIN_PROVISIONING_PLAN=Create-LabAdmin.php (mirror of the owner-approved Create-LabApprover.php: LAB-only PDO, bcrypt, guard tenant) → one disposable admin with exactly tenant.dashboard, tenant.users.index/show, tenant.users.manager-pin, tenant.users.manager-pin.store; password from secrets\admin.pass (owner-written); PIN entered privately through /users/2/manager-pin; Disable-LabAdmin.php afterwards (inactive + grants removed, rows kept); requires the browsable LAB Cloud
```
