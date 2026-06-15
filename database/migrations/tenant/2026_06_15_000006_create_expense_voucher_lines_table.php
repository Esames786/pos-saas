<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('expense_voucher_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('expense_voucher_id')->constrained('expense_vouchers')->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->string('description')->nullable();

            $table->decimal('amount', 15, 4);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('line_total', 15, 4);

            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index('expense_voucher_id');
            $table->index('expense_category_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('expense_voucher_lines');
    }
};
