<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->enum('unit_type', ['quantity', 'weight', 'volume', 'length'])->default('quantity');
            $table->decimal('base_factor', 14, 6)->default(1);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('tenant')->create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('code', 50)->nullable()->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'is_active']);
        });

        Schema::connection('tenant')->create('category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('language_code', 10)->default('en');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'language_code']);
        });

        Schema::connection('tenant')->create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('sku')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('product_type', ['simple', 'recipe', 'hybrid', 'service'])->default('simple');
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_purchasable')->default(true);
            $table->boolean('is_stock_tracked')->default(true);
            $table->boolean('has_variants')->default(false);
            $table->boolean('has_expiry')->default(false);
            $table->boolean('requires_batch')->default(false);
            $table->decimal('default_purchase_price', 14, 2)->default(0);
            $table->decimal('default_selling_price', 14, 2)->default(0);
            $table->boolean('is_taxable')->default(false);
            $table->decimal('tax_rate_percent', 8, 4)->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index(['category_id', 'status']);
            $table->index(['product_type', 'is_stock_tracked']);
        });

        Schema::connection('tenant')->create('product_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('language_code', 10)->default('en');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'language_code']);
        });

        Schema::connection('tenant')->create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('name');
            $table->string('barcode')->nullable()->unique();
            $table->decimal('purchase_price', 14, 2)->default(0);
            $table->decimal('selling_price', 14, 2)->default(0);
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->decimal('reorder_quantity', 14, 3)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });

        Schema::connection('tenant')->create('product_variant_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('language_code', 10)->default('en');
            $table->string('name');
            $table->timestamps();

            $table->unique(['product_variant_id', 'language_code'], 'pvt_lang_unique');
        });

        Schema::connection('tenant')->create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->string('barcode')->unique();
            $table->enum('barcode_type', ['manual', 'system', 'supplier'])->default('manual');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'product_variant_id']);
        });

        Schema::connection('tenant')->create('product_branch_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('selling_price', 14, 2)->default(0);
            $table->decimal('minimum_selling_price', 14, 2)->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'product_id', 'product_variant_id'], 'branch_product_variant_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('product_branch_prices');
        Schema::connection('tenant')->dropIfExists('product_barcodes');
        Schema::connection('tenant')->dropIfExists('product_variant_translations');
        Schema::connection('tenant')->dropIfExists('product_variants');
        Schema::connection('tenant')->dropIfExists('product_translations');
        Schema::connection('tenant')->dropIfExists('products');
        Schema::connection('tenant')->dropIfExists('category_translations');
        Schema::connection('tenant')->dropIfExists('categories');
        Schema::connection('tenant')->dropIfExists('units');
    }
};
