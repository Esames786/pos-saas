# Backup & Restore Runbook

> ⚠️ **Local-only backup is not enough. If the droplet is lost, local backups are
> lost with it.** Offsite sync is REQUIRED before real clients.

## What exists
| Tool | What it does |
|---|---|
| `php artisan tenants:backup` | Dumps master + every tenant DB (+ optional storage archive) to `storage/app/private/backups/{timestamp}/` with a manifest |
| `php artisan tenants:backup --prune` | Same + deletes backup folders older than `BACKUP_RETENTION_DAYS` (default 14) |
| Central panel → Tenants → Backup / Backup All | Per-tenant SQL dump to `storage/app/private/backups/tenants/{code}/`, downloadable, restore with pre-restore safety backup |
| `tenant_backups` table | Registry of panel backups |

## Nightly schedule (PROD-READINESS-1)
`routes/console.php` registers `tenants:backup --prune` daily at **02:00**, gated by:
```dotenv
BACKUP_SCHEDULE_ENABLED=true    # set on PRODUCTION only, then config:clear && config:cache
```
Requires the existing OS cron `* * * * * php /var/www/html/pos-saas/artisan schedule:run`.
Verify next-run: `php artisan schedule:list`.

## Retention policy
- Nightly dumps: `BACKUP_RETENTION_DAYS` (14) via `--prune`.
- Panel backups: manual — review `storage/app/private/backups/tenants/` size monthly.

## Offsite (choose one, REQUIRED)
1. **S3-compatible bucket** (AWS S3 / DigitalOcean Spaces / Backblaze B2):
   configure the `s3` disk via `AWS_*` env keys, then either set `BACKUP_DISK=s3`
   or sync after the nightly run:
   ```bash
   # /etc/cron.d entry after 02:00 backup, using rclone or s3cmd
   30 2 * * * root rclone sync /var/www/html/pos-saas/storage/app/private/backups remote:bingoo-pos-backups
   ```
2. **rsync to a second host**:
   ```bash
   45 2 * * * root rsync -az --delete /var/www/html/pos-saas/storage/app/private/backups/ backup@otherhost:/backups/pos-saas/
   ```
Never store cloud credentials in git — production `.env` / root-owned config only.

## Restore drill (practice quarterly)
### Single tenant (panel path — preferred)
1. Central panel → Tenants → row → **Backups** → pick file → **Restore**
   (a pre-restore backup is taken automatically; cross-tenant restore is blocked).
2. Verify: tenant login, `tb_diff=0`, stock balances sane.

### Single tenant (CLI)
```bash
mysql -u<user> -p pos_tenant_<code> < 20260704_083950_<code>.sql
php artisan system:clear-tenant-permission-cache
```

### Full-disaster (new droplet)
1. Provision LEMP + PHP 8.2, clone repo, restore production `.env` (kept in your password manager — NOT in git).
2. Restore master: `mysql pos_saas_master < master.sql`.
3. Restore every `pos_tenant_*` dump.
4. `composer install --no-dev && php artisan config:cache && route:cache && view:cache && storage:link`
5. Restore `storage/app` archive (payment proofs, product images).
6. `php artisan system:clear-tenant-permission-cache` + smoke (login, tb_diff, key pages).

## Verify a backup is good (spot-check monthly)
```bash
LATEST=$(ls -t storage/app/private/backups | head -1)
ls -lh storage/app/private/backups/$LATEST
grep -c "CREATE TABLE" storage/app/private/backups/$LATEST/tenant_demo.sql
grep -c "INSERT INTO"  storage/app/private/backups/$LATEST/tenant_demo.sql
```
A structure-only dump (0 INSERTs on a data-bearing tenant) means something is wrong.
