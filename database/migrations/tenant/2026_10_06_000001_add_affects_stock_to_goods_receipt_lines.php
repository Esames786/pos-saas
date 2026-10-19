<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GRN-NON-STOCK-1 — a GRN line can record a purchase without moving stock.
 *
 * Khatri buys cold drinks it sells but does not yet count (owner, 6 Oct): the purchase has to post
 * — GRN, bill, supplier payable — while inventory stays untouched. The line remembers, at the moment
 * it was received, whether it moved stock; the bill and any return read THAT, not the product's flag
 * today, so switching Track Stock on later never re-books an old receipt.
 *
 * Every existing line moved stock (an untracked product could not be received until now), so the
 * default 1 is true of all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('goods_receipt_lines', 'affects_stock')) {
            Schema::connection('tenant')->table('goods_receipt_lines', function (Blueprint $table) {
                $table->boolean('affects_stock')->default(true)->after('quantity_received');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('goods_receipt_lines', 'affects_stock')) {
            Schema::connection('tenant')->table('goods_receipt_lines', function (Blueprint $table) {
                $table->dropColumn('affects_stock');
            });
        }
    }
};
