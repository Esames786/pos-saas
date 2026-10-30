<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.8.3 SERVICE RELIABILITY (F-G10) — record the worker PROCESS identity next to the singleton heartbeat.
 *
 * worker_pid + worker_process_token (process start time, unix seconds) + worker_host let a supervised restart ask the OS
 * whether the previous worker process still exists instead of trusting a heartbeat younger than 90 s — the fast-reboot /
 * crash-restart duplicate-exit trap. Nullable, additive, safe-retry (a pre-0.8.3 row simply has no identity and keeps the
 * old heartbeat rule). Never a lease or authority column.
 */
return new class extends Migration
{
    private const TABLES = ['edge_local_print_worker_state', 'edge_local_authority_worker_state'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::connection('tenant')->hasTable($table)) {
                continue; // the creating migration runs first on a fresh appliance; nothing to add on a Cloud host
            }
            Schema::connection('tenant')->table($table, function (Blueprint $t) use ($table) {
                if (! Schema::connection('tenant')->hasColumn($table, 'worker_pid')) {
                    $t->unsignedInteger('worker_pid')->nullable()->after('runtime_version');
                }
                if (! Schema::connection('tenant')->hasColumn($table, 'worker_process_token')) {
                    $t->string('worker_process_token', 64)->nullable()->after('worker_pid');
                }
                if (! Schema::connection('tenant')->hasColumn($table, 'worker_host')) {
                    $t->string('worker_host', 64)->nullable()->after('worker_process_token');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::connection('tenant')->hasTable($table)) {
                continue;
            }
            Schema::connection('tenant')->table($table, function (Blueprint $t) use ($table) {
                $drop = array_values(array_filter(['worker_pid', 'worker_process_token', 'worker_host'], fn ($c) => Schema::connection('tenant')->hasColumn($table, $c)));
                if ($drop !== []) {
                    $t->dropColumn($drop);
                }
            });
        }
    }
};
