<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE EDGE — F2: the Cloud registry of Edge-originated supplier-finance events (supplier payments and
 * supplier-aware manual AP journals). One row per event_uuid: same uuid + same hash → already_applied; same uuid +
 * different hash → conflict (no mutation). The official supplier payment / journal entry the Cloud posted is
 * recorded here so reconciliation, the projection ("this ledger row came from that Edge event") and lost-ACK
 * recovery all read one truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('edge_inbound_supplier_finance_ingestions', function (Blueprint $table) {
            $table->id();
            $table->char('event_uuid', 26)->unique();
            $table->string('event_type', 48);                    // supplier_payment | supplier_ap_journal_adjustment
            $table->char('content_hash', 64);
            $table->string('envelope_schema_version', 64);
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('device_public_uuid', 64);
            $table->unsignedBigInteger('activation_epoch');
            $table->unsignedBigInteger('config_revision')->nullable();
            $table->char('ingestion_uuid', 26)->unique();
            $table->string('status', 24);                        // applied | conflict | refused | exception
            $table->string('failure_code', 64)->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();          // the supplier (payment) — journals may name several
            $table->unsignedBigInteger('supplier_payment_id')->nullable();  // OFFICIAL supplier payment posted by the Cloud
            $table->unsignedBigInteger('journal_entry_id')->nullable();     // OFFICIAL manual journal posted by the Cloud
            $table->string('official_reference_no', 64)->nullable();        // payment_no | entry_no
            $table->decimal('amount', 15, 4)->nullable();
            $table->json('ack_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'branch_id'], 'edge_sf_ingest_branch_idx');
            $table->index('status', 'edge_sf_ingest_status_idx');
            $table->index('supplier_payment_id', 'edge_sf_ingest_payment_idx');
            $table->index('journal_entry_id', 'edge_sf_ingest_journal_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('edge_inbound_supplier_finance_ingestions');
    }
};
