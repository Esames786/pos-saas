<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Bingoo branding footer toggle ("BingooPos / Bingoopos.com") per layout.
// Default ON for receipts only (user decision 2026-08-10); KOT/Reminder stay off.
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'show_bingoo_branding')) {
                $table->boolean('show_bingoo_branding')->default(false)->after('show_payment_breakdown');
            }
        });

        DB::connection('tenant')->table('receipt_layout_settings')
            ->where('document_type', 'receipt')
            ->update(['show_bingoo_branding' => true]);
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'show_bingoo_branding')) {
                $table->dropColumn('show_bingoo_branding');
            }
        });
    }
};
