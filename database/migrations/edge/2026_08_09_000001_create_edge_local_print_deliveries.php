<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-LOCAL-PRINT-1 (§5) — Edge-ONLY physical-delivery transport metadata. Lives under
 * database/migrations/edge (NEVER runs on Cloud tenants; created by edge:local:db-init only).
 *
 * One row per print_job the local delivery loop touches. print_jobs.print_status remains the ONE
 * authoritative business-facing status (queued/printed/failed); this table owns ONLY transport
 * execution state: lease-token ownership (stale completion must be impossible — a claimed_at
 * timestamp alone cannot distinguish the CURRENT worker from a stale one), bounded retry/backoff
 * bookkeeping, and last-error diagnostics. Shared Cloud concepts are deliberately NOT reused:
 * no synthetic print_agents row, no claimed_by_agent_id, no repurposed `cancelled` enum, and
 * print_jobs.attempts keeps its Cloud semantic (markFailed transitions, not physical attempts).
 * print_job_id is the LOCAL numeric id — appliance-local execution metadata, not a cross-system
 * business identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('edge_local_print_deliveries')) {
            return; // safe-retry (AE12)
        }
        Schema::connection('tenant')->create('edge_local_print_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('print_job_id');
            $table->unique('print_job_id', 'elpd_print_job_unique');
            // transport execution state — Edge-local vocabulary, explicit by design.
            $table->string('delivery_state', 20)->default('waiting'); // waiting|leased|retry_wait|delivered|terminal_failed
            $table->char('worker_uuid', 36)->nullable();
            $table->char('lease_token', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['delivery_state', 'next_attempt_at'], 'elpd_state_next_idx');
            // append-only history discipline: the job row must outlive its transport metadata.
            $table->foreign('print_job_id', 'elpd_print_job_fk')->references('id')->on('print_jobs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('edge_local_print_deliveries');
    }
};
