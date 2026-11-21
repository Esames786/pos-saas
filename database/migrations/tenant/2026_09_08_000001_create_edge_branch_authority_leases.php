<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE EDGE — P0 BRANCH AUTHORITY LEASE (Cloud side, per branch).
 *
 * While Online, the Cloud owns a per-branch mutation lease that the branch's appliance keeps alive by heartbeat.
 * When the lease can no longer be renewed (the appliance's heartbeats stop reaching the Cloud), the Cloud stops
 * accepting that branch's transactional mutations at `expires_at` — so a later local takeover can never overlap
 * a Cloud writer. Every timestamp here is the CLOUD clock; the appliance reasons only on its own clock.
 * Additive (a branch without a paired appliance never gets a row → zero behaviour change).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('edge_branch_authority_leases')) {
            return;
        }
        Schema::connection($this->connection)->create('edge_branch_authority_leases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->unique();
            $table->string('device_public_uuid', 64);
            $table->string('holder', 10)->default('cloud');          // cloud | edge
            $table->string('edge_state', 20)->default('standby');     // standby | local_active | handing_back (as last reported)
            $table->unsignedBigInteger('heartbeat_seq')->default(0);  // monotonic per device — a stale/replayed beat is refused
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedInteger('lease_ttl_seconds')->default(120);
            $table->timestamp('expires_at')->nullable();              // Cloud may write while now() < expires_at (and holder = cloud)
            $table->timestamp('fenced_at')->nullable();               // first moment the Cloud refused this branch for lease loss
            $table->timestamp('released_at')->nullable();             // an operator released a dead appliance's lease
            $table->string('release_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['holder', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('edge_branch_authority_leases');
    }
};
