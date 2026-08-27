<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE-SYNC-ENGINE-1E — the controlled operational-baseline CUTOVER (EDGE-ONLY, database/migrations/edge).
 *
 * A Config Refresh advances the appliance's source_revision; the accepted baseline's revision no longer
 * matches, so currentAccepted() returns null and offline selling fences (fail-closed). The ONLY sanctioned
 * way to resume selling is a controlled cutover to a NEW baseline whose on-hand quantities ACCOUNT for the
 * prior generation's ingested sales — never a blind in-binding replace (that fence stays in
 * EdgeOperationalBaselineService::accept).
 *
 * This migration is additive:
 *   - edge_operational_stock_baselines.superseded_at — stamped when a cutover supersedes the prior baseline.
 *     The prior row is RETAINED (status='superseded', active_binding_key nulled so the single-accepted-per-
 *     binding unique index frees for the new accepted row); its append-only movements survive (FK RESTRICT).
 *   - edge_baseline_cutovers — one immutable audit row per completed cutover: old + new baseline metadata,
 *     the config watermark move, the Cloud authoritative-position evidence, the drain evidence, actor + reason.
 *
 * Idempotent + safe-retry (AE12): every step is guarded.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('edge_operational_stock_baselines')
            && ! Schema::connection($this->connection)->hasColumn('edge_operational_stock_baselines', 'superseded_at')) {
            Schema::connection($this->connection)->table('edge_operational_stock_baselines', function (Blueprint $table) {
                $table->timestamp('superseded_at')->nullable()->after('accepted_at');
            });
        }

        if (! Schema::connection($this->connection)->hasTable('edge_baseline_cutovers')) {
            Schema::connection($this->connection)->create('edge_baseline_cutovers', function (Blueprint $table) {
                $table->id();
                $table->char('cutover_uuid', 26)->unique();               // immutable cutover identity (ULID)
                $table->unsignedBigInteger('branch_id');
                $table->string('device_uuid', 64);
                $table->unsignedBigInteger('activation_epoch');

                // prior generation (nullable: a first baseline has no predecessor, but cutover requires one).
                $table->unsignedBigInteger('old_baseline_id')->nullable();
                $table->char('old_baseline_uuid', 26)->nullable();
                $table->string('old_source_revision', 128)->nullable();
                $table->unsignedInteger('old_generation')->nullable();

                // new generation.
                $table->unsignedBigInteger('new_baseline_id');
                $table->char('new_baseline_uuid', 26);
                $table->string('new_source_revision', 128);
                $table->unsignedInteger('new_generation');
                $table->string('new_content_hash', 128);

                // step (2) evidence: the Cloud authoritative position the package was computed from.
                $table->timestamp('cloud_position_as_of')->nullable();
                $table->string('cloud_position_hash', 128)->nullable();

                // step (1) evidence: the drain state at cutover (outbox counts by state).
                $table->json('drain_evidence')->nullable();

                $table->string('performed_by', 191);
                $table->string('reason', 500)->nullable();
                $table->timestamp('started_at');
                $table->timestamp('completed_at');
                $table->timestamps();

                $table->index(['branch_id', 'activation_epoch'], 'ebc_branch_epoch_idx');
                $table->index(['new_baseline_uuid'], 'ebc_new_baseline_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('edge_baseline_cutovers');
        if (Schema::connection($this->connection)->hasColumn('edge_operational_stock_baselines', 'superseded_at')) {
            Schema::connection($this->connection)->table('edge_operational_stock_baselines', function (Blueprint $table) {
                $table->dropColumn('superseded_at');
            });
        }
    }
};
