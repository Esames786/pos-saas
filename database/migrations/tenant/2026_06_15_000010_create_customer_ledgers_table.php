<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operational customer receivable subledger (FIN-6). Debit raises receivable,
        // credit lowers it. NOT the General Ledger.
        Schema::connection('tenant')->create('customer_ledgers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->date('entry_date');

            // opening_balance | sale | payment | return | adjustment
            $table->string('entry_type', 30);
            // debit | credit
            $table->string('direction', 10);

            $table->decimal('amount', 15, 4);
            $table->decimal('balance_after', 15, 4);

            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_no')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('customer_id');
            $table->index('branch_id');
            $table->index('entry_date');
            $table->index('entry_type');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('customer_ledgers');
    }
};
