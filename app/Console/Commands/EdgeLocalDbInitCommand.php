<?php

namespace App\Console\Commands;

use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section U + fix 2) — provision / migrate the Branch Server's own local DB.
 *
 *   php artisan edge:local:db-init [--fresh]
 *
 * Runs the REAL tenant migrations plus the Edge-only migrations (database/migrations/edge) against
 * the dedicated Edge-local database — never the Cloud master or a Cloud tenant DB. Fails closed
 * unless this is a branch_server runtime pointed at a loopback, Edge-named database.
 *
 * IMPORTANT: it drives Laravel's Migrator DIRECTLY (not Artisan::call('migrate')). Raw `migrate` /
 * `migrate:fresh` / `db:wipe` stay DENIED on a Branch Server by the console boundary; this guarded
 * command is the only sanctioned way to build the appliance schema, and its internal migration run
 * does not dispatch (and therefore is not blocked by) the CLI boundary.
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

        // Bind the `tenant` connection to the Edge-local DB (lazy — no socket opened yet).
        EdgeLocalDatabase::useAsTenantConnection();
        $host = EdgeLocalDatabase::host();
        $database = EdgeLocalDatabase::database();
        $this->info("Edge-local target: {$database} @ {$host}");

        $this->ensureDatabaseExists($database);

        try {
            $this->runEdgeMigrations((bool) $this->option('fresh'));
        } catch (Throwable $e) {
            $this->error('Edge-local migration failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Edge-local database initialised.');

        return self::SUCCESS;
    }

    /**
     * Run the real tenant schema + Edge-only migrations against the Edge-local database using the
     * Migrator directly — bypassing the Artisan `migrate` command (which the CLI boundary denies).
     */
    private function runEdgeMigrations(bool $fresh): void
    {
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');

        $migrator->usingConnection('tenant', function () use ($migrator, $fresh) {
            if ($fresh) {
                // Destructive rebuild — LOCAL Edge DB only (the safety guard already validated the target).
                DB::connection('tenant')->getSchemaBuilder()->dropAllTables();
                DB::purge('tenant');
            }

            if (! $migrator->repositoryExists()) {
                $migrator->getRepository()->createRepository();
            }

            $migrator->setOutput($this->output);
            // Real tenant schema first, then the Edge-only migrations (edge_local_meta …).
            $migrator->run([database_path('migrations/tenant')]);
            $migrator->run([database_path('migrations/edge')]);
        });
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
        DB::purge('tenant'); // next query connects to the now-existing DB
    }
}
