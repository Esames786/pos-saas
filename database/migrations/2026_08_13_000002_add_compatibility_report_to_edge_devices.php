<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-COMPATIBILITY-CONTRACT-1 — persist the last compatibility manifest a paired appliance
 * reported, so the Cloud can classify a device (compatible / software_update_required /
 * feature_unavailable_offline) without asking it live. The narrow app_version / schema_version
 * columns stay (pairing-era facts); this JSON is the full grounded manifest.
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
            $table->json('compatibility_manifest')->nullable()->after('schema_version');
            $table->timestamp('compatibility_reported_at')->nullable()->after('compatibility_manifest');
        });
    }

    public function down(): void
    {
        Schema::table('edge_devices', function (Blueprint $table) {
            $table->dropColumn(['compatibility_manifest', 'compatibility_reported_at']);
        });
    }
};
