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

echo "==> [1/7] Pull latest code"
git pull --ff-only

echo "==> [2/7] Composer (no-op if dependencies unchanged)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> [3/7] Master database migrations"
$PHP artisan migrate --force

echo "==> [4/7] Register route permissions in the catalog"
$PHP artisan system:routes-sync

echo "==> [5/7] Per-tenant: migrations + Chart of Accounts + grant Owner all tenant permissions (resilient)"
$PHP artisan tinker --execute='
$names = \Illuminate\Support\Facades\DB::connection("master")->table("route_catalogs")->where("route_name","like","tenant.%")->pluck("route_name")->all();
$ok = 0; $skip = 0;
foreach (\App\Models\Master\Tenant::all() as $t) {
  try {
    app(\App\Services\Tenancy\TenancyManager::class)->activate($t);
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

echo "==> [6/7] Flush + rebuild caches"
$PHP artisan permission:cache-reset
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache

echo "==> [7/7] Reload PHP-FPM (clear OPcache)"
sudo systemctl reload php8.2-fpm

echo "==> DEPLOY COMPLETE"
