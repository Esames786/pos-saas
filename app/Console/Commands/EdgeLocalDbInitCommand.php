<?php

namespace App\Console\Commands;

use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section U) — provision / migrate the Branch Server's own local database.
 *
 *   php artisan edge:local:db-init [--fresh]
 *
 * Runs the REAL tenant migrations plus the Edge-only migrations (database/migrations/edge) against
 * the dedicated Edge-local database — never the Cloud master or a Cloud tenant DB. Fails closed
 * unless this is a branch_server runtime pointed at a loopback, Edge-named database
 * (EdgeLocalDatabase safety guard). Idempotent: re-running only applies new migrations.
 *
 * This command is on the Branch Server CLI allowlist; it is NOT run on Cloud during deployment
 * (deploy.sh only ships the code — see DEPLOYMENT POLICY).
 */
class EdgeLocalDbInitCommand extends Command
{
    protected $signature = 'edge:local:db-init {--fresh : DROP and rebuild the Edge-local schema (destructive, local DB only)}';

    protected $description = 'Provision/migrate the Branch Server local database (tenant + edge migrations), fail-closed to a loopback Edge DB.';

    public function handle(): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:db-init only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }

        // Fail closed: branch_server + loopback host + dedicated Edge database name.
        if (($reason = EdgeLocalDatabase::unsafeReason()) !== null) {
            $this->error("Refusing to initialise the Edge-local database: {$reason}.");

            return self::FAILURE;
        }

        // Point the `tenant` connection at the Edge-local database for this process.
        EdgeLocalDatabase::useAsTenantConnection();
        $host = EdgeLocalDatabase::host();
        $database = EdgeLocalDatabase::database();
        $this->info("Edge-local target: {$database} @ {$host}");

        // Create the database if needed via a server-level PDO (no DB selected). Guarded again.
        $this->ensureDatabaseExists($database);

        $fresh = (bool) $this->option('fresh');

        // Real tenant schema (full tenant migration set — Cloud-only tables may exist empty locally).
        $tenantCode = Artisan::call($fresh ? 'migrate:fresh' : 'migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ], $this->output);
        if ($tenantCode !== 0) {
            $this->error('Tenant migrations failed on the Edge-local database.');

            return self::FAILURE;
        }

        // Edge-only migrations (edge_local_meta, …). Never run on Cloud tenant DBs.
        $edgeCode = Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/edge',
            '--force' => true,
        ], $this->output);
        if ($edgeCode !== 0) {
            $this->error('Edge migrations failed on the Edge-local database.');

            return self::FAILURE;
        }

        $this->info('Edge-local database initialised.');

        return self::SUCCESS;
    }

    private function ensureDatabaseExists(?string $database): void
    {
        if (! EdgeLocalDatabase::isEdgeDatabaseName($database)) {
            // Defence-in-depth: never CREATE a non-Edge database.
            $this->error("Refusing to create non-Edge database [{$database}].");
            exit(self::FAILURE);
        }

        $c = config('database.connections.' . EdgeLocalDatabase::CONNECTION);
        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4",
            $c['username'],
            $c['password'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        DB::purge('tenant');
        DB::reconnect('tenant');
    }
}
