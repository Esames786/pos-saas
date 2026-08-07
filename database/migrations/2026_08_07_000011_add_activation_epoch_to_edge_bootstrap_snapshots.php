<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE-LOCAL-RUNTIME-1 (Section I/J) — the bootstrap snapshot now carries the Cloud-authoritative
 * activation generation ("epoch") it was built under (edge-bootstrap-v4). The importer stores it in
 * edge_local_meta and acknowledgement fences a stale generation. Additive, master DB.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        Schema::table('edge_bootstrap_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('activation_epoch')->nullable()->after('source_revision');
        });
    }

    public function down(): void
    {
        Schema::table('edge_bootstrap_snapshots', function (Blueprint $table) {
            $table->dropColumn('activation_epoch');
        });
    }
};
