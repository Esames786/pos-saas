<?php

/**
 * OFFLINE-SYNC-ENGINE-1B true-overlap proof — a separate OS process that applies ONE real config refresh
 * through the REAL EdgeLocalBootstrapImporter (-> EdgeLocalConfigRefreshApplier) against the Edge test DB.
 * If READY_FILE + RELEASE_FILE are set, it PAUSES inside the refresh transaction (at beforeConfigCommit —
 * holding the meta X-lock + every config-row X-lock, revision bumped but uncommitted) until the test
 * releases it, so a concurrent paid sale genuinely serializes on the refresh's real locks. No pause env =>
 * a plain refresh.
 *
 * Env: EDGE_TEST_TENANT_DB (must contain 'edge' + 'test'), TENANT_DB_*, READY_FILE?, RELEASE_FILE?.
 * Args: <package json path>
 * Prints: OK:refresh:<last_applied_config_revision> | ERR:<class>:<message>
 */

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Edge\EdgeLocalBootstrapImporter;
use App\Services\Edge\EdgeLocalConfigRefreshApplier;
use Illuminate\Support\Facades\DB;

$db = getenv('EDGE_TEST_TENANT_DB') ?: '';
if (stripos($db, 'test') === false || stripos($db, 'edge') === false) {
    fwrite(STDERR, "REFUSE: not an edge test database\n");
    exit(2);
}
config([
    'app.role' => 'branch_server',
    'database.connections.tenant.database' => $db,
    'database.connections.edge_local.database' => $db,
    'database.connections.edge_local.host' => '127.0.0.1',
    'database.connections.master.database' => 'nonexistent_master_edge_refresh_pause',
]);
DB::purge('tenant');
DB::setDefaultConnection('tenant');

$readyFile = getenv('READY_FILE') ?: '';
$releaseFile = getenv('RELEASE_FILE') ?: '';

// Bind a pausing applier subclass so the REAL importer resolves it (constructor injection unchanged).
app()->bind(EdgeLocalConfigRefreshApplier::class, function () use ($readyFile, $releaseFile) {
    $applier = new class extends EdgeLocalConfigRefreshApplier {
        public string $readyFile = '';
        public string $releaseFile = '';

        protected function beforeConfigCommit(): void
        {
            if ($this->readyFile === '' || $this->releaseFile === '') {
                return;
            }
            file_put_contents($this->readyFile, '1'); // "refresh holds meta + all config locks, uncommitted"
            $deadline = microtime(true) + 60;
            while (! is_file($this->releaseFile)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('refresh pause timeout waiting for release');
                }
                usleep(3000);
            }
        }
    };
    $applier->readyFile = $readyFile;
    $applier->releaseFile = $releaseFile;

    return $applier;
});

try {
    $package = json_decode((string) file_get_contents((string) ($argv[1] ?? '')), true);
    if (! is_array($package)) {
        throw new RuntimeException('package file unreadable');
    }
    $meta = app(EdgeLocalBootstrapImporter::class)->import($package);
    echo 'OK:refresh:' . $meta->last_applied_config_revision . "\n";
} catch (Throwable $e) {
    echo 'ERR:' . get_class($e) . ':' . $e->getMessage() . "\n";
    exit(1);
}
