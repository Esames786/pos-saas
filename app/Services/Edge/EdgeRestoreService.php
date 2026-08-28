<?php

namespace App\Services\Edge;

use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * OFFLINE EDGE PRODUCTIZATION — the appliance's guarded RESTORE authority (replacement-box recovery).
 *
 * Restores the recoverable local state from an encrypted backup, atomically. Guards (fail closed):
 *   - integrity: the backup must decrypt and its checksum must match (a tampered/partial file is refused);
 *   - format/schema: an unrecognised format or an incompatible schema generation is refused (the non-
 *     destructive schema-upgrade path is a separate productization step);
 *   - identity: the backup's branch must equal the branch this box is being recovered for — you can never
 *     restore one branch's data onto another.
 *
 * The apply is ONE transaction: FK checks are relaxed for the bulk load (the snapshot is internally
 * consistent), every recoverable table is replaced from the backup, and a failure anywhere rolls the whole
 * thing back — the live DB is never left half-overwritten. The un-synced outbox, acknowledged history,
 * sale_uuid/content_hash, and baseline/cutover evidence all survive because they are IN the backup.
 */
class EdgeRestoreService
{
    public const CONN = 'tenant';

    public function __construct(private readonly EdgeBackupService $backups)
    {
    }

    /**
     * Restore a backup for a specific branch. Returns a summary of what was restored.
     */
    public function restore(string $path, int $expectedBranchId): array
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('RESTORE_NOT_BRANCH_SERVER: appliance restore runs only on a Branch Server.');
        }

        $payload = $this->backups->decodeAndVerify($path); // integrity + format

        // Schema compatibility (V1 strict — the upgrade-on-restore path is a later productization step).
        $backupSchema = (string) ($payload['schema_generation'] ?? '');
        $currentSchema = (string) config('edge.config_schema');
        if ($backupSchema !== '' && $currentSchema !== '' && $backupSchema !== $currentSchema) {
            throw new RuntimeException("RESTORE_SCHEMA_INCOMPATIBLE: backup schema [{$backupSchema}] != current [{$currentSchema}].");
        }

        // Identity: never restore another branch's data.
        $backupBranch = $payload['binding']['branch_id'] ?? null;
        if ($backupBranch === null || (int) $backupBranch !== $expectedBranchId) {
            throw new RuntimeException('RESTORE_WRONG_IDENTITY: the backup branch does not match the branch being recovered.');
        }

        $tables = $payload['tables'];
        $restored = [];

        $conn = DB::connection(self::CONN);
        try {
            $conn->statement('SET FOREIGN_KEY_CHECKS=0');
            $conn->transaction(function () use ($conn, $tables, &$restored) {
                // Replace each recoverable table from the backup (parents-first insert order in EdgeBackupService::TABLES).
                foreach (EdgeBackupService::TABLES as $table) {
                    if (! array_key_exists($table, $tables) || ! Schema::connection(self::CONN)->hasTable($table)) {
                        continue;
                    }
                    $conn->table($table)->delete();
                    $rows = $tables[$table];
                    foreach (array_chunk($rows, 500) as $chunk) {
                        if ($chunk !== []) {
                            $conn->table($table)->insert($chunk);
                        }
                    }
                    $restored[$table] = count($rows);
                }
            });
        } finally {
            $conn->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return [
            'restored' => $restored,
            'binding' => $payload['binding'] ?? [],
            'created_at' => $payload['created_at'] ?? null,
            'software_version' => $payload['software_version'] ?? null,
        ];
    }
}
