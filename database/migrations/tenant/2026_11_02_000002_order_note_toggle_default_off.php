<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KOT-ORDER-NOTE-1 (2) — the KOT's "Order Note" switch is OFF by default (owner, 2 Nov: "Default OFF kardo").
 *
 * The column arrived the same morning with default ON. Before turning it off everywhere, the last 30 days were
 * counted on prod: counter-typed order notes = 0 at every tenant except 6 automatic "Split from …" notes at one
 * — no kitchen instruction is lost. So: the column default becomes false, and every existing row goes OFF
 * (nobody had chosen ON yet; it was only the default). An operator turns it on per branch in Edit Layout.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('tenant');
        if (! $s->hasColumn('receipt_layout_settings', 'show_order_note')) {
            return;
        }
        $s->table('receipt_layout_settings', function (Blueprint $table) {
            $table->boolean('show_order_note')->default(false)->change();
        });
        DB::connection('tenant')->table('receipt_layout_settings')->update(['show_order_note' => false]);
    }

    public function down(): void
    {
        $s = Schema::connection('tenant');
        if (! $s->hasColumn('receipt_layout_settings', 'show_order_note')) {
            return;
        }
        $s->table('receipt_layout_settings', function (Blueprint $table) {
            $table->boolean('show_order_note')->default(true)->change();
        });
    }
};
