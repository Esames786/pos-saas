<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-LOCAL-PRINT-1 Slice 2 (§9) — the appliance's print-worker PROCESS state (Edge-only; never on
 * Cloud tenants). ONE row (EdgeLocalMeta singleton_guard pattern): the chosen production topology is
 * a SINGLE worker process — a second accidental daemon must observe this row and exit cleanly.
 *
 * Liveness is judged by HEARTBEAT STALENESS exactly as job leases judge authority by expiry. This row
 * is NEVER a lease authority — job lease tokens remain the sole completion authority; a stale
 * heartbeat only tells supervision/diagnostics the process is gone and lets a new worker take over
 * the singleton. Stop is COOPERATIVE (stop_requested_at flag — pcntl signals do not exist on Windows
 * php-cli): the loop finishes its in-flight job, records a graceful stop, and exits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('edge_local_print_worker_state')) {
            return; // safe-retry (AE12)
        }
        Schema::connection('tenant')->create('edge_local_print_worker_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('singleton_guard')->default(1)->unique();
            $table->string('state', 20)->default('stopped'); // running | stopped
            $table->char('worker_uuid', 36)->nullable();
            $table->string('runtime_version', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('stop_requested_at')->nullable();
            $table->timestamp('last_graceful_stop_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('edge_local_print_worker_state');
    }
};
