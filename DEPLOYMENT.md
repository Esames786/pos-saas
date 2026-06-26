# Bingoo POS — Production Deployment Guide

This runbook takes Bingoo POS from a fresh server to a live, multi-tenant SaaS
ready for its first paying client. Read it end-to-end before deploying.

> **Status note:** This covers configuration + deployment.
> **Database backups are available** via `tenants:backup` (PRD-3, see §15).
> **Legal pages (PRD-4) and transactional mail (PRD-5) are not done yet.**
> Configure and test backups (and your off-server copy) before onboarding a real
> paying client.

---

## 1. Deployment assumptions

- Single application server (PHP-FPM + Nginx/Apache) + one MySQL 8 server
  (same host or separate).
- Architecture is **multi-tenant with a database per tenant**: a `master`
  database plus one `pos_tenant_{code}` database created **at runtime** when a
  tenant is provisioned.
- Tenants are reached at `{tenant_code}.TENANT_BASE_DOMAIN`; the marketing site
  + central admin live on `CENTRAL_DOMAIN`.
- You deploy from the `main` branch (merge + tag your release first).

## 2. Server requirements

- **PHP 8.3** with extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`,
  `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `curl`, `gd`.
  - `ext-zip` is optional and only needed if `.xlsx` accounting export is added
    later (export is CSV today).
- **MySQL 8** (or MariaDB 10.6+).
- **Composer 2**.
- **Nginx or Apache** with wildcard virtual host + wildcard TLS.
- **OS cron** (for the Laravel scheduler — see §11). **Required.**
- Optional: Redis (cache/session/queue at scale).

## 3. DNS + SSL

Tenant subdomains are dynamic, so you need **wildcard DNS and a wildcard TLS
certificate**.

DNS:

```
A     bingoopos.com        -> <server-ip>
A     *.bingoopos.com      -> <server-ip>
```

