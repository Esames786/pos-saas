<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('expense_categories', function (Blueprint $table) {
            $table->id();

            // Links to an expense-type Chart of Account (enforced in controller/service).
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();

            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index('account_id');
            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('expense_categories');
    }
};
