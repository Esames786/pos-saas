<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE EDGE — F1 SALES RETURNS: the Cloud's exactly-once registry for Edge-originated RETURN events.
 *
 * ONE row per return envelope the Cloud has decided on, keyed by the canonical return_uuid. Same contract as the sale
 * registry: the first ACCEPTED truth for a return_uuid is never overwritten; the same immutable content returns the
 * stored result with zero further effects; the same return_uuid with different content is a hard conflict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('edge_inbound_return_ingestions', function (Blueprint $table) {
            $table->id();
            $table->char('return_uuid', 26)->unique();
            $table->char('content_hash', 64);
            $table->string('envelope_schema_version', 64);
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('device_public_uuid', 64);
            $table->unsignedBigInteger('activation_epoch');
            $table->unsignedBigInteger('config_revision')->nullable();
            $table->char('ingestion_uuid', 26)->unique();
            $table->string('status', 24);                       // applied | conflict | refused | exception
            $table->string('failure_code', 64)->nullable();
            $table->unsignedBigInteger('sales_order_id')->nullable();      // the ORIGINAL Cloud sale the return reverses
            $table->unsignedBigInteger('sales_return_id')->nullable();     // the official return document
            $table->string('official_return_no', 64)->nullable();
            $table->json('approval_audit')->nullable();         // who approved on the appliance (immutable copy)
            $table->json('ack_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'branch_id'], 'edge_ret_ingest_branch_idx');
            $table->index('status', 'edge_ret_ingest_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('edge_inbound_return_ingestions');
    }
};
