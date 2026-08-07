<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section I) — durable, Cloud-authoritative activation generation ("epoch")
 * store for stale-device fencing.
 *
 * Every currently valid Edge device/bootstrap for a branch belongs to exactly one activation
 * generation. When the authoritative appliance for a branch is replaced, a NEWER generation is
 * allocated; the older generation stays historically visible so an old, revoked box carrying an
 * old-but-valid-looking local DB/token can eventually be rejected.
 *
 * This is a DEDICATED durable store (append-only, one row per generation) rather than relying on
 * MAX() over mutable edge_devices history — so the epoch survives regardless of any future device
 * row lifecycle. Lives in the master DB (no cross-DB FK to tenant branch by design).
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        Schema::create('edge_branch_activations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');           // tenant-DB branch id; no cross-DB FK
            $table->unsignedBigInteger('generation');          // monotonic per (tenant, branch), starts at 1
            $table->unsignedBigInteger('edge_device_id')->nullable(); // allocating device (master id)
            $table->string('device_public_uuid', 64)->nullable();     // durable device identity
            $table->string('reason', 64)->nullable();          // e.g. initial | device_replaced
            $table->timestamps();

            // Append-only: exactly one row per (tenant, branch, generation).
            $table->unique(['tenant_id', 'branch_id', 'generation'], 'edge_branch_activation_generation_unique');
            $table->index(['tenant_id', 'branch_id'], 'edge_branch_activation_branch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_branch_activations');
    }
};
