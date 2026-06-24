<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-FIN-A — Manufacturing posting settings (Phase A).
 *
 * Stores the per-tenant account mapping + inventory policy that a FUTURE posting
 * layer will read. This table holds CONFIGURATION ONLY: creating or enabling a
 * row posts nothing — no journal entries, no stock-ledger movements. The UI in
 * Phase A manages a single tenant-default row (branch_id = null); branch-level
 * overrides are a future phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('manufacturing_posting_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('is_enabled')->default(false);

            // Account mappings (resolve to accounts.id). Nullable until configured.
            $table->unsignedBigInteger('raw_material_inventory_account_id')->nullable();
            $table->unsignedBigInteger('wip_inventory_account_id')->nullable();
            $table->unsignedBigInteger('finished_goods_inventory_account_id')->nullable();
            $table->unsignedBigInteger('manufacturing_overhead_account_id')->nullable();
            $table->unsignedBigInteger('direct_labour_account_id')->nullable();
            $table->unsignedBigInteger('scrap_expense_account_id')->nullable();
            $table->unsignedBigInteger('rework_expense_account_id')->nullable();
            $table->unsignedBigInteger('production_variance_account_id')->nullable();
            $table->unsignedBigInteger('manufactured_cogs_account_id')->nullable();
            $table->unsignedBigInteger('inventory_adjustment_account_id')->nullable();

            // Policy (Phase A allows a single safe value each; widened in later phases).
            $table->string('negative_stock_policy', 30)->default('block');
            $table->string('costing_method', 30)->default('moving_average');
            $table->string('fg_cost_source', 30)->default('wip_actual');
            $table->boolean('labour_absorption_enabled')->default(false);
            $table->boolean('overhead_absorption_enabled')->default(false);

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            // Explicit short index names (MySQL 64-char identifier limit).
            $table->index('branch_id', 'mfg_post_set_branch_idx');
            $table->index('raw_material_inventory_account_id', 'mfg_post_set_rm_acc_idx');
            $table->index('wip_inventory_account_id', 'mfg_post_set_wip_acc_idx');
            $table->index('finished_goods_inventory_account_id', 'mfg_post_set_fg_acc_idx');
            $table->index('manufacturing_overhead_account_id', 'mfg_post_set_oh_acc_idx');
            $table->index('direct_labour_account_id', 'mfg_post_set_lab_acc_idx');
            $table->index('scrap_expense_account_id', 'mfg_post_set_scrap_acc_idx');
            $table->index('rework_expense_account_id', 'mfg_post_set_rework_acc_idx');
            $table->index('production_variance_account_id', 'mfg_post_set_var_acc_idx');
            $table->index('manufactured_cogs_account_id', 'mfg_post_set_cogs_acc_idx');
            $table->index('inventory_adjustment_account_id', 'mfg_post_set_adj_acc_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('manufacturing_posting_settings');
    }
};
