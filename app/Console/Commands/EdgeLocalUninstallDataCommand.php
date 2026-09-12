<?php

namespace App\Console\Commands;

use App\Models\Edge\EdgeSyncOutbox;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * P4 §15 — the EXPLICIT data-removal step of an uninstall. Uninstall-EdgeAppliance.ps1 never touches the local
 * database, the outbox, the backups or the recovery information by itself; only this command drops the LOCAL Edge
 * database, and only when the operator typed the confirmation phrase. It REFUSES while any event is still unsynced
 * (pending / leased / permanently refused) unless --force-lose-pending is also given — losing unsynced sales is a
 * deliberate, audited decision, never a side effect of "uninstall".
 *
 *   php artisan edge:local:uninstall-data --confirm="REMOVE ALL BRANCH DATA"
 */
class EdgeLocalUninstallDataCommand extends Command
{
    public const PHRASE = 'REMOVE ALL BRANCH DATA';

    protected $signature = 'edge:local:uninstall-data
        {--confirm= : The exact confirmation phrase: REMOVE ALL BRANCH DATA}
        {--force-lose-pending : Also drop when unsynced events exist (they are LOST — audited)}';

    protected $description = 'Drop the LOCAL Edge database at uninstall (explicit typed confirmation; refuses while events are unsynced).';

    public function handle(): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:uninstall-data only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        if (($reason = EdgeLocalDatabase::unsafeReason()) !== null) {
            $this->error("Refusing: {$reason}.");

            return self::FAILURE;
        }
        if ((string) $this->option('confirm') !== self::PHRASE) {
            $this->error('Refusing: pass --confirm="' . self::PHRASE . '" to remove the local database. Nothing was changed.');

            return self::FAILURE;
        }
        EdgeLocalDatabase::useAsTenantConnection();
        $database = (string) EdgeLocalDatabase::database();
        $open = 0;
        $failed = 0;
        try {
            $open = (int) DB::connection('tenant')->table('edge_sync_outbox')->whereIn('state', [EdgeSyncOutbox::STATE_PENDING, EdgeSyncOutbox::STATE_LEASED])->count();
            $failed = (int) DB::connection('tenant')->table('edge_sync_outbox')->where('state', EdgeSyncOutbox::STATE_FAILED_PERMANENT)->count();
        } catch (Throwable) {
            // no schema → nothing to protect
        }
        if (($open > 0 || $failed > 0) && ! $this->option('force-lose-pending')) {
            $this->error("Refusing: {$open} unsynced and {$failed} permanently-refused event(s) are still in the outbox. Hand back / sync first, or pass --force-lose-pending to lose them deliberately.");

            return self::FAILURE;
        }
        Log::warning('[edge-uninstall-audit] local_database_dropped', ['database' => $database, 'lost_pending' => $open, 'lost_failed_permanent' => $failed, 'host' => gethostname() ?: null]);
        try {
            DB::purge('tenant');
            $c = config('database.connections.' . EdgeLocalDatabase::CONNECTION);
            $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if (! EdgeLocalDatabase::isEdgeDatabaseName($database)) {
                $this->error("Refusing to drop non-Edge database [{$database}].");

                return self::FAILURE;
            }
            $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '', $database) . '`');
        } catch (Throwable $e) {
            $this->error('Drop failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->info("Local Edge database [{$database}] removed" . ($open + $failed > 0 ? " — {$open} unsynced / {$failed} refused event(s) were LOST by explicit choice." : '.'));

        return self::SUCCESS;
    }
}
