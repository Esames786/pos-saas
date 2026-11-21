<?php

/**
 * CP5 (stream B) — driver for the DISPOSABLE Cloud INSIDE the Windows Sandbox (derived from the proven CP4 driver).
 * Boots THIS worktree's app (edge09/b-wizard = edge09/integ + B fixes) with cp5-env.php and acts as the Cloud owner / operator:
 *
 *   php tests/Fixtures/EdgeWizardCp4/cp5-cloud.php setup                 master + tenant DBs, offline_edge plan, users, stock, live state
 *   php tests/Fixtures/EdgeWizardCp4/cp5-cloud.php pairing-code <file>   a pairing code (what the owner's "Generate pairing code" does)
 *   php tests/Fixtures/EdgeWizardCp4/cp5-cloud.php setup-code <file>     a setup code (Offline Edge page; mode follows the device)
 *   php tests/Fixtures/EdgeWizardCp4/cp5-cloud.php assertion <user> <file>  an enrollment assertion for one user (0.8.5 path)
 *   php tests/Fixtures/EdgeWizardCp4/cp5-cloud.php window <HH:MM> <HH:MM>|clear   device maintenance window (business tz)
 *   php tests/Fixtures/EdgeWizardCp4/cp5-cloud.php window-now|window-away   a window containing / excluding the current minute
 *   php tests/Fixtures/EdgeWizardCp4/cp5-cloud.php update-now            the Cloud device row's "Update now"
 *   php tests/Fixtures/EdgeWizardCp4/cp5-cloud.php device                the device row + lease (JSON)
 *   php tests/Fixtures/EdgeWizardCp4/cp5-cloud.php reset-rehearsal       revoke the paired device, drop registered releases (restart)
 *   php tests/Fixtures/EdgeWizardCp4/cp5-cloud.php artisan <args...>     any Cloud artisan command (edge:release:publish, approve-plan)
 *
 * Secrets (code / assertion files) are written into a user-only directory chosen by the caller and consumed by the appliance.
 */

require __DIR__ . '/cp5-env.php';
cp5_apply_env();

