<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-CUSTOMER-CREDIT-1 — an invoice can only absorb its own value.
 *
 * The invoice already stored advance_total (everything received before it was
 * issued) and cleared that whole amount from Customer Advances into Receivables.
 * If the customer had paid more than the invoice, the extra was cleared out of
 * the liability too, so money the business still owes stopped being visible as
 * money owed.
 *
 * advance_applied records what the invoice actually absorbed — never more than
 * its own grand total. The difference stays in 2300 Customer Advances, where it
 * belongs, until it is refunded.
 *
 * NULL on every invoice issued before this migration. Those postings are
 * historical fact and are read back exactly as they were posted; the new column
 * governs invoices issued from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('catering_final_invoices')
            && ! Schema::connection('tenant')->hasColumn('catering_final_invoices', 'advance_applied')) {
            Schema::connection('tenant')->table('catering_final_invoices', function (Blueprint $table) {
                $table->decimal('advance_applied', 14, 2)->nullable()->after('advance_total');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('catering_final_invoices', 'advance_applied')) {
            Schema::connection('tenant')->table('catering_final_invoices', function (Blueprint $table) {
                $table->dropColumn('advance_applied');
            });
        }
    }
};
