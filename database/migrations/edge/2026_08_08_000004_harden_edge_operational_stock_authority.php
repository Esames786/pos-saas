<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-LOCAL-POS-1 — harden the operational-stock AUTHORITY invariants (follow-up to 2026_08_08_000003;
 * ec9a9d3 is already pushed, so this is an additive forward migration, not a rewrite).
 *
 * (#7) DB-level single-accepted-baseline invariant: application locking cannot serialize two FIRST
 * acceptances that both observe zero accepted rows. `active_binding_key` = SHA-1(branch|device|epoch),
 * populated ONLY on accepted rows (nullable for future archived/superseded rows), with a UNIQUE index —
 * MySQL itself guarantees at most one accepted baseline per appliance binding; a racing loser gets a
 * unique violation that the service converts into a controlled conflict.
 *
 * (#8) Append-only movement history: `edge_operational_stock_movements` documented append-only, but its
 * baseline FK was cascadeOnDelete — a baseline delete could erase sale movement history. The FK is
 * recreated as RESTRICT so persisted operational history survives; there is no runtime baseline-delete path.
 *
 * Idempotent + safe-retry (AE12): every step is guarded.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // (#7) active_binding_key + unique index.
        if (! Schema::connection($this->connection)->hasColumn('edge_operational_stock_baselines', 'active_binding_key')) {
            Schema::connection($this->connection)->table('edge_operational_stock_baselines', function (Blueprint $table) {
                $table->char('active_binding_key', 40)->nullable()->after('status');
            });
        }
        // backfill existing accepted rows (idempotent — only rows still NULL).
        $rows = DB::connection($this->connection)->table('edge_operational_stock_baselines')
            ->where('status', 'accepted')->whereNull('active_binding_key')->get();
        foreach ($rows as $row) {
            DB::connection($this->connection)->table('edge_operational_stock_baselines')->where('id', $row->id)->update([
                'active_binding_key' => sha1($row->branch_id . '|' . $row->device_uuid . '|' . $row->activation_epoch),
            ]);
        }
        $indexes = array_map(fn ($r) => strtolower($r->Key_name), DB::connection($this->connection)->select('SHOW INDEX FROM `edge_operational_stock_baselines`'));
        if (! in_array('eosb_active_binding_unique', $indexes, true)) {
            Schema::connection($this->connection)->table('edge_operational_stock_baselines', function (Blueprint $table) {
                $table->unique('active_binding_key', 'eosb_active_binding_unique');
            });
        }

        // (#8) movements FK: cascade -> RESTRICT (append-only history must survive a baseline delete).
        $fk = DB::connection($this->connection)->select(
            "SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'edge_operational_stock_movements'
               AND REFERENCED_TABLE_NAME = 'edge_operational_stock_baselines'"
        );
        if (! empty($fk) && strtoupper($fk[0]->DELETE_RULE) !== 'RESTRICT') {
            Schema::connection($this->connection)->table('edge_operational_stock_movements', function (Blueprint $table) use ($fk) {
                $table->dropForeign($fk[0]->CONSTRAINT_NAME);
            });
            Schema::connection($this->connection)->table('edge_operational_stock_movements', function (Blueprint $table) {
                $table->foreign('baseline_id', 'eosm_baseline_fk_restrict')
                    ->references('id')->on('edge_operational_stock_baselines')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('edge_operational_stock_baselines', 'active_binding_key')) {
            Schema::connection($this->connection)->table('edge_operational_stock_baselines', function (Blueprint $table) {
                $table->dropUnique('eosb_active_binding_unique');
                $table->dropColumn('active_binding_key');
            });
        }
    }
};