TLS (Let's Encrypt example — DNS-01 challenge is required for wildcards):

```bash
certbot certonly --manual --preferred-challenges dns \
  -d bingoopos.com -d '*.bingoopos.com'
```

Point a single vhost at `public/` and serve both `bingoopos.com` and
`*.bingoopos.com` from it (Laravel resolves the tenant from the subdomain).
`CENTRAL_DOMAIN` and `TENANT_BASE_DOMAIN` in `.env` **must** match these names.

## 4. Database setup

Create the master DB and a user that can also create/drop tenant DBs:

```sql
CREATE DATABASE pos_saas_master CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'pos_saas_user'@'%' IDENTIFIED BY 'STRONG_PASSWORD_HERE';

-- Full rights on master + every tenant DB (pos_tenant_*)
GRANT ALL PRIVILEGES ON `pos_saas_master`.* TO 'pos_saas_user'@'%';
GRANT ALL PRIVILEGES ON `pos\_tenant\_%`.*  TO 'pos_saas_user'@'%';

-- Needed because tenant databases are CREATEd/DROPped at provisioning time
-- (app/Services/Tenancy/TenantProvisioner.php runs CREATE DATABASE on the
--  master connection).
GRANT CREATE, DROP ON *.* TO 'pos_saas_user'@'%';

FLUSH PRIVILEGES;
```

> If you prefer separate users for master vs tenant connections, the **master**
> user needs `CREATE DATABASE`/`DROP`, and the **tenant** user (`TENANT_DB_*`)
> needs `ALL PRIVILEGES ON pos_tenant_%`.

## 5. Environment file

```bash
cp .env.example .env
```

Then edit `.env` and set at minimum:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://bingoopos.com`
- `CENTRAL_DOMAIN` and `TENANT_BASE_DOMAIN` (match your wildcard DNS/SSL)
- `DB_*` (master) and `TENANT_DB_*` (tenant provisioning) credentials
- `MAIL_*` (SMTP) — optional until PRD-5, but configure now
- `LOG_CHANNEL=daily`, `LOG_LEVEL=warning`
- `SAAS_*` contact details; set `SAAS_DEMOS_ENABLED=false` on a pure prod host

## 6. Install dependencies

```bash
composer install --no-dev -o
php artisan key:generate
```

## 7. Master migration and seed

```bash
# Creates master schema (tenants, plans, modules, subscriptions, invoices…)
php artisan migrate --force

# Seed plans/modules + the central superadmin (use your project's seeders)
php artisan db:seed --force
```

> Tenant databases are **not** migrated here — each tenant is migrated/seeded
> automatically by `TenantProvisioner` when the tenant is created (signup or
> `tenants:provision-demo`).

## 8. Tenancy / domain checks

- Confirm `CENTRAL_DOMAIN` / `TENANT_BASE_DOMAIN` resolve (apex + a wildcard
  subdomain) and serve HTTPS.
- Confirm the DB user can create a database (provisioning a trial will fail
  otherwise).

## 9. Permissions and route sync

```bash
php artisan system:routes-sync
php artisan permission:cache-reset
```

## 10. Cache / build commands

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Re-run these on every deploy (after `git pull` / `composer install`).

## 11. Scheduler / cron  — REQUIRED

The app schedules subscription expiry (`saas:subscriptions-expire`, daily 00:10)
and the optional nightly demo reset. Without cron, **subscriptions never expire**.

Add to the deploy user's crontab:

```cron
* * * * * cd /path/to/pos-saas && php artisan schedule:run >> /dev/null 2>&1
```

## 12. Queue worker note

No queued jobs ship today (`QUEUE_CONNECTION=database` is safe without a worker).
**When transactional mail/notifications are added (PRD-5)**, run a worker:

```bash
php artisan queue:work --tries=3 --max-time=3600
# (supervise with systemd/supervisor)
```

## 13. Storage permissions

```bash
php artisan storage:link
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rw storage bootstrap/cache
```

## 14. Demo tenants

Public demo workspaces are optional and **separate** from real tenants.

```bash
php artisan tenants:provision-demo     # create/refresh demo tenants
php artisan demo:reset-all --yes       # reset public demos to clean sample data
```

> ⚠️ `demo:reset*` only ever touches the whitelisted demo tenants
> (`config/saas.demos.reset_tenant_codes`) and **never** real tenants.
> On a pure production host set `SAAS_DEMOS_ENABLED=false` and do not run these.

## 15. Backup & restore  — MANDATORY before first paid client

Backups are produced by the `tenants:backup` command (PRD-3). It dumps the
master DB and **every tenant DB**, plus an optional `storage/app` archive, into a
timestamped folder with a `manifest.json`. It is **read-only** — it never
creates, drops, or restores databases.

> ⚠️ **Security:** backup files contain **sensitive financial data**. Restrict
> the backup directory (`chmod 700`), move copies off-server, and encrypt at
> rest. Backups never contain `.env`, `vendor/`, or `node_modules/`.

### Where backups are stored

`{local-disk-root}/{BACKUP_PATH}/{YYYYmmdd_HHMMSS}/` — on the default Laravel 12
`local` disk that is **`storage/app/private/backups/{timestamp}/`** (override via
`BACKUP_DISK`/`BACKUP_PATH`). Each folder contains: `master.sql`,
`tenant_{code}.sql` (one per tenant), optional `storage.tar.gz`, and
`manifest.json`.

### Backup commands

```bash
# Dry run — show exactly what would be backed up, writes nothing
php artisan tenants:backup --dry-run

# Full backup (master + all tenant DBs + storage archive)
php artisan tenants:backup

# One tenant only (no storage archive)
php artisan tenants:backup --tenant=acme --no-storage

# Master DB only / tenant DBs only
php artisan tenants:backup --master-only
php artisan tenants:backup --tenants-only

# Backup then delete folders older than BACKUP_RETENTION_DAYS
php artisan tenants:backup --prune
```

> On Windows/Laragon, set `MYSQLDUMP_BINARY` in `.env` to the absolute
> `mysqldump.exe` path if it is not on PATH.

### Suggested scheduler (production)

A commented recommendation is in `routes/console.php`; enable on the server:

```php
Schedule::command('tenants:backup --prune')->dailyAt('02:00')->withoutOverlapping();
```

(Relies on the OS cron from §11. Left disabled by default so it never runs in dev.)

### Retention policy

`--prune` deletes backup folders older than `BACKUP_RETENTION_DAYS` (default 14).
Keep additional **off-server** copies with your own longer retention (e.g. weekly
for 3 months) — `--prune` only manages the local folder.

### What is NOT backed up

`.env`, `vendor/`, `node_modules/`, and `storage/framework` (cache/sessions/
views/logs) — only `storage/app` (uploads such as payment proofs) is archived.

### Restore procedure (MANUAL + EXPLICIT)

Restore is intentionally **not** automated. Never restore over production without:

1. **Maintenance mode** — `php artisan down`.
2. **Take a fresh backup first** — `php artisan tenants:backup`.
3. **Confirm the exact target DB name** (master vs the specific tenant DB).
4. Restore **master and the affected tenant DB(s) consistently** (same backup set).
5. Clear caches and re-prime them.
6. Run the §16 smoke test, then `php artisan up`.

Manual example commands (review/adjust before running — these OVERWRITE data):

```bash
# Master (mysql client reads the dump on stdin)
mysql -u pos_saas_user -p pos_saas_master < master.sql

# A specific tenant DB (must already exist; do NOT create/drop blindly)
mysql -u pos_saas_user -p pos_tenant_acme < tenant_acme.sql

# Storage (extract into storage/app)
tar -xzf storage.tar.gz -C storage/app
```

### Restore test checklist

- Restore a backup into a **non-production** database and app instance.
- Log in as a tenant owner; open POS; open Finance → Trial Balance (**diff = 0**).
- Confirm products, sales, GRNs, and journals match the backup date.
- Document the restore time so you know your recovery window.

## 16. Go-live smoke test

Run through this on the live host before announcing:

1. Central home (`https://CENTRAL_DOMAIN`) loads.
2. `/start-trial` loads.
3. Create a trial → tenant + tenant DB provisioned, and the **"workspace ready"
   email** is sent to the owner (check the inbox, or `storage/logs` if
   `MAIL_MAILER=log`).
4. Tenant subdomain `{code}.TENANT_BASE_DOMAIN` resolves over HTTPS.
5. Owner can log in.
6. **Forgot password:** on the tenant login, click "Forgot password?", submit the
   owner email → generic success message + reset email with a link on the **same
   tenant subdomain**; complete the reset and log in with the new password.
7. POS screen opens.
8. Create a product.
9. Create a Purchase Order → Approve → **Send to GRN** (lines prefilled).
10. Post the GRN (stock + inventory batch created).
11. Make a POS sale.
12. **Trial Balance difference is 0** (Finance → Trial Balance).
13. Public demos (if enabled) still open and are write-protected.

### Email / onboarding (PRD-5)

- **Configure SMTP before live signup** (`MAIL_*` in §5). With `MAIL_MAILER=log`
  emails are written to `storage/logs/laravel-*.log` instead of being sent.
- Two transactional emails exist: the **trial workspace-ready** email (after
  signup) and the **password-reset** email. Both are best-effort — a mail failure
  is logged and never blocks signup or reveals whether an email is registered.
- After go-live, **test both** (a real signup and a forgot-password) and confirm
  delivery; check `storage/logs` for any mail errors.
- Self-signup uses an **owner-chosen** password, so no password is emailed; the
  workspace-ready email links to login + the reset flow instead.

## 17. Rollback checklist

1. **Back up DBs first** (master + affected tenant DBs).
2. `php artisan down` (maintenance mode).
3. Restore the previous code release (git tag / release dir).
4. Restore master DB from backup.
5. Restore any affected tenant DB(s).
6. `php artisan optimize:clear && php artisan config:cache route:cache view:cache`.
7. `php artisan up` and re-run the §16 smoke test.

## 18. Routine updates — deploy a new release (DO THIS AFTER EVERY PUSH)

This is the **recurring** workflow for applying the latest pushed changes to the
live site. It is **non-destructive** (no DB is dropped or wiped) and safe to
re-run. It is *not* `system:reset` (see §18.6 — that one wipes everything).

Prerequisites (already true on the live box, verify once):
- `.env` has all four `TENANT_DB_*` lines, with `TENANT_DB_USERNAME=new_admin`
  (`grep -c TENANT_DB .env` → `4`). If this is ever missing, tenants connect as
  `root` and migrations fail with `Access denied for user 'root'@'localhost'`.
- `php` resolves to PHP 8.2 (else use `/usr/bin/php8.2` in the commands below).

### 18.1 The one-command deploy (recommended)

A committed script does the whole sequence. On the server:

```bash
cd /var/www/html/pos-saas
bash deploy.sh
```

`deploy.sh` self-pulls and runs, in order:
1. `git pull --ff-only`
2. `composer install --no-dev --optimize-autoloader` (no-op if unchanged)
3. `php artisan migrate --force` — **master** DB migrations
4. `php artisan system:routes-sync` — register any new route permissions in `route_catalogs`
5. **Per-tenant loop** (resilient — a broken/`pending` tenant prints `SKIP:` and the
   run continues): tenant migrations + `DefaultChartOfAccountsSeeder` + grant the
   `Owner` role **every** `tenant.%` permission (read from `route_catalogs.route_name`,
   so new permissions are picked up automatically — no per-sprint editing needed)
6. `permission:cache-reset` + `optimize:clear` + `config:cache` + `route:cache` + `view:cache`
7. `sudo systemctl reload php8.2-fpm` — **clears OPcache** (without this, old PHP
   bytecode can keep serving and your change "doesn't take")

Watch the output for `tenants ok=N skipped=M` and `DEPLOY COMPLETE`. Any tenant on
a `SKIP:` line needs repair — see §18.4.

> First time only: the script must exist on the box, so run `git pull` once
> manually to fetch `deploy.sh`, then use `bash deploy.sh` from then on.

### 18.2 Manual equivalent (if you can't run the script)

```bash
cd /var/www/html/pos-saas
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force                 # master DB
php artisan system:routes-sync
# per-tenant migrations + CoA + Owner permission sync (see 18.3)
php artisan permission:cache-reset
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl reload php8.2-fpm
```

### 18.3 Per-tenant migrations + permission sync (the important loop)

Tenant migrations live in `database/migrations/tenant` and **must run with a tenant
activated** (a raw `php artisan migrate --database=tenant` fails with "No database
selected"). This loop migrates every tenant, keeps the Chart of Accounts in sync,
and grants the `Owner` role all current tenant route permissions. It is what
`deploy.sh` runs; use it standalone if needed:

```bash
php artisan tinker --execute='
$names = \Illuminate\Support\Facades\DB::connection("master")->table("route_catalogs")->where("route_name","like","tenant.%")->pluck("route_name")->all();
foreach (\App\Models\Master\Tenant::all() as $t) {
  try {
    app(\App\Services\Tenancy\TenancyManager::class)->activate($t);
    \Illuminate\Support\Facades\Artisan::call("migrate", ["--database"=>"tenant","--path"=>"database/migrations/tenant","--force"=>true]);
    (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
    foreach ($names as $n) { \Spatie\Permission\Models\Permission::findOrCreate($n, "tenant"); }
    $owner = \Spatie\Permission\Models\Role::where("name","Owner")->where("guard_name","tenant")->first();
    if ($owner && $names) { $owner->givePermissionTo($names); }
    echo "  OK:   {$t->tenant_code}\n";
  } catch (\Throwable $e) {
    echo "  SKIP: {$t->tenant_code} -> ".substr($e->getMessage(),0,90)."\n";
  } finally {
    app(\App\Services\Tenancy\TenancyManager::class)->deactivate();
    \Illuminate\Support\Facades\DB::setDefaultConnection("master");
  }
}'
php artisan permission:cache-reset
```

### 18.4 Repairing a broken / `pending` demo tenant

If §18.1/§18.3 prints `SKIP:` for a tenant, or you see
`Access denied for user 'root'@'localhost' (Connection: tenant)`, that tenant's DB
is half-built (e.g. its per-tenant stored DB user is `root`, or its status is
`pending` from an aborted reset). **For a demo tenant, re-provision it fresh.**

> ⚠️ Argument types differ between commands:
> - `demo:reset` takes the **tenant_code** — e.g. `retaildemo`
> - `demo:provision` and `demo:seed` take the **industry** — e.g. `retail`
>
> Industry → tenant_code: retail→retaildemo, inventory→inventorydemo,
> restaurant→restaurantdemo, restaurant_pro→restaurantprodemo,
> enterprise→enterprisedemo, finance→financedemo.

```bash
php artisan config:clear                       # ensure live .env (new_admin) is used
php artisan demo:reset retaildemo --yes        # tenant_code; works only if status=active
# If it refuses ("status is [pending]" / "not active") OR the DB user is root:
php artisan demo:provision retail --fresh      # industry; drops+recreates clean as new_admin
php artisan demo:seed retail                   # industry; reloads sample data
```

Re-provisioning runs all tenant migrations + the CoA seeder + grants permissions
via `TenantProvisioner`, so the repaired tenant ends up fully up to date.

> The nightly `demo:reset-all` **stops on the first failing tenant**, so one broken
> demo (e.g. `retaildemo`, which is first in `reset_tenant_codes`) blocks the rest.
> If the scheduled reset is failing, repair the first demo as above.

### 18.5 Verify the deploy

```bash
# (a) every tenant DB connects (no root/access-denied, no pending leftovers)
php artisan tinker --execute='
foreach (\App\Models\Master\Tenant::all() as $t) {
  try { app(\App\Services\Tenancy\TenancyManager::class)->activate($t);
    \Illuminate\Support\Facades\DB::connection("tenant")->select("select 1");
    echo "CONN OK:   {$t->tenant_code} (".$t->status.")\n";
  } catch (\Throwable $e) { echo "CONN FAIL: {$t->tenant_code} -> ".substr($e->getMessage(),0,70)."\n"; }
  finally { app(\App\Services\Tenancy\TenancyManager::class)->deactivate(); \Illuminate\Support\Facades\DB::setDefaultConnection("master"); }
}'

# (b) finance integrity on a representative tenant (must be tb_diff=0 / pl_vs_bs=OK)
php artisan tinker --execute='
$t = \App\Models\Master\Tenant::where("tenant_code","financedemo")->first();
app(\App\Services\Tenancy\TenancyManager::class)->activate($t);
$tb = app(\App\Services\Finance\FinancialExportService::class)->trialBalance(now()->toDateString(), null);
echo "tb_diff=".$tb["difference"]."\n";
$pl = app(\App\Services\Finance\ProfitLossService::class)->statement(["date_from"=>"2000-01-01","date_to"=>now()->toDateString()]);
$bs = app(\App\Services\Finance\BalanceSheetService::class)->statement(["as_of_date"=>now()->toDateString()]);
echo "pl_vs_bs=".(abs($pl["net_profit"]-$bs["current_earnings"])<=0.01 ? "OK" : "MISMATCH")."\n";
'
```

Then in the browser: load a tenant dashboard + the screen the release changed, and
confirm Finance → Trial Balance still shows **difference 0**.

### 18.6 When to use `system:reset` instead (DESTRUCTIVE — rarely)

`php artisan system:reset --skip-backup --force` **drops every `pos_tenant_*`
database** and rebuilds master + all demos from seed. Use it ONLY for a deliberate
full demo rebuild when there is no real tenant/signup data to lose. It is **not**
the normal deploy path — for shipping code changes always use §18.1.

```bash
php artisan config:clear         # TENANT_DB_USERNAME must be live, not cached
php artisan system:reset --backup   # safest: backs up first, asks to confirm
```

### 18.7 Common gotchas

- **Change didn't take effect** → OPcache. Always `sudo systemctl reload php8.2-fpm`
  after a deploy (it's the last step of `deploy.sh`).
- **`Access denied for user 'root'@'localhost'`** on a tenant → `TENANT_DB_USERNAME`
  not in live config, or that tenant was provisioned as root. Fix .env + `config:clear`,
  then repair the tenant (§18.4).
- **403 / missing menu after adding a feature** → permissions not granted or Spatie
  cache stale. The §18.3 loop grants them; always finish with `permission:cache-reset`.
- **New route 404s** → route cache stale. `php artisan route:cache` (done by the script).
- **Never** edit `.env` with `echo "... " >> .env` blindly — check first
  (`grep TENANT_DB .env`); a duplicate or wrong line breaks tenant DB auth.

## 18. Common issues

- **Tenant subdomain 404 / wrong site** → `TENANT_BASE_DOMAIN` mismatch or no
  wildcard DNS/SSL. Verify `*.domain` resolves and the vhost serves it.
- **Trial creation fails at "creating database"** → DB user lacks
  `CREATE DATABASE`. Re-check §4 grants.
- **Subscriptions never expire** → cron not installed (§11).
- **Stack traces visible to users** → `APP_DEBUG` is still `true`. Set `false`,
  then `php artisan config:cache`.
- **Permissions/menu missing after deploy** → run
  `php artisan system:routes-sync && php artisan permission:cache-reset`.
- **Stale views/config after deploy** → re-run the §10 cache commands.
- **Uploads fail (payment proof)** → `storage/` not writable (§13).
