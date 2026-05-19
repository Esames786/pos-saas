<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('shifts', 'total_cash_refunds')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->decimal('total_cash_refunds', 12, 2)->default(0)->after('total_refunds');
                $table->decimal('total_card_refunds', 12, 2)->default(0)->after('total_cash_refunds');
                $table->decimal('total_bank_refunds',  12, 2)->default(0)->after('total_card_refunds');
                $table->decimal('total_other_refunds', 12, 2)->default(0)->after('total_bank_refunds');
            });
        }

        if (!Schema::hasColumn('daily_closings', 'terminal_id')) {
            Schema::table('daily_closings', function (Blueprint $table) {
                $table->unsignedBigInteger('terminal_id')->nullable()->after('branch_id');
                $table->foreign('terminal_id')->references('id')->on('terminals')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('daily_closings', 'total_cash_refunds')) {
            Schema::table('daily_closings', function (Blueprint $table) {
                $table->decimal('total_cash_refunds', 12, 2)->default(0)->after('total_refunds');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shifts', 'total_cash_refunds')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->dropColumn([
                    'total_cash_refunds',
                    'total_card_refunds',
                    'total_bank_refunds',
                    'total_other_refunds',
                ]);
            });
        }

        Schema::table('daily_closings', function (Blueprint $table) {
            if (Schema::hasColumn('daily_closings', 'terminal_id')) {
                $table->dropForeign(['terminal_id']);
                $table->dropColumn('terminal_id');
            }

            if (Schema::hasColumn('daily_closings', 'total_cash_refunds')) {
                $table->dropColumn('total_cash_refunds');
            }
        });
    }
};
