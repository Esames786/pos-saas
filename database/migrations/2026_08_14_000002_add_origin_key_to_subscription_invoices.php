<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLOUD-BILLING-1B — a deterministic idempotency key for auto-generated invoices.
 *
 * The signup first-invoice (and any future auto-generated invoice) carries a unique origin_key
 * so re-running provisioning / a retry can never create a duplicate. `invoice_no` is random and
 * not idempotency-friendly; this replaces "count then insert" race-prone logic with a UNIQUE
 * constraint the database enforces. Nullable so all existing/manual invoices are unaffected.
 */
return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        if (Schema::connection('master')->hasColumn('subscription_invoices', 'origin_key')) {
            return;
        }

        Schema::connection('master')->table('subscription_invoices', function (Blueprint $table) {
            $table->string('origin_key')->nullable()->unique()->after('invoice_no');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('master')->hasColumn('subscription_invoices', 'origin_key')) {
            return;
        }

        Schema::connection('master')->table('subscription_invoices', function (Blueprint $table) {
            $table->dropUnique(['origin_key']);
            $table->dropColumn('origin_key');
        });
    }
};
