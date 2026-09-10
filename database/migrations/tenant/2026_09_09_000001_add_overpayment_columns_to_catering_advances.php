<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATERING-OVERPAYMENT-1 (step 1 of 6) — room to record money taken beyond the
 * bill, and the reason it was taken.
 *
 * Both columns are ADDITIVE and default to "this never happened", so every
 * receipt already in the books keeps exactly the meaning it has now:
 *
 *   credit_portion      how much of THIS receipt is customer credit rather than
 *                       payment of a bill — the part that sits in 2300 Customer
 *                       Advances as money the business owes back. 0 on every
 *                       existing row, which is the truth: the model has refused
 *                       overpayment until now.
 *
 *   overpayment_reason  why the operator deliberately took more. Never optional
 *                       when the amount exceeds the balance — a liability the
 *                       business chose to accept must say who chose it and why.
 *
 * This migration alone changes NO behaviour. The guard that refuses overpayment
 * is untouched; nothing yet writes either column. That is deliberate: the
 * columns can reach production and sit unused while the posting they exist for
 * is still being checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('catering_advances', function (Blueprint $table) {
            $table->decimal('credit_portion', 14, 2)->default(0)->after('amount');
            $table->string('overpayment_reason', 255)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('catering_advances', function (Blueprint $table) {
            $table->dropColumn(['credit_portion', 'overpayment_reason']);
        });
    }
};
