<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATEGORY-BRANCH-SCOPE-1 — a category may belong to one branch.
 *
 * A tenant has ONE product book, and the POS grid was never branch-aware: every active,
 * sellable, POS-visible product appeared on every branch's screen. That is fine for a tenant
 * whose branches sell the same menu, and wrong for one whose branches are different
 * restaurants — the second counter would be handed a menu it does not sell.
 *
 * NULL MEANS EVERY BRANCH, and that is the default for every row that already exists. So this
 * column changes nothing for any current tenant by construction; it only gives a tenant that
 * needs the separation somewhere to say so. The same `branch_id IS NULL OR branch_id = ?`
 * idiom is already used for combos, waiters and printer mappings.
 *
 * Products follow their category — there is deliberately no `products.branch_id`. One place to
 * set it, one place to get it wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('categories', 'branch_id')) {
            return;
        }

        Schema::connection('tenant')->table('categories', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('branches')
                ->nullOnDelete();

            $table->index(['branch_id', 'is_active'], 'categories_branch_active_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasColumn('categories', 'branch_id')) {
            return;
        }

        Schema::connection('tenant')->table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_branch_active_idx');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
