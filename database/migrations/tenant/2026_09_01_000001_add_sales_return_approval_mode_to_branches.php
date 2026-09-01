<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RETURN-MANAGER-APPROVAL-1 — a branch can require a manager's PIN before a return posts.
 *
 * Posting a return is the most sensitive thing on the POS: it hands money back, puts stock
 * back on the shelf and writes to the ledger — and until now it went through with no approval
 * at all, while cancelling a single item already needed one.
 *
 * DEFAULT IS `auto_approve`, deliberately. Cancellations and manual discounts default to
 * `manager_required` because they always did; making this one match would stop every return at
 * every tenant the moment this deploys, until somebody found the setting. Turning it on is the
 * owner's decision, not a side effect of shipping the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('branches', 'sales_return_approval_mode')) {
            return;
        }

        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            $table->string('sales_return_approval_mode', 30)
                ->default('auto_approve')
                ->after('manual_discount_approval_mode');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasColumn('branches', 'sales_return_approval_mode')) {
            return;
        }

        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            $table->dropColumn('sales_return_approval_mode');
        });
    }
};
