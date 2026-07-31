<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRANCH-BOOTSTRAP-SNAPSHOT-HARDEN-1 — per-section delivery tracking so a snapshot is only
 * "downloaded" once EVERY required section has actually been fetched, and acknowledgment can
 * verify the complete required set was delivered (not just one section).
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        Schema::table('edge_bootstrap_snapshot_sections', function (Blueprint $table) {
            $table->timestamp('downloaded_at')->nullable()->after('row_count');
            $table->char('delivered_hash', 64)->nullable()->after('downloaded_at');
            $table->unsignedInteger('attempts')->default(0)->after('delivered_hash');
        });
    }

    public function down(): void
    {
        Schema::table('edge_bootstrap_snapshot_sections', function (Blueprint $table) {
            $table->dropColumn(['downloaded_at', 'delivered_hash', 'attempts']);
        });
    }
};
