# ⚠️ Destructive Commands — READ BEFORE RUNNING ON PRODUCTION

## `php artisan system:reset`
**DROPS EVERY `pos_tenant_*` DATABASE**, wipes and rebuilds the master DB, then
re-provisions ONLY the demo tenants.

- Any REAL client tenant is **permanently deleted** and is NOT recreated.
  (This already happened once: client tenant `mohsin` was destroyed by a
  system:reset on 2026-07-08.)
- `--skip-backup` skips the only safety net.
- **Never run on production once real clients exist** — unless you have a fresh
  verified backup AND explicit approval.
- For a normal code deploy use `bash deploy.sh` — it is non-destructive,
  idempotent, and covers migrations/permissions/caches.

## `php artisan demo:reset-all` / `demo:reset {tenant_code}`
Wipes and reseeds **demo tenants only** (guarded by `saas.demos.reset_tenant_codes`,
`is_demo=true`, DB-name prefix checks). Safe for demos; never usable on client
tenants without deliberately overriding env safety flags
(`ALLOW_TENANT_RESET_FOR_NON_DEMO`, `ALLOW_RESET_ALL_TENANTS` — leave these unset).

## Central panel → Tenants → Reset
Same guards as `demo:reset` (demo tenants only) + typed-confirmation modal.

## `migrate:fresh` (any variant)
Never on production. `deploy.sh` uses plain `migrate --force` only.

## Quick decision table
| I want to… | Use |
|---|---|
| Ship code changes | `bash deploy.sh` |
| Refresh demo data | `demo:reset-all --yes` (or panel Reset Demos) |
| Rebuild EVERYTHING incl. dropping tenants (dev only) | `system:reset` |
| Fix a broken/pending tenant | `config:clear` → `demo:provision <industry> --fresh` → `demo:seed <industry>` |
