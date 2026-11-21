<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 (stream B) — the Cloud branch setting for the optional Automatic Offline Takeover, as REPLICATED to the appliance.
 *
 * Stream C delivers it in the informational `restrictions` section of the bootstrap / config-refresh package
 * (restrictions[0].auto_takeover = {enabled, after_seconds, set_by, set_at}) — never as a branch column, which every 0.8.x
 * appliance would try to import. The importer / refresh applier hand that section to AutoTakeoverEvaluator::recordCloudSetting(),
 * which keeps the latest value here (JSON). Absent / unreadable = OFF. Additive + idempotent; Edge-owned.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('edge_local_meta', 'auto_takeover_setting')) {
            Schema::connection($this->connection)->table('edge_local_meta', fn (Blueprint $t) => $t->text('auto_takeover_setting')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('edge_local_meta', 'auto_takeover_setting')) {
            Schema::connection($this->connection)->table('edge_local_meta', fn (Blueprint $t) => $t->dropColumn('auto_takeover_setting'));
        }
    }
};
