<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRINT-AGENT-INSTALLER-1: pairing-code flow for one-click agent setup.
 * The admin screen shows a short-lived 6-digit code; the installed agent
 * exchanges it once for the permanent token. Old env-var/token agents are
 * untouched — token_hash auth keeps working as before.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'pairing_code_hash',
        'pairing_expires_at',
        'pairing_attempts',
        'paired_at',
        'paired_device_name',
        'paired_device_platform',
        'paired_device_ip',
    ];

    public function up(): void
    {
        Schema::connection('tenant')->table('print_agents', function (Blueprint $table) {
            $sm = Schema::connection('tenant');

            if (! $sm->hasColumn('print_agents', 'pairing_code_hash')) {
                // Deterministic HMAC-SHA256 digest (queryable) — never the plain code.
                $table->string('pairing_code_hash', 64)->nullable()->index('pa_pairing_hash_idx');
            }
            if (! $sm->hasColumn('print_agents', 'pairing_expires_at')) {
                $table->timestamp('pairing_expires_at')->nullable();
            }
            if (! $sm->hasColumn('print_agents', 'pairing_attempts')) {
                $table->unsignedTinyInteger('pairing_attempts')->default(0);
            }
            if (! $sm->hasColumn('print_agents', 'paired_at')) {
                $table->timestamp('paired_at')->nullable();
            }
            if (! $sm->hasColumn('print_agents', 'paired_device_name')) {
                $table->string('paired_device_name')->nullable();
            }
            if (! $sm->hasColumn('print_agents', 'paired_device_platform')) {
                $table->string('paired_device_platform')->nullable();
            }
            if (! $sm->hasColumn('print_agents', 'paired_device_ip')) {
                $table->string('paired_device_ip', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('print_agents', function (Blueprint $table) {
            $sm = Schema::connection('tenant');

            if ($sm->hasColumn('print_agents', 'pairing_code_hash')) {
                $table->dropIndex('pa_pairing_hash_idx');
            }

            foreach (self::COLUMNS as $column) {
                if ($sm->hasColumn('print_agents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
