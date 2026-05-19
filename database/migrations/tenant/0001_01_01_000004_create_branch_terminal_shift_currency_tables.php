<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('branches', 'code')) {
            Schema::connection('tenant')->table('branches', function (Blueprint $table) {
                $table->string('code')->nullable()->after('id');
                $table->string('phone')->nullable()->after('address');
                $table->string('email')->nullable()->after('phone');
                $table->string('timezone')->default('Asia/Karachi')->after('email');
                $table->string('tax_registration_no')->nullable()->after('timezone');
                $table->boolean('is_tax_enabled')->default(false)->after('tax_registration_no');
                $table->boolean('show_tax_number_on_invoice')->default(false)->after('is_tax_enabled');
                $table->text('receipt_footer')->nullable()->after('show_tax_number_on_invoice');
            });
        }

        Schema::connection('tenant')->create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name');
            $table->string('symbol', 10);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('tenant')->create('currency_denominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->decimal('denomination_value', 12, 2);
            $table->enum('denomination_type', ['note', 'coin'])->default('note');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['currency_id', 'denomination_value']);
        });

        Schema::connection('tenant')->create('terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('device_identifier')->nullable();
            $table->boolean('requires_shift')->default(true);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::connection('tenant')->create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('terminal_id')->constrained('terminals')->cascadeOnDelete();
            $table->foreignId('opened_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->decimal('total_sales', 14, 2)->default(0);
            $table->decimal('total_cash', 14, 2)->default(0);
            $table->decimal('total_card', 14, 2)->default(0);
            $table->decimal('total_bank_transfer', 14, 2)->default(0);
            $table->decimal('total_cheque', 14, 2)->default(0);
            $table->decimal('total_refunds', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('expected_cash', 14, 2)->default(0);
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();

            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'terminal_id', 'status']);
        });

        Schema::connection('tenant')->create('daily_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->date('closing_date');
            $table->foreignId('closed_by_user_id')->constrained('users')->cascadeOnDelete();

            $table->decimal('total_sales', 14, 2)->default(0);
            $table->decimal('total_cash', 14, 2)->default(0);
            $table->decimal('total_card', 14, 2)->default(0);
            $table->decimal('total_bank_transfer', 14, 2)->default(0);
            $table->decimal('total_cheque', 14, 2)->default(0);
            $table->decimal('total_refunds', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('expected_cash', 14, 2)->default(0);
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();

            $table->enum('status', ['closed', 'approved'])->default('closed');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'closing_date']);
        });

        Schema::connection('tenant')->create('cash_count_lines', function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('currency_denomination_id')->constrained('currency_denominations')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('cash_count_lines');
        Schema::connection('tenant')->dropIfExists('daily_closings');
        Schema::connection('tenant')->dropIfExists('shifts');
        Schema::connection('tenant')->dropIfExists('terminals');
        Schema::connection('tenant')->dropIfExists('currency_denominations');
        Schema::connection('tenant')->dropIfExists('currencies');
    }
};
