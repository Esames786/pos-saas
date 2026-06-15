<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Double-entry General Ledger header (FIN-7). Each entry must balance:
        // total_debit == total_credit.
        Schema::connection('tenant')->create('journal_entries', function (Blueprint $table) {
            $table->id();

            $table->string('entry_no')->unique();
            $table->date('entry_date');

            // Originating business event (expense_voucher / supplier_payment / ...).
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_no')->nullable();

            $table->string('description')->nullable();

            // draft | posted | void
            $table->string('status', 20)->default('posted');

            $table->decimal('total_debit', 15, 4)->default(0);
            $table->decimal('total_credit', 15, 4)->default(0);

            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->foreignId('reversed_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->boolean('is_reversal')->default(false);

            $table->timestamps();

            $table->index('entry_date');
            $table->index(['source_type', 'source_id']);
            $table->index('status');
            $table->index('is_reversal');
            $table->index('reversed_entry_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('journal_entries');
    }
};
