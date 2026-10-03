<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GRN-VOID-1 — a receipt that has not been billed yet can be taken back.
 *
 * `status` was enum('posted') with no second value: a GRN was a posting, full stop, and a wrong
 * one could only be lived with. It is still not EDITABLE — editing a posted receipt would rewrite
 * stock history that sales and costs already depend on — but it can now be VOIDED, which reverses
 * the stock through its own ledger entries and leaves the original where it is.
 *
 * Voided, not deleted: the reversal rows reference this receipt, and deleting it would leave them
 * pointing at nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('tenant')->statement(
            "ALTER TABLE goods_receipts MODIFY COLUMN status ENUM('posted','voided') NOT NULL DEFAULT 'posted'"
        );

        Schema::connection('tenant')->table('goods_receipts', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('goods_receipts', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('posted_at');
            }
            if (! Schema::connection('tenant')->hasColumn('goods_receipts', 'voided_by_user_id')) {
                $table->unsignedBigInteger('voided_by_user_id')->nullable()->after('voided_at');
            }
            if (! Schema::connection('tenant')->hasColumn('goods_receipts', 'void_reason')) {
                $table->string('void_reason', 255)->nullable()->after('voided_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('goods_receipts', function (Blueprint $table) {
            foreach (['void_reason', 'voided_by_user_id', 'voided_at'] as $column) {
                if (Schema::connection('tenant')->hasColumn('goods_receipts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        DB::connection('tenant')->statement(
            "ALTER TABLE goods_receipts MODIFY COLUMN status ENUM('posted') NOT NULL DEFAULT 'posted'"
        );
    }
};
