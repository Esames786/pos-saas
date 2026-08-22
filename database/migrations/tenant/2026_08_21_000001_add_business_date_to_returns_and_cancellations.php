<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REPORT-BUSINESS-DATE-1 — a return and an item-cancellation must know which
 * BUSINESS DAY they belong to, not just their wall-clock date.
 *
 * A restaurant runs past midnight. A sale rung at 01:00 on a shift that opened
 * the previous evening already books to the previous business day (sales_orders
 * carries a frozen `business_date`). But returns and item-cancellations had no
 * such column, so reports allocated them by their raw calendar date
 * (`return_date` / `cancelled_at`). A refund punched after midnight, while the
 * pre-midnight shift was still open, therefore landed on the WRONG day and the
 * day's figures never reconciled (Khatri 08-20: three 08-19 returns totalling
 * 1,450 wrongly subtracted from 08-20's Net Sales).
 *
 * The fix carries a `business_date` on both rows, taken from the ORIGINATING
 * order's business_date — the same order/shift whose cash the return already
 * adjusts, so the report and the cash reconciliation agree.
 *
 * Additive, nullable, reversible. The backfill only ever writes the new column,
 * deriving it from data that is already correct (the order's business_date), so
 * no amount, refund, stock row or journal entry is touched. Reports read
 * COALESCE(business_date, DATE(<calendar>)) so any un-backfilled row (e.g. an
 * order with a NULL business_date) behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('sales_returns')
            && ! Schema::connection('tenant')->hasColumn('sales_returns', 'business_date')) {
            Schema::connection('tenant')->table('sales_returns', function (Blueprint $table) {
                $table->date('business_date')->nullable()->after('return_date');
            });

            DB::connection('tenant')->statement(
                'UPDATE sales_returns r
                    JOIN sales_orders o ON o.id = r.sales_order_id
                    SET r.business_date = o.business_date
                    WHERE r.business_date IS NULL AND o.business_date IS NOT NULL'
            );
        }

        if (Schema::connection('tenant')->hasTable('sales_order_line_cancellations')
            && ! Schema::connection('tenant')->hasColumn('sales_order_line_cancellations', 'business_date')) {
            Schema::connection('tenant')->table('sales_order_line_cancellations', function (Blueprint $table) {
                $table->date('business_date')->nullable()->after('cancelled_at');
            });

            DB::connection('tenant')->statement(
                'UPDATE sales_order_line_cancellations x
                    JOIN sales_orders o ON o.id = x.sales_order_id
                    SET x.business_date = o.business_date
                    WHERE x.business_date IS NULL AND o.business_date IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        foreach (['sales_returns', 'sales_order_line_cancellations'] as $table) {
            if (Schema::connection('tenant')->hasColumn($table, 'business_date')) {
                Schema::connection('tenant')->table($table, function (Blueprint $t) {
                    $t->dropColumn('business_date');
                });
            }
        }
    }
};
