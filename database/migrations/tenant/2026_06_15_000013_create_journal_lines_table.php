<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('journal_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('description')->nullable();

            // Each line has either debit > 0 OR credit > 0, never both.
            $table->decimal('debit', 15, 4)->default(0);
            $table->decimal('credit', 15, 4)->default(0);

            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index('journal_entry_id');
            $table->index('account_id');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('journal_lines');
    }
};
