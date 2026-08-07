<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THIRD-PARTY-DEPARTMENT-HANDOVER-1 — a department can be flagged as owned/operated by a third party
 * (e.g. "Kashif Kitchen" owning the BBQ category). Its sales are collected through our POS but belong
 * to the owner; a one-click handover reclassifies that day's/range's department sales out of our
 * income into a per-owner payable, and a payout settles it by cash/bank. Money-only — stock/COGS is
 * never touched.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection($this->connection)->table('departments', function (Blueprint $table) {
            $table->boolean('is_third_party')->default(false)->after('status');
            $table->string('owner_name', 190)->nullable()->after('is_third_party');
            $table->string('owner_contact', 190)->nullable()->after('owner_name');
            // The per-owner liability sub-account (child of 2400), auto-created on first handover.
            $table->unsignedBigInteger('payable_account_id')->nullable()->after('owner_contact');
        });

        Schema::connection($this->connection)->create('department_handovers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('department_id');
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('handover_total', 15, 4);
            $table->unsignedBigInteger('payable_account_id');            // liability account credited
            $table->unsignedBigInteger('reclass_journal_entry_id')->nullable(); // Dr 4210 / Cr payable
            $table->unsignedBigInteger('payout_journal_entry_id')->nullable();  // Dr payable / Cr cash-bank
            $table->unsignedBigInteger('payout_cash_bank_account_id')->nullable();
            // pending_payout | settled | reversed
            $table->string('status', 20)->default('pending_payout');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by_user_id')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'department_id'], 'dept_handover_branch_dept_idx');
            $table->index(['department_id', 'period_from', 'period_to'], 'dept_handover_period_idx');
            $table->index('status', 'dept_handover_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('department_handovers');
        Schema::connection($this->connection)->table('departments', function (Blueprint $table) {
            $table->dropColumn(['is_third_party', 'owner_name', 'owner_contact', 'payable_account_id']);
        });
    }
};
