<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 (stream B) — OPTIONAL AUTOMATIC OFFLINE TAKEOVER (default OFF) bookkeeping on the appliance's singleton meta row:
 *
 *   authority_cloud_skew_seconds / _at   Cloud server_time − appliance clock, measured at every acknowledged heartbeat
 *                                        (the auto path refuses when it is unknown or |skew| > 10 s — Khatri's clock was 12 h off)
 *   auto_takeover_last_attempt_at        the last time the evaluator CALLED takeOver (one attempt per 60 s)
 *   auto_takeover_last_decision / _reasons / _at   the last decision + reasons, shown on the status page
 *
 * Additive + idempotent (hasColumn guards); Edge-owned (database/migrations/edge). Nothing here changes the lease, the gates or
 * who sells: the takeover itself stays EdgeAuthorityService::takeOver.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $add = [
            'authority_cloud_skew_seconds' => fn (Blueprint $t) => $t->integer('authority_cloud_skew_seconds')->nullable(),
            'authority_cloud_skew_at' => fn (Blueprint $t) => $t->timestamp('authority_cloud_skew_at')->nullable(),
            'auto_takeover_last_attempt_at' => fn (Blueprint $t) => $t->timestamp('auto_takeover_last_attempt_at')->nullable(),
            'auto_takeover_last_decision' => fn (Blueprint $t) => $t->string('auto_takeover_last_decision', 32)->nullable(),
            'auto_takeover_last_reasons' => fn (Blueprint $t) => $t->string('auto_takeover_last_reasons', 500)->nullable(),
            'auto_takeover_last_at' => fn (Blueprint $t) => $t->timestamp('auto_takeover_last_at')->nullable(),
        ];
        foreach ($add as $column => $definition) {
            if (! Schema::connection($this->connection)->hasColumn('edge_local_meta', $column)) {
                Schema::connection($this->connection)->table('edge_local_meta', $definition);
            }
        }
    }

    public function down(): void
    {
        foreach (['authority_cloud_skew_seconds', 'authority_cloud_skew_at', 'auto_takeover_last_attempt_at', 'auto_takeover_last_decision', 'auto_takeover_last_reasons', 'auto_takeover_last_at'] as $column) {
            if (Schema::connection($this->connection)->hasColumn('edge_local_meta', $column)) {
                Schema::connection($this->connection)->table('edge_local_meta', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
