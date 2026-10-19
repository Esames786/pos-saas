<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BILL-BARCODE-1 — the bill number printed as a big barcode on the bill and / or the KOT.
 *
 * Owner, 20 Oct: an order held at another terminal comes to the billing counter; the cashier scans the bill's
 * barcode and it comes up. "Bina kisi tenant ka KOT hilaye": a per-branch layout switch, OFF everywhere
 * (default false), so no live tenant's paper changes on deploy — only where an operator (or the demo setup)
 * turns it on in Edit Layout. Same pattern as show_column_dividers (default OFF).
 *
 * A tiny table (a few rows per tenant); a boolean with a default is an INSTANT change on MySQL 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'show_bill_barcode')) {
                $table->boolean('show_bill_barcode')->default(false)->after('show_heading_stars');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'show_bill_barcode')) {
                $table->dropColumn('show_bill_barcode');
            }
        });
    }
};
