<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE EDGE — P0 BRANCH AUTHORITY LEASE (appliance side).
 *
 * The appliance records ONLY what it observed on its own clock: when its last heartbeat was acknowledged by the
 * Cloud and for how long that lease runs. Local authority (authority_state = local_active) is granted only after
 * the lease has lapsed by the Cloud TTL plus a skew margin AND every readiness gate passes — never on one failed
 * request. Additive + idempotent.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('edge_local_meta')) {
            return;
        }
        Schema::connection($this->connection)->table('edge_local_meta', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('edge_local_meta', 'authority_state')) {
                $table->string('authority_state', 20)->default('standby');       // standby | local_active | handing_back
            }
            if (! Schema::connection($this->connection)->hasColumn('edge_local_meta', 'authority_last_ack_at')) {
                $table->timestamp('authority_last_ack_at')->nullable();           // appliance clock
            }
            if (! Schema::connection($this->connection)->hasColumn('edge_local_meta', 'authority_lease_ttl_seconds')) {
                $table->unsignedInteger('authority_lease_ttl_seconds')->nullable();
            }
            if (! Schema::connection($this->connection)->hasColumn('edge_local_meta', 'authority_heartbeat_seq')) {
                $table->unsignedBigInteger('authority_heartbeat_seq')->default(0);
            }
            if (! Schema::connection($this->connection)->hasColumn('edge_local_meta', 'authority_last_failure_at')) {
                $table->timestamp('authority_last_failure_at')->nullable();
            }
            if (! Schema::connection($this->connection)->hasColumn('edge_local_meta', 'authority_takeover_at')) {
                $table->timestamp('authority_takeover_at')->nullable();
            }
            if (! Schema::connection($this->connection)->hasColumn('edge_local_meta', 'authority_state_reason')) {
                $table->string('authority_state_reason', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['authority_state', 'authority_last_ack_at', 'authority_lease_ttl_seconds', 'authority_heartbeat_seq', 'authority_last_failure_at', 'authority_takeover_at', 'authority_state_reason'] as $col) {
            if (Schema::connection($this->connection)->hasColumn('edge_local_meta', $col)) {
                Schema::connection($this->connection)->table('edge_local_meta', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
