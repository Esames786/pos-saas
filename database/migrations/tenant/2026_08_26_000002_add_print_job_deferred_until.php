<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRINTER-HEALTH-1 hardening: a job the agent cannot deliver right now (its printer is offline /
 * cooling in the circuit breaker) must be RE-QUEUED, never terminally failed. `deferred_until` parks
 * it for a short while so it (a) survives to reprint the moment the printer answers again, and (b)
 * drops out of the pending fetch meanwhile, so a dead printer's backlog stops starving the healthy
 * ones. Additive + nullable — existing jobs read as "not deferred".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('print_jobs', function (Blueprint $table) {
            $table->timestamp('deferred_until')->nullable()->after('claimed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('print_jobs', function (Blueprint $table) {
            $table->dropColumn('deferred_until');
        });
    }
};
