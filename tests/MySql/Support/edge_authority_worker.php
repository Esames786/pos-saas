<?php
/**
 * OFFLINE EDGE — P0 BRANCH AUTHORITY LEASE partition worker.
 *
 * ONE independent OS process = ONE side of the partition:
 *   ROLE=cloud          the Cloud (tenant DB = EDGE_TEST_TENANT_DB)     modes: cloud:heartbeat | cloud:fence | cloud:handback | cloud:release
 *   ROLE=branch_server  the appliance (tenant DB = EDGE_WORKER_DB)       modes: edge:ack | edge:heartbeat-fail | edge:gates | edge:takeover | edge:sale-check | edge:state | edge:handback
 *
 * Every process reasons on its own clock and its own database; the only "wire" between them is what the test
 * carries by hand (a heartbeat the Cloud accepted → an ack the appliance recorded). A partition is simply the
 * absence of that carry. Prints ONE line: OK:… or ERR:<class>:<message>.
 */
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Master\EdgeDevice;
use App\Models\Tenant\Branch;
use App\Services\Edge\BranchOperatingModeService;
use App\Services\Edge\EdgeAuthorityLeaseService;
use App\Services\Edge\EdgeAuthorityService;
use Illuminate\Support\Facades\DB;

$role = getenv('ROLE') ?: 'cloud';
$db = getenv('EDGE_WORKER_DB') ?: (getenv('EDGE_TEST_TENANT_DB') ?: '');
if (stripos($db, 'test') === false) {
    fwrite(STDERR, "REFUSE non-test db\n");
    exit(2);
}
config([
    'app.role' => $role,
    'database.connections.tenant.database' => $db,
    'edge.authority.ttl_seconds' => (int) (getenv('EDGE_AUTHORITY_TTL') ?: 3),
    'edge.authority.skew_margin_seconds' => (int) (getenv('EDGE_AUTHORITY_SKEW_MARGIN') ?: 2),
    'edge.authority.heartbeat_url' => getenv('EDGE_AUTHORITY_HEARTBEAT_URL') ?: 'http://127.0.0.1:9/api/edge/authority/heartbeat',
    'edge.authority.handback_url' => getenv('EDGE_AUTHORITY_HANDBACK_URL') ?: 'http://127.0.0.1:9/api/edge/authority/handback',
    'edge.authority.require_confirmation' => true,
    'edge.sync.device_id' => getenv('EDGE_DEVICE_UUID') ?: 'dev-partition',
    'edge.sync.device_secret' => 'secret',
    'edge.sync.connect_timeout' => 1, 'edge.sync.timeout' => 2,
]);
if ($role === 'branch_server') {
    config(['database.connections.master.database' => 'nonexistent_master_edge_authority_partition']); // the appliance never needs the master DB
    DB::purge('master');
}
DB::purge('tenant');
DB::setDefaultConnection('tenant');

$mode = $argv[1] ?? '';
try {
    switch ($mode) {
        // ── Cloud side ─────────────────────────────────────────────────────────────────────────────
        case 'cloud:heartbeat': // cloud:heartbeat <branch_id> <device_uuid> <seq> <edge_state>
            $device = new EdgeDevice(['public_uuid' => (string) $argv[3], 'branch_id' => (int) $argv[2], 'tenant_id' => 1]);
            $r = app(EdgeAuthorityLeaseService::class)->heartbeat($device, (int) $argv[4], (string) $argv[5]);
            echo 'OK:holder=' . $r['holder'] . ':fenced=' . ($r['fenced'] ? 1 : 0) . ':expires_in=' . $r['expires_in_seconds'] . "\n";
            exit(0);
        case 'cloud:fence': // cloud:fence <branch_id>  — what a Cloud POS write (mobile/desktop) meets
            $branch = Branch::on('tenant')->findOrFail((int) $argv[2]);
            app(BranchOperatingModeService::class)->assertSaleMutationAllowed($branch);
            echo "OK:cloud-write-allowed\n";
            exit(0);
        case 'cloud:handback': // cloud:handback <branch_id> <device_uuid> <pending> <failed>
            $device = new EdgeDevice(['public_uuid' => (string) $argv[3], 'branch_id' => (int) $argv[2], 'tenant_id' => 1]);
            $r = app(EdgeAuthorityLeaseService::class)->handback($device, (int) $argv[4], (int) $argv[5]);
            echo 'OK:holder=' . $r['holder'] . "\n";
            exit(0);
        case 'cloud:release': // cloud:release <branch_id> <reason>
            $r = app(EdgeAuthorityLeaseService::class)->release((int) $argv[2], (string) $argv[3], 'test-operator');
            echo 'OK:holder=' . ($r['holder'] ?? 'none') . "\n";
            exit(0);

        // ── Appliance side ─────────────────────────────────────────────────────────────────────────
        case 'edge:ack': // edge:ack <ttl>  — the appliance records an acknowledged heartbeat (what a successful wire delivers)
            $meta = app(\App\Services\Edge\EdgeBranchContext::class)->requireCurrent();
            $meta->forceFill(['authority_heartbeat_seq' => (int) $meta->authority_heartbeat_seq + 1, 'authority_last_ack_at' => now(), 'authority_lease_ttl_seconds' => (int) $argv[2]])->save();
            echo 'OK:acked:seq=' . $meta->authority_heartbeat_seq . "\n";
            exit(0);
        case 'edge:heartbeat-fail': // the real transport against an unreachable Cloud — must record a failure, never throw, never change state
            $r = app(EdgeAuthorityService::class)->heartbeat();
            echo ($r['ok'] ? 'OK:unexpected-success' : 'OK:failure-recorded:state=' . $r['state']) . "\n";
            exit(0);
        case 'edge:gates':
            $svc = app(EdgeAuthorityService::class);
            echo 'OK:' . json_encode(['gates' => $svc->gates(), 'can' => $svc->canTakeOver(), 'lapsed' => $svc->cloudLeaseLapsedLocally()]) . "\n";
            exit(0);
        case 'edge:takeover': // edge:takeover [confirm]
            $r = app(EdgeAuthorityService::class)->takeOver(($argv[2] ?? '') === 'confirm', 'supervisor');
            echo 'OK:state=' . $r['state'] . "\n";
            exit(0);
        case 'edge:sale-check': // what a local cashier mutation meets
            app(EdgeAuthorityService::class)->assertLocalMutationAllowed();
            echo "OK:edge-write-allowed\n";
            exit(0);
        case 'edge:state':
            echo 'OK:' . json_encode(app(EdgeAuthorityService::class)->cashierState()) . "\n";
            exit(0);
        case 'edge:handback': // the REAL appliance handback over the real transport (unreachable Cloud here) — must stay fenced, never flip to standby
            $r = app(EdgeAuthorityService::class)->handback('supervisor');
            echo 'OK:state=' . $r['state'] . "\n";
            exit(0);
        default:
            fwrite(STDERR, "unknown mode {$mode}\n");
            exit(2);
    }
} catch (\Throwable $e) {
    echo 'ERR:' . get_class($e) . ':' . str_replace("\n", ' ', $e->getMessage()) . "\n";
}
