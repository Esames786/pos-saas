<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRANCH-BOOTSTRAP-SNAPSHOT-1 — immutable, branch-scoped bootstrap snapshots (master DB).
 * A snapshot is the deterministic set of catalog/settings a paired device downloads before
 * a (future) readiness/activation step. Contents are computed once and never mutated;
 * sections are stored separately so they can be fetched + integrity-checked individually.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        Schema::create('edge_bootstrap_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');       // tenant-DB branch id; no cross-DB FK
            $table->unsignedBigInteger('edge_device_id');
            $table->string('schema_version', 40);          // e.g. edge-bootstrap-v1
            $table->string('status', 20)->default('building'); // building|ready|downloaded|acknowledged|failed|expired
            $table->string('source_revision', 64)->nullable(); // watermark for reuse / future delta
            $table->char('manifest_hash', 64)->nullable();
            $table->json('section_summary')->nullable();   // name => {hash, count}
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['edge_device_id', 'status']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('edge_bootstrap_snapshot_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('snapshot_id');
            $table->string('name', 64);
            $table->char('content_hash', 64);
            $table->unsignedInteger('row_count')->default(0);
            $table->longText('payload_gz');                // gzip(canonical JSON); logical hash is over the uncompressed JSON
            $table->timestamps();

            $table->unique(['snapshot_id', 'name'], 'edge_bootstrap_section_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_bootstrap_snapshot_sections');
        Schema::dropIfExists('edge_bootstrap_snapshots');
    }
};
