<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUPPLIER-RUNNING-ACCOUNT-1 — pay a supplier on account, beyond what is owed.
 *
 * `purchasing_settings`: one row; `supplier_running_account` OFF by default, so every tenant keeps
 * today's refusal until its owner turns it on (finance:supplier-running-account).
 *
 * `supplier_credit_allocations`: which credit (a payment or a purchase return) settled which bill,
 * and how much. A bill's amount_paid is the sum of its allocations; credit not allocated is the
 * supplier's advance. Additive only — nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('purchasing_settings')) {
            Schema::connection('tenant')->create('purchasing_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('supplier_running_account')->default(false);
                $table->timestamp('supplier_running_account_changed_at')->nullable();
                $table->unsignedBigInteger('supplier_running_account_changed_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::connection('tenant')->hasTable('supplier_credit_allocations')) {
            Schema::connection('tenant')->create('supplier_credit_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('supplier_id')->index();
                $table->string('source_type', 20);   // payment | return
                $table->unsignedBigInteger('source_id');
                $table->unsignedBigInteger('purchase_bill_id')->index();
                $table->decimal('amount', 15, 4);
                $table->timestamps();

                $table->index(['source_type', 'source_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('supplier_credit_allocations');
        Schema::connection('tenant')->dropIfExists('purchasing_settings');
    }
};
