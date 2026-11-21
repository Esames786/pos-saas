<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W-F VERSION REPORTING (next release, shared-POS architecture §10) — the appliance now reports its build on the authority
 * heartbeat. The pairing-era app_version / schema_version columns (40) are widened to 64 (the same bound the compatibility
 * report endpoint already validates), and the Cloud remembers the sha256 of the last build block it recorded so an
 * unchanged heartbeat (every 20 s) never writes the master DB: build_reported_hash / build_reported_at.
 *
 * Informational only: nothing here is read by the authority lease, the fence or ingestion.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        Schema::table('edge_devices', function (Blueprint $table) {
            $table->string('app_version', 64)->nullable()->change();
            $table->string('schema_version', 64)->nullable()->change();
        });

        Schema::table('edge_devices', function (Blueprint $table) {
            if (! Schema::connection($this->getConnection())->hasColumn('edge_devices', 'build_reported_hash')) {
                $table->char('build_reported_hash', 64)->nullable()->after('compatibility_reported_at');
            }
            if (! Schema::connection($this->getConnection())->hasColumn('edge_devices', 'build_reported_at')) {
                $table->timestamp('build_reported_at')->nullable()->after('build_reported_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('edge_devices', function (Blueprint $table) {
            $table->dropColumn(['build_reported_hash', 'build_reported_at']);
        });
        // The widths are deliberately NOT narrowed back to 40: a reported version longer than 40 characters would make the
        // rollback fail (strict mode) or silently truncate a fact. 64 is a superset of every value 40 could hold.
    }
};
