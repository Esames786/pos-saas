<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W6 (0.7.0-edge, bootstrap v7) — EDGE-ONLY migration (database/migrations/edge; never a Cloud tenant DB).
 *
 * Team 4 C-4: the bootstrap's informational `tenant.business_name` is persisted on the appliance binding row so the
 * offline Quick Report header prints the tenant business name exactly like Online (PosQuickReportController). Display
 * metadata only — not an identity/binding field (EdgeLocalMeta::IMMUTABLE_FIELDS is unchanged). Additive, nullable,
 * idempotent (hasColumn guard) — safe for the forward-only appliance schema upgrader.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasColumn('edge_local_meta', 'tenant_business_name')) {
            return;
        }
        Schema::connection($this->connection)->table('edge_local_meta', function (Blueprint $table) {
            $table->string('tenant_business_name', 190)->nullable()->after('tenant_code');
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('edge_local_meta', 'tenant_business_name')) {
            return;
        }
        Schema::connection($this->connection)->table('edge_local_meta', function (Blueprint $table) {
            $table->dropColumn('tenant_business_name');
        });
    }
};
