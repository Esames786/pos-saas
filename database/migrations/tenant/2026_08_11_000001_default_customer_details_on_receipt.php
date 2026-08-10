<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Customer name + phone print on the bill by default (client 2026-08-11): the delivery rider and
// the counter both need the number. Receipts only — KOT/Reminder layouts are untouched.
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('tenant')->table('receipt_layout_settings')
            ->where('document_type', 'receipt')
            ->update(['show_customer_name' => true]);
    }

    public function down(): void
    {
        // no-op: this is a display default, and turning it back off would fight an explicit choice.
    }
};
