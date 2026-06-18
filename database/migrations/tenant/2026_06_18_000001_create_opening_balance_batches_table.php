<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('opening_balance_batches', function (Blueprint $table) {
            $table->id();

            $table->string('batch_no')->unique();
            $table->date('opening_date');

            // Branch is optional — an opening batch can be company-wide.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->text('description')->nullable();

            // draft | posted | void
            $table->string('status', 20)->default('draft');

            $table->decimal('total_debit', 15, 4)->default(0);
            $table->decimal('total_credit', 15, 4)->default(0);

            // GL journal produced when the batch is posted (FIN-13).
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->timestamps();

            $table->index('opening_date');
            $table->index('status');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('opening_balance_batches');
    }
};
