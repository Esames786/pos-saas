<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 (owner, 21 Nov 2026) — OPTIONAL, default-OFF "Automatic Offline Takeover" per branch.
 *
 *   edge_auto_takeover_enabled          false by default: nothing changes for any branch on deploy
 *   edge_auto_takeover_after_seconds    300 by default (the Offline Edge page accepts 180–3600)
 *   edge_auto_takeover_set_by_user_id   who last changed it (audit)
 *   edge_auto_takeover_set_at           when
 *
 * Edited on the Cloud Offline Edge page only by a holder of tenant.offline-edge.security; delivered to the Branch Server in
 * the bootstrap / config-refresh payload (informational `restrictions` row, key `auto_takeover`). CLOUD_ONLY in the Edge
 * tenant-migration manifest: the appliance never needs these columns (the branch section's column list is explicit).
 * Additive; INSTANT column adds on MySQL 8 (branches is a tiny table).
 */
return new class extends Migration
{
    public function up(): void
    {
        $has = fn (string $c) => Schema::connection('tenant')->hasColumn('branches', $c);

        Schema::connection('tenant')->table('branches', function (Blueprint $table) use ($has) {
            if (! $has('edge_auto_takeover_enabled')) {
                $table->boolean('edge_auto_takeover_enabled')->default(false);
            }
            if (! $has('edge_auto_takeover_after_seconds')) {
                $table->unsignedSmallInteger('edge_auto_takeover_after_seconds')->default(300);
            }
            if (! $has('edge_auto_takeover_set_by_user_id')) {
                $table->unsignedBigInteger('edge_auto_takeover_set_by_user_id')->nullable();
            }
            if (! $has('edge_auto_takeover_set_at')) {
                $table->timestamp('edge_auto_takeover_set_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        $present = array_values(array_filter(
            ['edge_auto_takeover_enabled', 'edge_auto_takeover_after_seconds', 'edge_auto_takeover_set_by_user_id', 'edge_auto_takeover_set_at'],
            fn ($c) => Schema::connection('tenant')->hasColumn('branches', $c),
        ));
        if ($present !== []) {
            Schema::connection('tenant')->table('branches', fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
