<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OWNER DECISION D7 — the update audit row records the EXACT migration plan the update was approved against
 * (EDGE-ONLY, additive + idempotent).
 *
 *   migration_plan_hash  sha256 of the edge-migration-plan-v1 tuples the NEW runtime printed before the pointer switch;
 *                        the installer refuses the update (UPDATE_MIGRATION_PLAN_MISMATCH) when it differs from the
 *                        hash of the pre-install report (`--expected-plan-hash`), and records both here.
 *   migration_plan       the plan document itself (entries with classification + decision, including CLOUD_ONLY files
 *                        skipped-with-record) — what support reads to see what an update ran, skipped or refused.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('edge_local_updates')) {
            return; // created by 2026_08_28_000004_edge_local_updates on every appliance
        }
        if (! $schema->hasColumn('edge_local_updates', 'migration_plan_hash')) {
            $schema->table('edge_local_updates', function (Blueprint $table) {
                $table->string('migration_plan_hash', 80)->nullable()->after('schema_after');
                $table->string('expected_plan_hash', 80)->nullable()->after('migration_plan_hash');
                $table->json('migration_plan')->nullable()->after('expected_plan_hash');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('edge_local_updates') && $schema->hasColumn('edge_local_updates', 'migration_plan_hash')) {
            $schema->table('edge_local_updates', fn (Blueprint $table) => $table->dropColumn(['migration_plan_hash', 'expected_plan_hash', 'migration_plan']));
        }
    }
};
