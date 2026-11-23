<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 — SHIFT / ORDER CONTINUITY: the adoption markers on the appliance's own operational rows (stream A).
 *
 *   shifts.continues_cloud_shift_uuid   the Cloud shift this Edge shift continues (opened by ContinuityAdoptionService at the
 *                                       first touch after a supervised takeover; opening cash = the mirrored expected cash);
 *   shifts.continuity_segment           the local-authority period it belongs to (edge_local_meta.authority_takeover_at) —
 *                                       UNIQUE (terminal, Cloud shift, segment) is the race guard: two counters touching the
 *                                       POS at the same instant can never open two continuations of one Cloud shift;
 *   shifts.continuity_mirrored_at       the mirror instant the continuation is based on (cashier banner "mirrored HH:MM:SS");
 *   sales_orders.adopted_from_order_uuid / adopted_mirror_revision / adopted_mirrored_at / adopted_cloud_fingerprint
 *                                       an Edge held sale that IS a mirrored Cloud open order (same sale_uuid) — the envelope
 *                                       carries them so the Cloud completes the EXISTING order instead of creating a second one;
 *   restaurant_table_sessions.adopted_from_session_uuid
 *                                       an Edge table session that IS a mirrored Cloud session (same session_uuid).
 *
 * EDGE-ONLY (database/migrations/edge; never a Cloud tenant DB). Additive, nullable, idempotent (hasColumn guards).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);

        if ($s->hasTable('shifts') && ! $s->hasColumn('shifts', 'continues_cloud_shift_uuid')) {
            $s->table('shifts', function (Blueprint $table) {
                $table->char('continues_cloud_shift_uuid', 26)->nullable();
                $table->string('continuity_segment', 40)->nullable();
                $table->timestamp('continuity_mirrored_at')->nullable();
                $table->unique(['terminal_id', 'continues_cloud_shift_uuid', 'continuity_segment'], 'shifts_continuation_unique');
            });
        }

        if ($s->hasTable('sales_orders') && ! $s->hasColumn('sales_orders', 'adopted_from_order_uuid')) {
            $s->table('sales_orders', function (Blueprint $table) {
                $table->char('adopted_from_order_uuid', 26)->nullable()->unique('sales_orders_adopted_from_order_uuid_unique');
                $table->string('adopted_mirror_revision', 128)->nullable();
                $table->timestamp('adopted_mirrored_at')->nullable();
                $table->char('adopted_cloud_fingerprint', 64)->nullable();
            });
        }

        if ($s->hasTable('restaurant_table_sessions') && ! $s->hasColumn('restaurant_table_sessions', 'adopted_from_session_uuid')) {
            $s->table('restaurant_table_sessions', function (Blueprint $table) {
                $table->char('adopted_from_session_uuid', 26)->nullable();
            });
        }
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        if ($s->hasColumn('shifts', 'continues_cloud_shift_uuid')) {
            $s->table('shifts', function (Blueprint $table) {
                $table->dropUnique('shifts_continuation_unique');
                $table->dropColumn(['continues_cloud_shift_uuid', 'continuity_segment', 'continuity_mirrored_at']);
            });
        }
        if ($s->hasColumn('sales_orders', 'adopted_from_order_uuid')) {
            $s->table('sales_orders', function (Blueprint $table) {
                $table->dropUnique('sales_orders_adopted_from_order_uuid_unique');
                $table->dropColumn(['adopted_from_order_uuid', 'adopted_mirror_revision', 'adopted_mirrored_at', 'adopted_cloud_fingerprint']);
            });
        }
        if ($s->hasColumn('restaurant_table_sessions', 'adopted_from_session_uuid')) {
            $s->table('restaurant_table_sessions', fn (Blueprint $table) => $table->dropColumn('adopted_from_session_uuid'));
        }
    }
};
