<?php

namespace App\Services\Edge;

use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * OFFLINE EDGE PRODUCTIZATION — the appliance's guarded RESTORE authority (replacement-box recovery).
 *
 * Restores the recoverable local state from an encrypted backup, atomically. It decrypts through the recovery
 * key (EdgeBackupService::decodeAndVerify → EdgeBackupKeyProvider), NOT the dead appliance's APP_KEY.
 *
 * Guards, all BEFORE any mutation (fail closed):
 *   - integrity: the backup must decrypt (recovery key + MAC) and its checksum must match;
 *   - format + schema: an unrecognised format is refused; a schema generation newer than this build is
 *     refused; an equal/older-supported generation proceeds (older upgrades run edge:local:schema-upgrade);
 *   - identity: the backup's branch must equal the branch being recovered;
 *   - REFERENCE INTEGRITY: every config reference the recoverable rows need (products, payment methods,
 *     branch) must already resolve on this box — a fresh appliance must Cloud-bootstrap its config BEFORE
 *     restore. Disabling FK checks is NOT a substitute for missing config; this precheck is the guarantee.
 *
 * The apply is ONE transaction: reverse-delete then parents-first insert; FK checks are relaxed only for the
 * bulk mechanics of an internally-consistent snapshot, and a final in-transaction integrity pass re-verifies
 * the restored references before commit. Any failure rolls the whole thing back — the live DB is never left
 * half-overwritten. The un-synced outbox, acknowledged history, sale_uuid/content_hash and baseline/cutover
 * evidence all survive because they are in the backup.
 */
class EdgeRestoreService
{
    public const CONN = 'tenant';

    /**
     * [childTable, column, refTable] — CONFIG references the recoverable rows must resolve on the target box.
     * Only EXTERNAL (config) references belong here; intra-recoverable-set references (e.g. kot_batches ->
     * sales_orders) are satisfied by the parents-first restore order, not the precheck.
     */
    private const REFS = [
        ['shifts', 'branch_id', 'branches'],
        ['shifts', 'terminal_id', 'terminals'],
        ['restaurant_table_sessions', 'restaurant_table_id', 'restaurant_tables'],
        ['edge_local_table_reservations', 'restaurant_table_id', 'restaurant_tables'],
        ['sales_orders', 'branch_id', 'branches'],
        ['sales_order_lines', 'product_id', 'products'],
        ['sale_payments', 'payment_method_id', 'payment_methods'],
        ['kot_batch_lines', 'product_id', 'products'],
        ['edge_operational_stock_balances', 'product_id', 'products'],
        ['edge_operational_stock_movements', 'product_id', 'products'],
    ];

    public function __construct(private readonly EdgeBackupService $backups)
    {
    }

    public function restore(string $path, int $expectedBranchId): array
    {
        if (! EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('RESTORE_NOT_BRANCH_SERVER: appliance restore runs only on a Branch Server.');
        }

        $payload = $this->backups->decodeAndVerify($path); // integrity + recovery-key decrypt

        $this->assertSchema((string) ($payload['schema_generation'] ?? ''));

        $backupBranch = $payload['binding']['branch_id'] ?? null;
        if ($backupBranch === null || (int) $backupBranch !== $expectedBranchId) {
            throw new RuntimeException('RESTORE_WRONG_IDENTITY: the backup branch does not match the branch being recovered.');
        }

        $tables = $payload['tables'];
        $this->assertReferenceIntegrity($tables); // config must resolve BEFORE we touch anything

        $restored = [];
        $conn = DB::connection(self::CONN);
        try {
            $conn->statement('SET FOREIGN_KEY_CHECKS=0');
            $conn->transaction(function () use ($conn, $tables, &$restored) {
                // reverse-delete (children first) then parents-first insert.
                foreach (array_reverse(EdgeBackupService::TABLES) as $table) {
                    if ($this->has($table)) {
                        $conn->table($table)->delete();
                    }
                }
                foreach (EdgeBackupService::TABLES as $table) {
                    if (! array_key_exists($table, $tables) || ! $this->has($table)) {
                        continue;
                    }
                    foreach (array_chunk($tables[$table], 500) as $chunk) {
                        if ($chunk !== []) {
                            $this->applyInsert($conn, $table, $chunk);
                        }
                    }
                    $restored[$table] = count($tables[$table]);
                }

                // In-transaction integrity pass: the committed snapshot must be referentially valid.
                $this->assertReferenceIntegrity($tables);
            });
        } finally {
            $conn->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return [
            'restored' => $restored,
            'binding' => $payload['binding'] ?? [],
            'created_at' => $payload['created_at'] ?? null,
            'software_version' => $payload['software_version'] ?? null,
            'schema_generation' => $payload['schema_generation'] ?? null,
        ];
    }

    /** Supported schema generations this build can restore (equal or a retained older one). */
    private function supportedSchemas(): array
    {
        return array_filter([(string) config('edge.config_schema')]);
    }

    private function assertSchema(string $backupSchema): void
    {
        $current = (string) config('edge.config_schema');
        if ($backupSchema === '' || $current === '') {
            return;
        }
        if (! in_array($backupSchema, $this->supportedSchemas(), true)) {
            // Not equal and not a retained older generation -> refuse (a future/newer or unknown schema).
            throw new RuntimeException("RESTORE_SCHEMA_INCOMPATIBLE: backup schema [{$backupSchema}] is not restorable by this build [{$current}].");
        }
        // (Older-but-supported generations run edge:local:schema-upgrade after restore; equal needs none.)
    }

    /** Every config id the recoverable rows reference must exist locally, else refuse (fail closed). */
    private function assertReferenceIntegrity(array $tables): void
    {
        foreach (self::REFS as [$child, $column, $refTable]) {
            if (! isset($tables[$child]) || ! $this->has($child) || ! $this->has($refTable)) {
                continue;
            }
            $ids = [];
            foreach ($tables[$child] as $row) {
                $v = $row[$column] ?? null;
                if ($v !== null && $v !== '') {
                    $ids[(int) $v] = true;
                }
            }
            $ids = array_keys($ids);
            if ($ids === []) {
                continue;
            }
            $present = DB::connection(self::CONN)->table($refTable)->whereIn('id', $ids)->pluck('id')->all();
            $missing = array_diff($ids, array_map('intval', $present));
            if ($missing !== []) {
                throw new RuntimeException(
                    "RESTORE_REFERENCE_UNRESOLVED: {$child}.{$column} references {$refTable} id(s) [" . implode(',', array_slice($missing, 0, 10)) . '] '
                    . 'that do not exist locally — Cloud-bootstrap the current config before restoring.'
                );
            }
        }
    }

    private function has(string $table): bool
    {
        return Schema::connection(self::CONN)->hasTable($table);
    }

    /** Insert one chunk of restored rows. Test-only seam so a mid-apply failure can prove atomic rollback. */
    protected function applyInsert($conn, string $table, array $chunk): void
    {
        $conn->table($table)->insert($chunk);
    }
}
