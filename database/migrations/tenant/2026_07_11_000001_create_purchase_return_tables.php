<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PURCHASE-RETURNS-1 — supplier purchase returns.
 *
 * Completes the purchasing cycle: PO → GRN → Bill → Payment → RETURN.
 * Posting a return reduces official branch stock (movement purchase_return,
 * FEFO via InventoryService) and reduces the supplier payable (supplier
 * ledger credit + GL Dr 2100 AP / Cr 1400 Inventory — the exact mirror of
 * purchase-bill posting). A fully-paid supplier simply goes into credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('purchase_returns')) {
            Schema::connection('tenant')->create('purchase_returns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
                $table->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts')->nullOnDelete();
                $table->string('return_no', 60)->unique('pret_no_unique');
                $table->date('return_date');
                $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
                $table->decimal('subtotal', 14, 4)->default(0);
                $table->decimal('tax_total', 14, 4)->default(0);
                $table->decimal('discount_total', 14, 4)->default(0);
                $table->decimal('grand_total', 14, 4)->default(0);
                // damaged | expired | wrong_item | over_supply | quality_issue | price_dispute | other
                $table->string('reason_code', 50)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['branch_id', 'return_date'], 'pret_branch_date_idx');
                $table->index(['supplier_id', 'status'], 'pret_supplier_status_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('purchase_return_lines')) {
            Schema::connection('tenant')->create('purchase_return_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_return_id')
                    ->constrained('purchase_returns', indexName: 'pret_line_return_fk')
                    ->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->foreignId('inventory_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
                // v1 source = a goods_receipt_line; returnable = received − Σ(posted
                // return lines on the same source). NULL = standalone return
                // (validated against current stock only).
                $table->string('source_line_type', 50)->nullable();
                $table->unsignedBigInteger('source_line_id')->nullable();
                $table->decimal('quantity', 14, 3);
                $table->decimal('unit_cost', 14, 4)->default(0);
                $table->decimal('tax_amount', 14, 4)->default(0);
                $table->decimal('discount_amount', 14, 4)->default(0);
                $table->decimal('line_total', 14, 4)->default(0);
                $table->string('reason_code', 50)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['product_id', 'product_variant_id'], 'pret_line_prod_var_idx');
                $table->index(['source_line_type', 'source_line_id'], 'pret_line_source_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('purchase_return_lines');
        Schema::connection('tenant')->dropIfExists('purchase_returns');
    }
};
