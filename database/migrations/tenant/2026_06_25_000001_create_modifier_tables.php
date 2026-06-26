<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MOD-1: Product modifier / add-on catalog.
 *
 * modifier_groups       — a choice attached to products (e.g. "Crust", "Toppings"),
 *                         with min/max selection rules. Branch-aware (branch_id null = all).
 * modifiers             — the options inside a group (e.g. Thin Crust +0, Stuffed Crust +150),
 *                         each with a price_delta and an optional linked_product_id hook for
 *                         future inventory deduction (Phase 2 — unused in MOD-1).
 * product_modifier_group — attaches groups to products.
 *
 * Config only: no POS / printing / inventory pipeline impact at this stage.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('modifier_groups')) {
            Schema::connection('tenant')->create('modifier_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('name');
                $table->unsignedInteger('min_select')->default(0);     // 0 = optional
                $table->unsignedInteger('max_select')->nullable();      // null = unlimited
                $table->boolean('is_required')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamps();

                $table->index(['branch_id', 'status']);
            });
        }

        if (!Schema::connection('tenant')->hasTable('modifiers')) {
            Schema::connection('tenant')->create('modifiers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
                $table->string('name');
                $table->decimal('price_delta', 14, 2)->default(0);
                // Future inventory hook (Phase 2): when set, choosing this modifier deducts
                // the linked product's stock. Unused by MOD-1; nullable + nullOnDelete.
                $table->foreignId('linked_product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->boolean('is_default')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamps();

                $table->index(['modifier_group_id', 'status'], 'modifiers_group_status_index');
            });
        }

        if (!Schema::connection('tenant')->hasTable('product_modifier_group')) {
            Schema::connection('tenant')->create('product_modifier_group', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                // Explicit short index names — tenant table + two FK cols would otherwise
                // exceed MySQL's 64-char identifier limit.
                $table->unique(['product_id', 'modifier_group_id'], 'product_modifier_group_unique');
                $table->index('modifier_group_id', 'pmg_modifier_group_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('product_modifier_group');
        Schema::connection('tenant')->dropIfExists('modifiers');
        Schema::connection('tenant')->dropIfExists('modifier_groups');
    }
};
