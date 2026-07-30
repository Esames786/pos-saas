<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRANCH-DEVICE-PAIRING-HARDEN-1 — DB-enforced invariants that application code alone
 * cannot guarantee under concurrency:
 *
 *  1. edge_pairing_codes: unique(code_hash, active_slot) — two branches/tenants can never
 *     hold the same LIVE six-digit code (active_slot=1). NULL after use/cancel/expiry, and
 *     MySQL allows multiple NULLs, so historical reuse stays legal.
 *
 *  2. edge_devices: replace the single-column unique(installation_uuid) with
 *     unique(installation_uuid, active_slot) so a REVOKED installation (active_slot=NULL)
 *     can pair again, while only ONE ACTIVE device per installation exists globally.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        // MVP serializes device-slot allocation with a tenant-row lock (see EdgePairingService);
        // a dedicated license-slot row is left for a later sprint.
        Schema::table('edge_pairing_codes', function (Blueprint $table) {
            $table->unique(['code_hash', 'active_slot'], 'edge_pairing_codes_livecode_unique');
        });

        Schema::table('edge_devices', function (Blueprint $table) {
            $table->dropUnique('edge_devices_installation_uuid_unique');
            $table->unique(['installation_uuid', 'active_slot'], 'edge_devices_installation_active_unique');
        });
    }

    public function down(): void
    {
        Schema::table('edge_devices', function (Blueprint $table) {
            $table->dropUnique('edge_devices_installation_active_unique');
            $table->unique('installation_uuid', 'edge_devices_installation_uuid_unique');
        });

        Schema::table('edge_pairing_codes', function (Blueprint $table) {
            $table->dropUnique('edge_pairing_codes_livecode_unique');
        });
    }
};
