<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE EDGE — F3: the Cloud registry of Edge-originated purchase-return events (one row per event_uuid: same uuid +
 * same hash → already_applied; different hash → conflict, no mutation). Records the OFFICIAL purchase return the Cloud
 * posted so reconciliation, the projection's applied-event set and lost-ACK recovery read one truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('edge_inbound_purchase_return_ingestions', function (Blueprint $table) {
            $table->id();
            $table->char('event_uuid', 26)->unique('edge_pr_ingest_event_uq');
            $table->char('content_hash', 64);
            $table->string('envelope_schema_version', 64);
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('device_public_uuid', 64);
            $table->unsignedBigInteger('activation_epoch');
            $table->unsignedBigInteger('config_revision')->nullable();
            $table->char('ingestion_uuid', 26)->unique('edge_pr_ingest_ingestion_uq');
            $table->string('status', 24);                       // applied | conflict | refused | exception
            $table->string('failure_code', 64)->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('goods_receipt_id')->nullable();
            $table->unsignedBigInteger('purchase_return_id')->nullable();   // the OFFICIAL purchase return document
            $table->string('official_return_no', 64)->nullable();
            $table->decimal('amount', 15, 4)->nullable();
            $table->json('ack_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'branch_id'], 'edge_pr_ingest_branch_idx');
            $table->index('status', 'edge_pr_ingest_status_idx');
            $table->index('purchase_return_id', 'edge_pr_ingest_return_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('edge_inbound_purchase_return_ingestions');
    }
};
