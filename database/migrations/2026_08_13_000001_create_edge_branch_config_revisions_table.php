<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-CONFIG-REFRESH-1 — durable, Cloud-authoritative CONFIG REVISION store.
 *
 * The bootstrap `source_revision` is a content watermark (a hash): it detects change but carries no
 * order. Config refresh needs a MONOTONIC, recoverable revision so an appliance can refuse an OLDER
 * configuration and treat the SAME revision as an idempotent replay. This table is that authority:
 * append-only, one row per (tenant, branch, revision), revision starting at 1 and increasing by 1
 * whenever the branch's config watermark changes. Allocation is idempotent per watermark — rebuilding
 * a snapshot for an unchanged config reuses the latest revision instead of minting a new one.
 *
 * Lives in the master DB (no cross-DB FK to the tenant branch by design), mirroring
 * edge_branch_activations.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        Schema::create('edge_branch_config_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');          // tenant-DB branch id; no cross-DB FK
            $table->unsignedBigInteger('revision');           // monotonic per (tenant, branch), starts at 1
            $table->string('source_revision', 128);           // the config watermark this revision captured
            $table->timestamps();

            // Append-only: exactly one row per (tenant, branch, revision).
            $table->unique(['tenant_id', 'branch_id', 'revision'], 'edge_branch_config_revision_unique');
            $table->index(['tenant_id', 'branch_id'], 'edge_branch_config_revision_branch_index');
        });

        // The snapshot now records which monotonic config revision it carries (v5 manifest field).
        Schema::table('edge_bootstrap_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('config_revision')->nullable()->after('activation_epoch');
        });
    }

    public function down(): void
    {
        Schema::table('edge_bootstrap_snapshots', function (Blueprint $table) {
            $table->dropColumn('config_revision');
        });
        Schema::dropIfExists('edge_branch_config_revisions');
    }
};
