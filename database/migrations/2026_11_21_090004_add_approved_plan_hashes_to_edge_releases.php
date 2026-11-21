<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 (K6 plan approval, integration finding 21 Nov 2026) — the appliance (stream B) refuses a release whose tenant
 * migration plan has pending entries unless the Cloud advertises the OWNER-APPROVED plan hash for the appliance's installed
 * version. The map {"<from_version>": "sha256:<64 hex>"} is written ONLY by a Cloud operator through
 * `edge:release:publish … --approve-plan=<from>=<hash>` or `edge:release:approve-plan` (audited); the Cloud never computes
 * or approves a plan on a device's behalf. Additive, nullable.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('tenancy.master_connection', 'master');
    }

    public function up(): void
    {
        if (! Schema::connection($this->getConnection())->hasColumn('edge_releases', 'approved_plan_hashes')) {
            Schema::connection($this->getConnection())->table('edge_releases', function (Blueprint $table) {
                $table->json('approved_plan_hashes')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->getConnection())->hasColumn('edge_releases', 'approved_plan_hashes')) {
            Schema::connection($this->getConnection())->table('edge_releases', fn (Blueprint $table) => $table->dropColumn('approved_plan_hashes'));
        }
    }
};
