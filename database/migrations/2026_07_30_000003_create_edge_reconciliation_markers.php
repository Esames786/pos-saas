<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRANCH-DEVICE-PAIRING-HARDEN-2 — a minimal, PERSISTENT marker recorded when a branch
 * lifecycle reconciliation fails AFTER a master-side security mutation (cancel/revoke)
 * has already committed. The security action must never be lost, and the pending
 * reconciliation must survive a crash/restart instead of living only in a log line.
 *
 * This is NOT the full reconciliation engine — just durable state that a later retry
 * (or a future job) can resolve. One marker per (tenant, branch).
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        Schema::create('edge_reconciliation_markers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');   // tenant-DB branch id; no cross-DB FK
            $table->string('operation', 64);           // e.g. edge_device_revoked / edge_pairing_cancelled
            $table->string('status', 16)->default('pending'); // pending | resolved
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id'], 'edge_reconciliation_tenant_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_reconciliation_markers');
    }
};
