<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-SCHEMA-UPGRADE-1 — EDGE-ONLY migration (database/migrations/edge; never a Cloud tenant DB).
 *
 * An appliance that already holds sales, shifts, held orders, print history and the sync outbox must be
 * upgradable WITHOUT rebuilding its local database. `edge:local:schema-upgrade` applies only the pending
 * forward migrations and then records which Edge schema generation the appliance now runs, so the
 * compatibility contract can report applied-vs-shipped and an operator can prove an upgrade landed.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection($this->connection)->table('edge_local_meta', function (Blueprint $table) {
            $table->string('edge_schema_version', 190)->nullable()->after('last_refreshed_at');
            $table->timestamp('last_schema_upgrade_at')->nullable()->after('edge_schema_version');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('edge_local_meta', function (Blueprint $table) {
            $table->dropColumn(['edge_schema_version', 'last_schema_upgrade_at']);
        });
    }
};
