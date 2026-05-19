<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Printers
        if (!Schema::connection('tenant')->hasTable('printers')) {
            Schema::connection('tenant')->create('printers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('name');
                $table->string('code')->unique();
                $table->enum('printer_type', ['network', 'usb', 'browser'])->default('browser');
                $table->enum('print_role', ['receipt', 'kot', 'both'])->default('receipt');
                $table->string('ip_address')->nullable();
                $table->unsignedSmallInteger('port')->nullable();
                $table->enum('paper_size', ['58mm', '80mm', 'A4'])->default('80mm');
                $table->unsignedTinyInteger('characters_per_line')->default(42);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // Category → Printer mappings
        if (!Schema::connection('tenant')->hasTable('category_printer_mappings')) {
            Schema::connection('tenant')->create('category_printer_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('category_id')->index();
                $table->unsignedBigInteger('printer_id')->index();
                $table->enum('print_role', ['kot', 'receipt'])->default('kot');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['branch_id', 'category_id', 'print_role'], 'cat_printer_branch_cat_role_unique');
            });
        }

        // Terminal printer settings
        if (!Schema::connection('tenant')->hasTable('terminal_printer_settings')) {
            Schema::connection('tenant')->create('terminal_printer_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('terminal_id')->unique()->index();
                $table->unsignedBigInteger('receipt_printer_id')->nullable()->index();
                $table->unsignedBigInteger('kot_printer_id')->nullable()->index();
                $table->boolean('auto_print_receipt')->default(false);
                $table->boolean('auto_print_kot')->default(false);
                $table->timestamps();
            });
        }

        // User printer settings
        if (!Schema::connection('tenant')->hasTable('user_printer_settings')) {
            Schema::connection('tenant')->create('user_printer_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique()->index();
                $table->unsignedBigInteger('receipt_printer_id')->nullable()->index();
                $table->unsignedBigInteger('kot_printer_id')->nullable()->index();
                $table->boolean('remember_last_kot_printers')->default(true);
                $table->timestamps();
            });
        }

        // Receipt/KOT layout settings
        if (!Schema::connection('tenant')->hasTable('receipt_layout_settings')) {
            Schema::connection('tenant')->create('receipt_layout_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->enum('document_type', ['receipt', 'kot'])->default('receipt');
                $table->enum('paper_size', ['58mm', '80mm', 'A4'])->default('80mm');
                $table->string('logo_path')->nullable();
                $table->boolean('show_logo')->default(true);
                $table->boolean('show_branch_name')->default(true);
                $table->boolean('show_branch_address')->default(true);
                $table->boolean('show_branch_phone')->default(true);
                $table->boolean('show_tax_number')->default(true);
                $table->boolean('show_cashier_name')->default(true);
                $table->boolean('show_customer_name')->default(false);
                $table->boolean('show_table_info')->default(true);
                $table->boolean('show_order_no')->default(true);
                $table->boolean('show_item_codes')->default(false);
                $table->boolean('show_payment_breakdown')->default(true);
                $table->text('header_text')->nullable();
                $table->text('footer_text')->nullable();
                $table->unsignedTinyInteger('font_size')->default(12);
                $table->unsignedTinyInteger('kot_font_size')->default(14);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['branch_id', 'document_type']);
            });
        }

        // Print jobs queue
        if (!Schema::connection('tenant')->hasTable('print_jobs')) {
            Schema::connection('tenant')->create('print_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('job_no')->unique();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('terminal_id')->nullable();
                $table->unsignedBigInteger('printer_id')->nullable()->index();
                $table->enum('document_type', ['receipt', 'kot']);
                $table->enum('print_status', ['queued', 'printed', 'failed', 'cancelled'])->default('queued')->index();
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('reference_no')->nullable();
                $table->json('payload')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('printed_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
            });
        }

        // Patch sales_orders
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('sales_orders', 'receipt_print_count')) {
                $table->unsignedSmallInteger('receipt_print_count')->default(0)->after('notes');
            }
            if (!Schema::connection('tenant')->hasColumn('sales_orders', 'kot_print_count')) {
                $table->unsignedSmallInteger('kot_print_count')->default(0)->after('receipt_print_count');
            }
            if (!Schema::connection('tenant')->hasColumn('sales_orders', 'last_receipt_printed_at')) {
                $table->timestamp('last_receipt_printed_at')->nullable()->after('kot_print_count');
            }
            if (!Schema::connection('tenant')->hasColumn('sales_orders', 'last_kot_printed_at')) {
                $table->timestamp('last_kot_printed_at')->nullable()->after('last_receipt_printed_at');
            }
        });

        // Patch sales_order_lines
        Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'kitchen_note')) {
                $table->string('kitchen_note')->nullable();
            }
            if (!Schema::connection('tenant')->hasColumn('sales_order_lines', 'kot_printed_quantity')) {
                $table->decimal('kot_printed_quantity', 10, 4)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('print_jobs');
        Schema::connection('tenant')->dropIfExists('receipt_layout_settings');
        Schema::connection('tenant')->dropIfExists('user_printer_settings');
        Schema::connection('tenant')->dropIfExists('terminal_printer_settings');
        Schema::connection('tenant')->dropIfExists('category_printer_mappings');
        Schema::connection('tenant')->dropIfExists('printers');
    }
};
