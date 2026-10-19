#!/usr/bin/env bash
#
# Bingoo POS — standard production deploy.
# Run this after pushing changes:   bash deploy.sh
#
# SAFE + IDEMPOTENT + NON-DESTRUCTIVE: it never drops or wipes a database and is
# safe to re-run. It pulls code, applies master + per-tenant migrations, keeps the
# Chart of Accounts + Owner permissions in sync with the code, rebuilds caches and
# reloads PHP-FPM (clears OPcache).
#
# It does NOT reseed demo data. For a full demo wipe+rebuild use the destructive
#   php artisan system:reset --skip-backup --force
# instead (that DROPS every pos_tenant_* DB — do not use for a normal deploy).
#
# A single broken/pending tenant is skipped with a SKIP line instead of aborting
# the whole run; fix it separately (e.g. php artisan demo:provision <industry> --fresh).
#
set -euo pipefail
cd "$(dirname "$0")"

PHP=php   # use php8.2 explicitly if 'php' isn't 8.2 on this box

echo "==> [1/9] Pull latest code"
git pull --ff-only

echo "==> [2/9] Composer (no-op if dependencies unchanged)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> [3/9] Master database migrations"
$PHP artisan migrate --force

echo "==> [3b/9] Master seed (plans/modules/central perms — idempotent)"
$PHP artisan db:seed --class="Database\\Seeders\\MasterSeeder" --force

echo "==> [4/9] Register route permissions in the catalog"
# route:clear FIRST — with a stale cached routes file, routes-sync misses the
# newly pulled routes, so their permissions never enter the catalog and the
# per-tenant grant loop silently skips them (found 2026-07-12).
$PHP artisan route:clear
$PHP artisan system:routes-sync

echo "==> [5/9] Per-tenant: migrations + Chart of Accounts + grant Owner all tenant permissions (resilient)"
$PHP artisan tinker --execute='
$names = \Illuminate\Support\Facades\DB::connection("master")->table("route_catalogs")->where("route_name","like","tenant.%")->pluck("route_name")->all();
$ok = 0; $skip = 0;
foreach (\App\Models\Master\Tenant::all() as $t) {
  try {
    app(\App\Services\Tenancy\TenancyManager::class)->activate($t);
    // Forget the spatie permission cache after switching connection so findOrCreate reads
    // THIS tenant DB (otherwise a stale cross-tenant cache makes it INSERT existing perms → dup-key).
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    \Illuminate\Support\Facades\Artisan::call("migrate", ["--database"=>"tenant","--path"=>"database/migrations/tenant","--force"=>true]);
    (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
    foreach ($names as $n) { \Spatie\Permission\Models\Permission::findOrCreate($n, "tenant"); }
    $owner = \Spatie\Permission\Models\Role::where("name","Owner")->where("guard_name","tenant")->first();
    if ($owner && $names) { $owner->givePermissionTo($names); }
    echo "  OK:   {$t->tenant_code}\n"; $ok++;
  } catch (\Throwable $e) {
    echo "  SKIP: {$t->tenant_code} -> ".substr($e->getMessage(),0,90)."\n"; $skip++;
  } finally {
    app(\App\Services\Tenancy\TenancyManager::class)->deactivate();
    \Illuminate\Support\Facades\DB::setDefaultConnection("master");
  }
}
echo "  tenants ok={$ok} skipped={$skip}\n";
'

echo "==> [6/9] Flush permission caches (master + EVERY tenant cache table)"
$PHP artisan permission:cache-reset
# PROD-READINESS-1: the spatie cache key also lives in each tenant DB's cache
# table; a stale per-tenant row 403s NEW permissions while old ones still work.
$PHP artisan system:clear-tenant-permission-cache

echo "==> [7/9] Rebuild caches"
$PHP artisan optimize:clear
# Build the caches AS the PHP-FPM user (www-data) so the compiled config/routes/VIEWS are owned by the
# same user that serves requests. Running these as root left root-owned compiled views that PHP-FPM
# (www-data) could not overwrite when a request needed to recompile — 500-ing the whole site (Khatri
# outage 2026-08-28: "file_put_contents(storage/framework/views/…): Permission denied"). Fall back to
# root (then the [8] chown fixes ownership) if www-data cannot run artisan on this host.
if sudo -u www-data $PHP artisan --version >/dev/null 2>&1; then RUNWEB="sudo -u www-data $PHP"; RUNAS="sudo -u www-data"; else RUNWEB="$PHP"; RUNAS=""; fi
# DEPLOY-ATOMIC-CACHE-1 (19 Oct): at the dinner rush one request read routes-v7.php while route:cache was
# still writing it (ParseError "Unclosed '('", 16:33:19 UTC). So the config and route caches are written to
# a temp file first and renamed into place in ONE step: a request sees the old file or the new one, never
# half of one. Laravel takes the path from APP_CONFIG_CACHE / APP_ROUTES_CACHE (relative to the app).
build_cache() {   # $1 artisan command · $2 the env var naming its file · $3 the real file
    local tmp="${3%.php}.next.php"
    rm -f "$tmp"
    $RUNAS env "$2=$tmp" $PHP artisan "$1"
    $PHP -l "$tmp" >/dev/null
    mv -f "$tmp" "$3"
}
build_cache config:cache APP_CONFIG_CACHE bootstrap/cache/config.php
build_cache route:cache APP_ROUTES_CACHE bootstrap/cache/routes-v7.php
$RUNWEB artisan view:cache
# Workers cache booted code — tell them to restart (no-op if none running).
$PHP artisan queue:restart || true

echo "==> [8/9] Fix ownership (belt-and-suspenders — see [7]: caches now build as www-data)"
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache

echo "==> [8b/9] Public-site country database (only when missing — the scheduler refreshes it monthly)"
# WEBSITE-I18N-GEO-1 P4: storage/ is not in git. The site works without the file (language default market).
if [ ! -f storage/app/geoip/dbip-country-lite.mmdb ]; then $RUNWEB artisan geoip:update || echo "  (geoip:update failed — continuing)"; fi

echo "==> [9/9] Reload PHP-FPM (clear OPcache)"
sudo systemctl reload php8.2-fpm

echo "==> DEPLOY COMPLETE"
