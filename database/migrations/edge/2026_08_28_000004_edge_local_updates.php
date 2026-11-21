<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE EDGE PRODUCTIZATION (O) — the appliance's local UPDATE audit log (EDGE-ONLY).
 *
 * One immutable row per update attempt: version transition, the signed package + artifact manifest hashes,
 * the schema generation before/after, the result and failure/rollback outcome. This is what support reads to
 * tell a clean update from a refused one from a rolled-back one. No secrets. Additive + idempotent (AE12).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('edge_local_updates')) {
            return;
        }
        Schema::connection($this->connection)->create('edge_local_updates', function (Blueprint $table) {
            $table->id();
            $table->char('update_uuid', 26)->unique();
            $table->string('from_version', 64)->nullable();
            $table->string('to_version', 64)->nullable();
            $table->string('package_hash', 128)->nullable();     // signature over the package payload
            $table->string('artifact_manifest_hash', 128)->nullable();
            $table->string('schema_before', 64)->nullable();
            $table->string('schema_after', 64)->nullable();
            $table->string('result', 24);                        // applied | refused | rolled_back | failed
            $table->string('failure_code', 64)->nullable();
            $table->string('rollback_result', 24)->nullable();   // reverted_runtime | restore_required | none
            $table->string('performed_by', 191)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['result', 'created_at'], 'elu_result_created_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('edge_local_updates');
    }
};
