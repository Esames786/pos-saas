<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-CONFIG-REFRESH-1 — EDGE-ONLY migration (database/migrations/edge; never a Cloud tenant DB).
 *
 * The appliance must persist which Cloud config revision it last SUCCESSFULLY applied, so a refresh
 * can refuse an older revision, no-op an identical replay, and apply a newer one. The bootstrap_*
 * columns stay frozen as the record of the INITIAL import; these columns track the CURRENT applied
 * configuration (initial import seeds them too).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection($this->connection)->table('edge_local_meta', function (Blueprint $table) {
            // Cloud-authoritative monotonic config revision last successfully applied (v5 manifest).
            $table->unsignedBigInteger('last_applied_config_revision')->nullable()->after('manifest_hash');
            // The config payload contract version that revision was applied under.
            $table->string('config_schema_version', 64)->nullable()->after('last_applied_config_revision');
            // Which snapshot the last refresh came from (initial import seeds it with the bootstrap's).
            $table->string('last_refresh_snapshot_uuid', 64)->nullable()->after('config_schema_version');
            $table->timestamp('last_refreshed_at')->nullable()->after('last_refresh_snapshot_uuid');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('edge_local_meta', function (Blueprint $table) {
            $table->dropColumn(['last_applied_config_revision', 'config_schema_version', 'last_refresh_snapshot_uuid', 'last_refreshed_at']);
        });
    }
};
