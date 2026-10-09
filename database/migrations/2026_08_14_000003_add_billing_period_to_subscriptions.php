<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLOUD-BILLING-2 — per-subscription billing period (monthly / yearly).
 *
 * The plan already carries a billing_period, but a subscription had none — the yearly/monthly choice
 * a customer makes at signup had nowhere to live. Additive enum defaulting to monthly, so every
 * existing subscription reads as monthly (backfill is the column default).
 */
return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        if (Schema::connection('master')->hasColumn('subscriptions', 'billing_period')) {
            return;
        }

        Schema::connection('master')->table('subscriptions', function (Blueprint $table) {
            $table->enum('billing_period', ['monthly', 'yearly'])->default('monthly')->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('master')->hasColumn('subscriptions', 'billing_period')) {
            return;
        }

        Schema::connection('master')->table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('billing_period');
        });
    }
};