$root = CP5_TREE;
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Master\EdgeDevice;
use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Master\TenantDomain;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$cmd = $argv[1] ?? '';
$state = CP5_CLOUD_DIR . '/state.json';
$out = fn (array $doc) => print(json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$tenantFn = function (): Tenant {
    $t = Tenant::where('tenant_code', CP5_TENANT_CODE)->firstOrFail();
    app(TenancyManager::class)->activate($t);

    return $t;
};
$readState = fn () => json_decode((string) @file_get_contents($state), true) ?: [];
$writeState = fn (array $s) => file_put_contents($state, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$device = fn (Tenant $t) => EdgeDevice::active()->where('tenant_id', $t->id)->first();

/** Insert a row, filling every NOT NULL column without a default that the caller did not give (plausible values). */
$insert = function (string $table, array $values) {
    $c = DB::connection('tenant');
    $cols = $c->select('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    foreach ($cols as $col) {
        $n = $col->COLUMN_NAME;
        if (array_key_exists($n, $values) || $col->IS_NULLABLE === 'YES' || $col->COLUMN_DEFAULT !== null || str_contains((string) $col->EXTRA, 'auto_increment')) {
            continue;
        }
        $t = strtolower((string) $col->DATA_TYPE);
        $values[$n] = match (true) {
            in_array($t, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'double', 'float'], true) => 0,
            in_array($t, ['datetime', 'timestamp'], true) => now(),
            $t === 'date' => now()->toDateString(),
            $t === 'enum' => trim(explode(',', substr((string) $col->COLUMN_TYPE, 5, -1))[0], "'"),
            $t === 'json' => '[]',
            default => 'cp5',
        };
    }

    return $c->table($table)->insertGetId($values);
};

switch ($cmd) {
    case 'setup':
        $t0 = microtime(true);
        $pdo = new PDO('mysql:host=127.0.0.1;port=' . CP5_DB_PORT . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . CP5_MASTER_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        DB::purge('master');
        Artisan::call('migrate', ['--database' => 'master', '--force' => true]);
        DB::setDefaultConnection('master');
        (new Database\Seeders\MasterSeeder())->run();
        Artisan::call('system:routes-sync');   // the route catalog the subscription access engine resolves modules through
        $plan = Plan::where('code', 'restaurant_pro')->firstOrFail();
        $module = Module::where('key', 'offline_edge')->firstOrFail();
        PlanModule::updateOrCreate(['plan_id' => $plan->id, 'module_id' => $module->id], ['is_enabled' => true, 'limits' => null]);
        $tenant = Tenant::firstOrCreate(['tenant_code' => CP5_TENANT_CODE], [
            'business_name' => 'CP5 Rehearsal Biryani', 'owner_name' => 'CP5 Owner', 'owner_email' => 'owner@cp5edgewiz.test',
            'currency_code' => 'PKR', 'status' => 'active', 'trial_ends_at' => now()->addMonths(2), 'activated_at' => now(),
        ]);
        TenantDomain::updateOrCreate(['domain' => CP5_TENANT_CODE . '.cp5.test'], ['tenant_id' => $tenant->id, 'is_primary' => true, 'status' => 'active']);
        Subscription::updateOrCreate(['tenant_id' => $tenant->id], ['plan_id' => $plan->id, 'status' => 'trial', 'trial_ends_at' => now()->addMonths(2)]);
        $pdo->exec('DROP DATABASE IF EXISTS `pos_tenant_' . CP5_TENANT_CODE . '`');
        app(App\Services\Tenancy\TenantProvisioner::class)->provisionTenant($tenant, 'Cp4-Owner-Pass-' . Str::random(8));
        // no break: setup continues with the seed

    case 'seed':
        $t0 = $t0 ?? microtime(true);
        $t = $tenantFn();
        $c = DB::connection('tenant');
        $branch = Branch::orderBy('id')->firstOrFail();
        $owner = User::orderBy('id')->firstOrFail();
        $owner->forceFill(['employee_code' => 'CP5OWN', 'default_branch_id' => $branch->id, 'status' => 'active'])->save();
        $cashierRole = DB::connection('tenant')->table('roles')->where('guard_name', 'tenant')->where('name', 'like', '%ashier%')->value('name');
        $users = [$owner->id];
        foreach (['CP5C1' => 'Counter One', 'CP5C2' => 'Counter Two'] as $code => $name) {
            $u = User::firstOrCreate(['email' => strtolower($code) . '@cp5edgewiz.test'], [
                'name' => $name, 'password' => bcrypt(Str::random(20)), 'status' => 'active', 'employee_code' => $code, 'default_branch_id' => $branch->id,
            ]);
            if ($cashierRole) {
                $u->assignRole($cashierRole);
            }
            $users[] = $u->id;
        }
        $term1 = $c->table('terminals')->insertGetId(['branch_id' => $branch->id, 'code' => 'CP5-T1', 'name' => 'Counter 1', 'requires_shift' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $term2 = $c->table('terminals')->insertGetId(['branch_id' => $branch->id, 'code' => 'CP5-T2', 'name' => 'Counter 2', 'requires_shift' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $unit = $c->table('units')->where('code', 'pc')->value('id') ?: $c->table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cat = $c->table('categories')->insertGetId(['name' => 'CP5 Rice', 'slug' => 'cp5-rice-' . Str::random(4), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $product = $insert('products', ['category_id' => $cat, 'name' => 'Chicken Biryani', 'sku' => 'CP5-BIR', 'unit_id' => $unit, 'inventory_consumption_method' => 'stock_item',
            'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 450, 'created_at' => now(), 'updated_at' => now()]);
        $batch = $c->table('inventory_batches')->insertGetId(['batch_key' => "b-{$branch->id}-{$product}", 'branch_id' => $branch->id, 'product_id' => $product, 'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => 200, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $c->table('stock_balances')->insert(['balance_key' => "{$branch->id}-{$product}-0-{$batch}", 'branch_id' => $branch->id, 'product_id' => $product, 'inventory_batch_id' => $batch, 'quantity_on_hand' => 80, 'average_cost' => 200, 'created_at' => now(), 'updated_at' => now()]);
        $floor = $c->table('restaurant_floors')->insertGetId(['branch_id' => $branch->id, 'name' => 'Ground', 'created_at' => now(), 'updated_at' => now()]);
        $table = $c->table('restaurant_tables')->insertGetId(['branch_id' => $branch->id, 'restaurant_floor_id' => $floor, 'table_no' => 'T7', 'created_at' => now(), 'updated_at' => now()]);
        $bdate = app(App\Support\TenantClock::class)->currentBusinessDate($branch);

        // Live state the 0.9 standby must mirror (stream A, K1): one OPEN shift, one open table with an unpaid order, one held bill.
        $shift = $insert('shifts', ['shift_uuid' => (string) Str::ulid(), 'branch_id' => $branch->id, 'terminal_id' => $term1, 'status' => 'open', 'opened_at' => now(),
            'opened_by_user_id' => $users[1], 'opening_cash' => 1500, 'business_date' => $bdate, 'created_at' => now(), 'updated_at' => now()]);
        $session = $insert('restaurant_table_sessions', ['session_uuid' => (string) Str::ulid(), 'session_no' => 'TS-CP5-1', 'branch_id' => $branch->id, 'restaurant_table_id' => $table,
            'opened_by_user_id' => $users[1], 'opened_shift_id' => $shift, 'guest_count' => 4, 'status' => 'open', 'business_date' => $bdate, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $mkSale = function (array $v) use ($insert, $branch, $bdate, $term1, $product) {
            $id = $insert('sales_orders', array_merge(['sale_uuid' => (string) Str::ulid(), 'sale_no' => 'SO-CP5-' . Str::upper(Str::random(6)), 'branch_id' => $branch->id, 'order_source' => 'pos',
                'order_type' => 'quick_sale', 'sale_date' => now(), 'business_date' => $bdate, 'terminal_id' => $term1, 'subtotal' => 900, 'grand_total' => 900,
                'created_at' => now(), 'updated_at' => now()], $v));
            $insert('sales_order_lines', ['line_uuid' => (string) Str::ulid(), 'sales_order_id' => $id, 'product_id' => $product, 'product_name' => 'Chicken Biryani', 'quantity' => 2, 'unit_price' => 450, 'line_total' => 900, 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        };
        $tableOrder = $mkSale(['status' => 'held', 'order_type' => 'dine_in', 'shift_id' => $shift, 'restaurant_table_id' => $table, 'restaurant_table_session_id' => $session]);
        $held = $mkSale(['status' => 'held', 'order_type' => 'takeaway', 'shift_id' => $shift]);
        $paid = [];
        for ($i = 0; $i < 3; $i++) {
            $paid[] = $mkSale(['status' => 'paid', 'shift_id' => $shift, 'paid_amount' => 900]);
        }
        $s = ['tenant_id' => $t->id, 'tenant_code' => CP5_TENANT_CODE, 'branch_id' => $branch->id, 'users' => $users, 'employee_codes' => ['CP5OWN', 'CP5C1', 'CP5C2'],
            'terminals' => [$term1, $term2], 'product_id' => $product, 'open_shift_id' => $shift, 'table_session_id' => $session, 'table_order_id' => $tableOrder,
            'held_sale_id' => $held, 'paid_sales' => $paid, 'setup_seconds' => (int) (microtime(true) - $t0)];
        $writeState($s);
        $out(['result' => 'ok'] + $s);
        break;

    case 'pairing-code':
        $t = $tenantFn();
        $s = $readState();
        $issued = app(App\Services\Edge\EdgePairingService::class)->generateCode($t, Branch::findOrFail((int) $s['branch_id']), (int) $s['users'][0]);
        file_put_contents((string) $argv[2], $issued['code']);
        $out(['result' => 'ok', 'expires_at' => $issued['expires_at'] ?? null]);
        break;

    case 'setup-code':
        $t = $tenantFn();
        $s = $readState();
        $r = app(App\Services\Edge\Cloud\SetupSessionService::class)->create($t, Branch::findOrFail((int) $s['branch_id']), (int) $s['users'][0]);
        file_put_contents((string) $argv[2], (string) $r['code']);
        $out(['result' => 'ok', 'mode' => $r['mode'], 'expires_at' => $r['expires_at']]);
        break;

    case 'assertion':
        $t = $tenantFn();
        $s = $readState();
        $dev = $device($t);
        $a = app(App\Services\Edge\EdgeEnrollmentIssuer::class)->issue($t, (int) $s['branch_id'], $dev, User::findOrFail((int) $argv[2]), (int) $s['users'][0]);
        file_put_contents((string) $argv[3], json_encode($a, JSON_UNESCAPED_SLASHES));
        $out(['result' => 'ok', 'user_id' => (int) $argv[2]]);
        break;

    case 'window':
    case 'window-now':
    case 'window-away':
        $t = $tenantFn();
        $dev = $device($t);
        $profiles = app(App\Services\Edge\Cloud\DeviceProfileService::class);
        $tz = $profiles->maintenanceWindow($dev)['tz'];
        $now = now()->setTimezone($tz);
        if ($cmd === 'window-now') {
            [$start, $end] = [$now->copy()->subMinutes(2)->format('H:i'), $now->copy()->addMinutes(90)->format('H:i')];
        } elseif ($cmd === 'window-away') {
            [$start, $end] = [$now->copy()->addHours(6)->format('H:i'), $now->copy()->addHours(7)->format('H:i')];
        } elseif (($argv[2] ?? '') === 'clear') {
            [$start, $end] = [null, null];
        } else {
            [$start, $end] = [$argv[2], $argv[3]];
        }
        $profiles->setMaintenanceWindow($dev, $start, $end, 0);
        $out(['result' => 'ok', 'window' => $profiles->maintenanceWindow($dev->fresh()), 'now_in_tz' => $now->format('H:i')]);
        break;

    case 'update-now':
        $t = $tenantFn();
        app(App\Services\Edge\Cloud\DeviceProfileService::class)->requestUpdateNow($device($t), 0);
        $out(['result' => 'ok']);
        break;

    case 'device':
        $t = $tenantFn();
        $dev = EdgeDevice::where('tenant_id', $t->id)->orderByDesc('id')->first();
        $lease = DB::connection('tenant')->table('edge_branch_authority_leases')->first();
        $out(['device' => $dev ? array_intersect_key($dev->toArray(), array_flip(['public_uuid', 'installation_uuid', 'status', 'app_version', 'reported_version', 'install_phase', 'install_phase_ok',
            'install_phase_message', 'install_phase_at', 'update_now_requested_at', 'maintenance_window_start', 'maintenance_window_end', 'lan_hostname', 'lan_ip', 'lan_https_port',
            'last_authenticated_at', 'last_seen_at', 'lan_ca_sha256', 'lan_reported_at'])) + ['lan_ca_pem_present' => (string) ($dev->lan_ca_pem ?? '') !== ''] : null, 'lease' => $lease]);
        break;

    case 'reset-rehearsal':
        // Restart the appliance side of the rehearsal on THIS disposable Cloud: revoke the paired device (the owner's
        // "Revoke") and drop every registered release (rows + zips under storage/app/edge-releases). Tenant data stays.
        $t = $tenantFn();
        $s = $readState();
        $revoked = [];
        foreach (EdgeDevice::active()->where('tenant_id', $t->id)->get() as $dev) {
            app(App\Services\Edge\EdgePairingService::class)->revokeDevice($t, $dev, (int) $s['users'][0], 'cp5 rehearsal restart');
            $revoked[] = $dev->public_uuid;
        }
        $releases = App\Models\Master\EdgeRelease::query()->get();
        foreach ($releases as $r) {
            @unlink(storage_path('app/' . $r->storage_path));
            $r->delete();
        }
        $out(['result' => 'ok', 'revoked' => $revoked, 'releases_removed' => $releases->pluck('version')->all()]);
        break;

    case 'public-keys':
        // PUBLIC material only: the Cloud enrollment public key (installer -EnrollmentPublicKey) and the update key id.
        $k = cp5_keys();
        $out(['enrollment_public' => $k['enrollment_public'], 'update_key_id' => App\Services\Edge\EdgeSigningKeyStore::keyId((string) config('edge.update.public_key'))]);
        break;

    case 'lease':
        // The Cloud's view of the branch authority lease (Cloud clock): the fencing proof of CP5 scenario c/d.
        $t = $tenantFn();
        $lease = DB::connection('tenant')->table('edge_branch_authority_leases')->first();
        $now = now();
        $out(['now' => $now->format('Y-m-d H:i:s'), 'lease' => $lease, 'expires_in_s' => $lease && $lease->expires_at ? \Illuminate\Support\Carbon::parse($lease->expires_at)->getTimestamp() - $now->getTimestamp() : null]);
        break;

    case 'counter-wizard':
        // Stream D's counter self-service wizard exactly as the Cloud page builds it (owner user, no self-enrollment).
        $t = $tenantFn();
        $s = $readState();
        $owner = User::findOrFail((int) $s['users'][0]);
        $bundles = app(App\Services\Edge\Cloud\CounterBundleService::class);
        ['branch' => $branch, 'device' => $dev] = $bundles->contextFor($t, $owner);
        $online = 'https://' . CP5_TENANT_CODE . '.cp5.test/pos';   // the renderer accepts only an https Online POS URL
        $context = $bundles->wizardContext($t, $owner, $branch, $dev, $online, false);
        $renderer = App\Services\Edge\Cloud\CounterSetupRendererAdapter::resolve();
        if (! $renderer) {
            fwrite(STDERR, "no counter setup renderer on this Cloud\n");
            exit(1);
        }
        $script = $renderer->renderWindowsWizard(app(App\Services\Edge\Cloud\DeviceProfileService::class)->lanProfile($dev), $online, (string) $branch->name, $context);
        file_put_contents((string) $argv[2], $script);
        $out(['result' => 'ok', 'bytes' => strlen($script), 'file_name' => App\Services\Edge\Cloud\CounterSetupRendererAdapter::wizardFileNameFor($renderer, (string) $branch->name, (int) $branch->id)]);
        break;

    case 'artisan':
        // A command line (forward-slash paths); Artisan parses it exactly like the CLI.
        $code = Artisan::call(implode(" ", array_map(fn ($x) => preg_match("/\s/", $x) ? "\"" . $x . "\"" : $x, array_slice($argv, 2))));
        echo Artisan::output();
        exit($code);

    default:
        fwrite(STDERR, "unknown command [{$cmd}]\n");
        exit(2);
}

