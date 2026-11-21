<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 (K2b, integration finding 21 Nov 2026) — a setup wizard whose pairing code expired (or is about to) asks for a
 * fresh one with its setup token (POST /api/edge/setup/pairing-code). The session counts the pairing codes it was given so a
 * stolen token cannot churn codes without bound. Additive.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        if (! Schema::connection($this->getConnection())->hasColumn('edge_setup_sessions', 'pairing_codes_issued')) {
            Schema::connection($this->getConnection())->table('edge_setup_sessions', function (Blueprint $table) {
                $table->unsignedInteger('pairing_codes_issued')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->getConnection())->hasColumn('edge_setup_sessions', 'pairing_codes_issued')) {
            Schema::connection($this->getConnection())->table('edge_setup_sessions', fn (Blueprint $table) => $table->dropColumn('pairing_codes_issued'));
        }
    }
};
