# Release Checklist

Use for every production release. The standard path is `bash deploy.sh` — this
checklist wraps it with pre/post verification.

## Pre-deploy
- [ ] `git status` clean locally; feature verified locally (lint + render/functional QA)
- [ ] `git log origin/feat/...` — the exact commit going out is confirmed
- [ ] `git diff` reviewed — **no secrets** (.env values, passwords, tokens, IPs+creds)
- [ ] New migrations reviewed: additive only? destructive steps flagged?
- [ ] No destructive commands in the release path (see DESTRUCTIVE_COMMANDS_WARNING.md)
- [ ] Backup before risky releases: `php artisan tenants:backup` (or panel Backup All)

## Deploy (deploy.sh does 1-9 automatically)
- [ ] `git pull` (ff-only)
- [ ] composer install
- [ ] master `migrate --force`
- [ ] `MasterSeeder` (plans/modules/central perms — idempotent)
- [ ] `system:routes-sync`
- [ ] per-tenant: migrate + CoA seed + grant Owner all `tenant.%` perms (resilient loop)
- [ ] `permission:cache-reset` **+ `system:clear-tenant-permission-cache`** (per-tenant spatie rows!)
- [ ] `config:cache` / `route:cache` / `view:cache`
- [ ] `queue:restart || true`
- [ ] ownership fix (`chown -R www-data:www-data storage bootstrap/cache`)
- [ ] reload php-fpm

## Post-deploy smoke
- [ ] Tenant login → dashboard 200 (`https://demo.bingoopos.com`)
- [ ] Central login → tenants page 200 (no 403 = permission caches healthy)
- [ ] New/changed routes respond (spot-check the release's screens)
- [ ] Finance smoke on demo tenant:
  ```
  tb_diff=0
  official_negative_stock_rows=0
  department_negative_stock_rows=0
  ```
- [ ] Key module spot-check (POS page, one report)
- [ ] `tail -50 storage/logs/laravel-$(date +%F).log` — no fresh ERRORs
- [ ] If perms/routes changed and a tenant still 403s: run
      `php artisan system:clear-tenant-permission-cache` again

## Rollback
- `git log` the previous tag/commit → `git checkout <sha> -- .` is NOT the path;
  use `git reset --hard <previous-release-sha>` + re-run deploy.sh steps 6-9.
- DB rollbacks come from backups (see BACKUP_AND_RESTORE_RUNBOOK.md) — migrations
  are not auto-reversed on production.
