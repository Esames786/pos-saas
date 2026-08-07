<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * EDGE-IDENTITY-1 — add the canonical CROSS-SYSTEM operational identity columns.
 *
 * Cloud and Branch Server (Edge) databases will hold the SAME business object under DIFFERENT numeric ids.
 * These ULID columns are the durable, immutable, globally-unique application identity by which the two
 * systems agree "this is the same sale / line / payment / KOT line / table session / approval / customer".
 * Numeric primary keys stay LOCAL ONLY. See {@see \App\Support\Edge\EdgeIdentity}.
 *
 * Staged, production-safe (mirrors the shipped `shifts.shift_uuid` migration):
 *   1. add the column NULLABLE (additive, non-locking on modern MySQL);
 *   2. idempotent generate-once, memory-bounded backfill (only rows still NULL, batched by id — each gets a fresh ULID; already-populated rows are never changed);
 *   3. add the UNIQUE index once every existing row is populated.
 * The column is deliberately left NULLABLE at the DB level (not NOT NULL): the model trait
 * {@see \App\Models\Concerns\HasCanonicalIdentity} guarantees every NEW row is generated non-null, and the
 * backfill leaves zero nulls — so a NOT NULL constraint is a documented later-stage step, avoiding a risky
 * whole-table rewrite here. A nullable UNIQUE index still enforces uniqueness of all real values (MySQL
 * permits multiple NULLs, which exist only transiently during backfill).
 *
 * REUSED identities are intentionally NOT touched here: `shifts.shift_uuid` (ULID),
 * `kot_batches.event_uuid` + `sales_order_line_cancellations.event_uuid` (UUID v4) already exist and are
 * already unique.
 *
 * APPLIANCE-UPDATE-SAFE (locked contract AE12): this migration is designed to run on an EXISTING Branch
 * Server database during a future in-place UPDATE without ever wiping it. It is purely additive + backfill:
 * it NEVER drops the database, truncates, `migrate:fresh`es, reseeds, or re-imports a bootstrap; existing
 * operational rows are preserved and only NULL identities are filled. Every step is guarded (hasColumn /
 * whereNull / index-exists), so an interrupted run is SAFELY RE-RUNNABLE — Laravel only records the
 * migration once up() finishes, and re-executing up() from the top is idempotent.
 */
return new class extends Migration
{
    /** table => [column, unique-index-name] — all ULID canonical identities added by this migration. */
    private array $identities = [
        'sales_orders'              => ['sale_uuid', 'sales_orders_sale_uuid_unique'],
        'sales_order_lines'         => ['line_uuid', 'sales_order_lines_line_uuid_unique'],
        'sale_payments'             => ['payment_uuid', 'sale_payments_payment_uuid_unique'],
        'kot_batch_lines'           => ['kot_line_uuid', 'kot_batch_lines_kot_line_uuid_unique'],
        'restaurant_table_sessions' => ['session_uuid', 'restaurant_table_sessions_session_uuid_unique'],
        'manager_approvals'         => ['approval_uuid', 'manager_approvals_approval_uuid_unique'],
        'customers'                 => ['customer_uuid', 'customers_customer_uuid_unique'],
    ];

    public function up(): void
    {
        $conn = DB::connection('tenant');

        foreach ($this->identities as $table => [$column, $indexName]) {
            if (! Schema::connection('tenant')->hasTable($table)) {
                continue; // defensive: never fail a tenant that lacks a table
            }

            // 1. additive nullable column (idempotent).
            if (! Schema::connection('tenant')->hasColumn($table, $column)) {
                Schema::connection('tenant')->table($table, function (Blueprint $t) use ($column) {
                    $t->char($column, 26)->nullable()->after('id');
                });
            }

            // 2. idempotent generate-once, memory-bounded backfill of rows still NULL (fresh ULID each; never re-generates).
            do {
                $ids = $conn->table($table)->whereNull($column)->orderBy('id')->limit(1000)->pluck('id');
                foreach ($ids as $id) {
                    $conn->table($table)->where('id', $id)->update([$column => (string) Str::ulid()]);
                }
            } while ($ids->count() > 0);

            // 3. add the UNIQUE index only after every row is populated (idempotent).
            $indexes = $this->indexNames($conn, $table);
            if (! in_array($indexName, $indexes, true)) {
                Schema::connection('tenant')->table($table, function (Blueprint $t) use ($column, $indexName) {
                    $t->unique($column, $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->identities as $table => [$column, $indexName]) {
            if (! Schema::connection('tenant')->hasColumn($table, $column)) {
                continue;
            }
            Schema::connection('tenant')->table($table, function (Blueprint $t) use ($column, $indexName) {
                $t->dropUnique($indexName);
                $t->dropColumn($column);
            });
        }
    }

    /** Existing index names for a table (lower-cased), portable across MySQL/MariaDB. */
    private function indexNames($conn, string $table): array
    {
        $rows = $conn->select("SHOW INDEX FROM `{$table}`");

        return array_values(array_unique(array_map(fn ($r) => strtolower($r->Key_name), $rows)));
    }
};
