# Bingoo POS — Production Deployment Guide

This runbook takes Bingoo POS from a fresh server to a live, multi-tenant SaaS
ready for its first paying client. Read it end-to-end before deploying.

> **Status note:** This covers configuration + deployment only.
> **Database backups (PRD-3), legal pages (PRD-4) and transactional mail (PRD-5)
> are not done yet.** Do **not** onboard a real paying client until backups
> exist (see §15).

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

## 15. Backup requirement  — MANDATORY before first paid client

There is **no backup command yet** (coming in **PRD-3**). Until then, set up a
manual schedule that dumps the master DB **and every tenant DB**:

```bash
# master
mysqldump pos_saas_master > master_$(date +%F).sql
# each tenant DB (enumerate pos_tenant_* from the server)
for db in $(mysql -N -e "SHOW DATABASES LIKE 'pos\_tenant\_%'"); do
  mysqldump "$db" > "${db}_$(date +%F).sql"
done
```

Store off-server, test a restore, and define a retention policy.
**PRD-3 will replace this with a `tenants:backup` artisan command + runbook.**

## 16. Go-live smoke test

Run through this on the live host before announcing:

1. Central home (`https://CENTRAL_DOMAIN`) loads.
2. `/start-trial` loads.
3. Create a trial → tenant + tenant DB provisioned.
4. Tenant subdomain `{code}.TENANT_BASE_DOMAIN` resolves over HTTPS.
5. Owner can log in.
6. POS screen opens.
7. Create a product.
8. Create a Purchase Order → Approve → **Send to GRN** (lines prefilled).
9. Post the GRN (stock + inventory batch created).
10. Make a POS sale.
11. **Trial Balance difference is 0** (Finance → Trial Balance).
12. Public demos (if enabled) still open and are write-protected.

## 17. Rollback checklist

1. **Back up DBs first** (master + affected tenant DBs).
2. `php artisan down` (maintenance mode).
3. Restore the previous code release (git tag / release dir).
4. Restore master DB from backup.
5. Restore any affected tenant DB(s).
6. `php artisan optimize:clear && php artisan config:cache route:cache view:cache`.
7. `php artisan up` and re-run the §16 smoke test.

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
