<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 CONTINUITY (stream A, CP3 gaps 4 + 5) — what the K1 live state already carries and the mirror now keeps:
 *
 *   edge_mirror_order_lines.combo_id / parent_line_uuid   deal structure (header → components) so a deal order is adoptable
 *   edge_mirror_order_lines.kot_sent_qty                  the exact kitchen-sent quantity (a line sent in part fires only the rest)
 *   edge_mirror_order_lines.voided                        a line voided online (such an order stays with the Cloud)
 *   edge_mirror_table_orders / edge_mirror_held_sales .kot_batches
 *                                                         the order's Cloud KOT events [{event_uuid, sequence_no, event_type}] —
 *                                                         adoption records them on the appliance so the next kitchen ticket
 *                                                         CONTINUES the Cloud numbering (#3 after #1 and #2), never a second "#1"
 *   edge_mirror_held_sales.payments                       Cloud payments of a held bill (a bill paid in part stays with the Cloud)
 *
 * No new table (the fail-closed tenant reset classifies every table by name). EDGE-ONLY, additive, idempotent.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if ($s->hasTable('edge_mirror_order_lines') && ! $s->hasColumn('edge_mirror_order_lines', 'parent_line_uuid')) {
            $s->table('edge_mirror_order_lines', function (Blueprint $table) {
                $table->unsignedBigInteger('combo_id')->nullable();
                $table->char('parent_line_uuid', 26)->nullable();
                $table->decimal('kot_sent_qty', 14, 3)->nullable();
                $table->boolean('voided')->default(false);
            });
        }
        foreach (['edge_mirror_table_orders', 'edge_mirror_held_sales'] as $t) {
            if ($s->hasTable($t) && ! $s->hasColumn($t, 'kot_batches')) {
                $s->table($t, function (Blueprint $table) {
                    $table->json('kot_batches')->nullable();
                    $table->decimal('delivery_charge', 14, 2)->default(0);   // part of the coherent money (total = … + delivery)
                });
            }
        }
        if ($s->hasTable('edge_mirror_held_sales') && ! $s->hasColumn('edge_mirror_held_sales', 'payments')) {
            $s->table('edge_mirror_held_sales', fn (Blueprint $table) => $table->json('payments')->nullable());
        }
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        if ($s->hasColumn('edge_mirror_order_lines', 'parent_line_uuid')) {
            $s->table('edge_mirror_order_lines', fn (Blueprint $table) => $table->dropColumn(['combo_id', 'parent_line_uuid', 'kot_sent_qty', 'voided']));
        }
        foreach (['edge_mirror_table_orders', 'edge_mirror_held_sales'] as $t) {
            if ($s->hasColumn($t, 'kot_batches')) {
                $s->table($t, fn (Blueprint $table) => $table->dropColumn(['kot_batches', 'delivery_charge']));
            }
        }
        if ($s->hasColumn('edge_mirror_held_sales', 'payments')) {
            $s->table('edge_mirror_held_sales', fn (Blueprint $table) => $table->dropColumn('payments'));
        }
    }
};
