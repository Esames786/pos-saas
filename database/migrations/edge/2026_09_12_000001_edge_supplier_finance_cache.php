<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE EDGE — F2 SUPPLIER FINANCE PARITY (appliance side).
 *
 * The READ-ONLY warm projection of the Cloud's supplier finance (replaced wholesale on every refresh), and the
 * appliance's own LOCAL OPERATIONAL supplier-finance events (immutable, pending until the Cloud posts the official
 * transaction). Nothing here is an accounting ledger: no local GL, no local AP control, no local cash/bank ledger —
 * the appliance shows the Cloud's official position adjusted by its own pending events.
 *
 * Index names are explicit: MySQL caps identifiers at 64 characters and the generated ones would exceed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('edge_supplier_finance_suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cloud_supplier_id')->unique('esf_sup_cloud_id_uq');
            $table->string('code', 50);
            $table->string('name');
            $table->string('status', 20);
            $table->decimal('cloud_payable', 15, 4)->default(0);      // AUTHORITATIVE Cloud payable at as_of
            $table->timestamp('cloud_updated_at')->nullable();
            $table->timestamp('refreshed_at');
            $table->timestamps();
        });

        Schema::connection('tenant')->create('edge_supplier_finance_bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cloud_bill_id')->unique('esf_bill_cloud_id_uq');
            $table->unsignedBigInteger('cloud_supplier_id')->index('esf_bill_supplier_idx');
            $table->unsignedBigInteger('branch_id');
            $table->string('bill_no', 50);
            $table->string('supplier_invoice_no', 100)->nullable();
            $table->date('bill_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 20);
            $table->decimal('grand_total', 15, 4)->default(0);
            $table->decimal('amount_paid', 15, 4)->default(0);
            $table->decimal('balance_due', 15, 4)->default(0);
            $table->timestamp('cloud_updated_at')->nullable();
            $table->timestamp('refreshed_at');
            $table->timestamps();
        });

        Schema::connection('tenant')->create('edge_supplier_finance_cash_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cloud_cash_bank_account_id')->unique('esf_cb_cloud_id_uq');
            $table->string('code', 50);
            $table->string('name');
            $table->string('account_type', 20);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('coa_account_id')->nullable();   // chart-of-accounts mapping (null = no GL → unusable)
            $table->string('coa_code', 50)->nullable();
            $table->string('bank_name')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->decimal('cloud_balance', 15, 4)->default(0);
            $table->timestamp('cloud_updated_at')->nullable();
            $table->timestamp('refreshed_at');
            $table->timestamps();
        });

        Schema::connection('tenant')->create('edge_supplier_finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cloud_account_id')->unique('esf_acc_cloud_id_uq');
            $table->string('code', 50);
            $table->string('name');
            $table->string('type', 20);
            $table->string('normal_balance', 10);
            $table->unsignedBigInteger('parent_cloud_account_id')->nullable();
            $table->boolean('is_ap')->default(false);                   // canonical Accounts Payable family (2100 + descendants)
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamp('refreshed_at');
            $table->timestamps();
        });

        Schema::connection('tenant')->create('edge_supplier_finance_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cloud_ledger_id')->unique('esf_led_cloud_id_uq');
            $table->unsignedBigInteger('cloud_supplier_id')->index('esf_led_supplier_idx');
            $table->string('entry_type', 50);
            $table->string('direction', 10);
            $table->decimal('amount', 15, 4);
            $table->decimal('balance_after', 15, 4);
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_no', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('created_by_name')->nullable();
            $table->char('edge_event_uuid', 26)->nullable()->index('esf_led_event_idx');   // the Edge event this official row came from
            $table->timestamp('cloud_created_at')->nullable();
            $table->timestamp('refreshed_at');
            $table->timestamps();
        });

        Schema::connection('tenant')->create('edge_supplier_finance_applied_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_uuid', 26)->unique('esf_applied_event_uq');
            $table->string('event_type', 48);
            $table->string('official_reference_no', 64)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('refreshed_at');
            $table->timestamps();
        });

        // The appliance's OWN immutable supplier-finance events (operational history; PENDING SYNC until the Cloud posts).
        Schema::connection('tenant')->create('edge_local_supplier_finance_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_uuid', 26)->unique('elsf_event_uuid_uq');
            $table->string('event_type', 48);                            // supplier_payment | supplier_ap_journal_adjustment
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->date('business_date');
            $table->string('description', 500)->nullable();
            $table->string('reference_no', 100)->nullable();
            $table->decimal('amount', 15, 4);
            $table->json('payload');                                     // the business content (for display; the envelope is the truth)
            $table->string('envelope_schema_version', 64);
            $table->char('content_hash', 64);
            $table->string('finance_watermark', 128)->nullable();        // projection the event validated against
            $table->timestamps();
            $table->index(['branch_id', 'event_type'], 'elsf_events_branch_type_idx');
        });

        // One row per dimension effect of an event: supplier payable delta, cash/bank delta, bill allocation.
        Schema::connection('tenant')->create('edge_local_supplier_finance_effects', function (Blueprint $table) {
            $table->id();
            $table->char('event_uuid', 26)->index('elsf_eff_event_idx');
            $table->unsignedBigInteger('cloud_supplier_id')->nullable()->index('elsf_eff_supplier_idx');
            $table->unsignedBigInteger('cloud_cash_bank_account_id')->nullable()->index('elsf_eff_cb_idx');
            $table->unsignedBigInteger('cloud_bill_id')->nullable()->index('elsf_eff_bill_idx');
            $table->decimal('payable_delta', 15, 4)->default(0);        // negative = payable reduced
            $table->decimal('cash_delta', 15, 4)->default(0);           // negative = cash/bank reduced
            $table->timestamps();
        });

        Schema::connection('tenant')->table('edge_local_meta', function (Blueprint $table) {
            $table->string('supplier_finance_cache_watermark', 128)->nullable();
            $table->timestamp('supplier_finance_cache_as_of')->nullable();
            $table->timestamp('supplier_finance_cache_refreshed_at')->nullable();
            $table->string('standby_supplier_finance_watermark_seen', 128)->nullable();
            $table->timestamp('standby_supplier_finance_as_of_seen')->nullable();
            $table->text('supplier_finance_rules')->nullable();          // the canonical rules + permission names the projection carried (JSON)
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('edge_local_meta', function (Blueprint $table) {
            $table->dropColumn(['supplier_finance_cache_watermark', 'supplier_finance_cache_as_of', 'supplier_finance_cache_refreshed_at', 'standby_supplier_finance_watermark_seen', 'standby_supplier_finance_as_of_seen', 'supplier_finance_rules']);
        });
        foreach (['edge_local_supplier_finance_effects', 'edge_local_supplier_finance_events', 'edge_supplier_finance_applied_events', 'edge_supplier_finance_ledger_entries',
            'edge_supplier_finance_accounts', 'edge_supplier_finance_cash_bank_accounts', 'edge_supplier_finance_bills', 'edge_supplier_finance_suppliers'] as $t) {
            Schema::connection('tenant')->dropIfExists($t);
        }
    }
};
