<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeBootstrapPullClient;
use App\Services\Edge\EdgeLocalBootstrapImporter;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * P4 FIRST-INSTALL — pull the Cloud bootstrap snapshot for THIS paired device, import it into the local DB (the
 * proven EdgeLocalBootstrapImporter — same path as the file-based edge:local:bootstrap-import) and acknowledge it.
 *
 *   php artisan edge:local:bootstrap-pull [--cloud-url=https://…] [--save=C:\path\package.json]
 *
 * Requires the device identity from edge:local:pair (config edge.sync.device_id/secret). Never prints a secret.
 */
class EdgeLocalBootstrapPullCommand extends Command
{
    protected $signature = 'edge:local:bootstrap-pull
        {--cloud-url= : Cloud base URL (default EDGE_CLOUD_BASE_URL)}
        {--save= : Also write the assembled package JSON to this path (diagnostics)}';

    protected $description = 'Pull, verify, import and acknowledge the Cloud bootstrap snapshot for this appliance.';

    public function handle(EdgeBootstrapPullClient $client, EdgeLocalBootstrapImporter $importer): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:bootstrap-pull only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        if (($reason = EdgeLocalDatabase::unsafeReason()) !== null) {
            $this->error("Refusing to import: {$reason}.");

            return self::FAILURE;
        }
        $cloud = rtrim((string) ($this->option('cloud-url') ?: env('EDGE_CLOUD_BASE_URL', '')), '/');
        if ($cloud === '') {
            $this->error('A Cloud base URL is required (--cloud-url or EDGE_CLOUD_BASE_URL).');

            return self::FAILURE;
        }
        if (trim((string) config('edge.sync.device_id', '')) === '' || trim((string) config('edge.sync.device_secret', '')) === '') {
            $this->error('This appliance has no device identity yet — run edge:local:pair first.');

            return self::FAILURE;
        }
        EdgeLocalDatabase::useAsTenantConnection();

        try {
            $this->info('Pulling the bootstrap snapshot from ' . $cloud . ' …');
            $pulled = $client->pull($cloud);
            $manifest = $pulled['manifest'];
            $this->line(sprintf('  snapshot %s · schema %s · %d sections · config revision %s',
                $pulled['snapshot_uuid'], (string) ($manifest['schema_version'] ?? '?'), count($pulled['sections']), (string) ($manifest['config_revision'] ?? '?')));
            if ($this->option('save')) {
                file_put_contents((string) $this->option('save'), json_encode(['manifest' => $manifest, 'sections' => $pulled['sections']], JSON_UNESCAPED_SLASHES));
            }
            $meta = $importer->import(['manifest' => $manifest, 'sections' => $pulled['sections']]);
            $ack = $client->acknowledge($cloud, $pulled['snapshot_uuid'], (string) $manifest['schema_version'], strtolower((string) $manifest['manifest_hash']), $pulled['receipts']);
        } catch (Throwable $e) {
            Log::warning('[edge-bootstrap-audit] appliance_bootstrap_pull_failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
            $this->error('Bootstrap pull failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        Log::info('[edge-bootstrap-audit] appliance_bootstrap_pulled', ['snapshot' => $pulled['snapshot_uuid'], 'branch_id' => $meta->branch_id, 'epoch' => $meta->activation_epoch]);
        $this->info('Bootstrap applied and acknowledged. Appliance binding:');
        $this->line('  tenant   : ' . $meta->tenant_code . ' (#' . $meta->tenant_id . ')');
        $this->line('  branch   : #' . $meta->branch_id);
        $this->line('  device   : ' . $meta->device_uuid);
        $this->line('  epoch    : ' . $meta->activation_epoch);
        $this->line('  ack      : ' . (string) ($ack['status'] ?? $ack['result'] ?? 'ok'));

        return self::SUCCESS;
    }
}
