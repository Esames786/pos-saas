<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATERING-SLICE-1: localization + catering product profile foundation.
 *
 * - customer_translations / supplier_translations mirror the existing
 *   product_translations / category_translations architecture exactly —
 *   optional per-language display values with the base row as fallback.
 *   The stable customers/suppliers tables are NOT modified.
 * - catering_product_profiles is a 1:1 extension of products (unique
 *   product_id), so the shared Product authority carries zero catering
 *   columns. Config-replicated for future Edge.
 * - catering_settings is a per-tenant singleton (nullable branch_id row
 *   pattern from manufacturing_posting_settings).
 *
 * No stock, finance, sales, or printing table is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('customer_translations')) {
            Schema::connection('tenant')->create('customer_translations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->string('language_code', 10)->default('en');
                $table->string('name');
                $table->timestamps();

                $table->unique(['customer_id', 'language_code'], 'customer_translations_unique');
            });
        }

        if (! Schema::connection('tenant')->hasTable('supplier_translations')) {
            Schema::connection('tenant')->create('supplier_translations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->string('language_code', 10)->default('en');
                $table->string('name');
                $table->timestamps();

                $table->unique(['supplier_id', 'language_code'], 'supplier_translations_unique');
            });
        }

        if (! Schema::connection('tenant')->hasTable('catering_product_profiles')) {
            Schema::connection('tenant')->create('catering_product_profiles', function (Blueprint $table) {
                $table->id();
                $table->char('profile_uuid', 26)->nullable()->unique();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->boolean('catering_enabled')->default(true);
                $table->foreignId('default_quote_unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->string('pricing_mode', 30)->default('per_pax'); // per_pax | fixed
                $table->decimal('default_catering_rate', 14, 2)->nullable();
                $table->string('production_station', 50)->nullable();
                $table->decimal('minimum_qty', 12, 3)->nullable();
                // Catering production labels are catering metadata, not catalog truth;
                // customer-facing display names come from product_translations.
                $table->string('production_label')->nullable();
                $table->string('production_label_ur')->nullable();
                $table->text('instructions')->nullable();
                $table->timestamps();

                $table->unique('product_id', 'catering_product_profiles_product_unique');
                $table->index(['catering_enabled', 'production_station'], 'catering_profiles_station_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('catering_settings')) {
            Schema::connection('tenant')->create('catering_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
                $table->string('reminder_recipient_email')->nullable();
                $table->decimal('default_service_charge_percent', 8, 4)->default(0);
                $table->string('print_language_profile', 10)->default('en'); // en | ur | both
                $table->json('reminder_offsets')->nullable(); // e.g. ["d7","d3","d1","same_day"]
                $table->timestamps();

                $table->unique('branch_id', 'catering_settings_branch_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('catering_settings');
        Schema::connection('tenant')->dropIfExists('catering_product_profiles');
        Schema::connection('tenant')->dropIfExists('supplier_translations');
        Schema::connection('tenant')->dropIfExists('customer_translations');
    }
};
