<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE-SYNC-ENGINE-1C — the durable Cloud ingestion registry (tenant DB).
 *
 * ONE row per Edge paid-sale envelope the Cloud has decided on, keyed by the canonical sale_uuid. It is
 * the replay/conflict authority: the first ACCEPTED truth for a sale_uuid is never overwritten. A repeat
 * of the SAME immutable content returns the stored result with zero further effects; the SAME sale_uuid
 * with DIFFERENT content is a hard conflict. It also carries enough evidence (device/branch/epoch/config
 * revision + the Cloud sales_order id + official sale number + ACK payload) to prove and audit ingestion.
 *
 * Cloud-only: this is authoritative Cloud posting state, NOT an appliance table (the Edge outbox lives in
 * database/migrations/edge). It ships to every Cloud tenant via the normal tenant migration path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('edge_inbound_sale_ingestions', function (Blueprint $table) {
            $table->id();

            // Canonical cross-system sale identity — the idempotency key.
            $table->char('sale_uuid', 26)->unique();
            $table->char('content_hash', 64);                 // sha256 over the immutable envelope
            $table->string('envelope_schema_version', 64);

            // Binding evidence (never trusted from request; validated before any mutation).
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('device_public_uuid', 64);
            $table->unsignedBigInteger('activation_epoch');
            $table->unsignedBigInteger('config_revision')->nullable();

            // Cloud ingestion identity + the authoritative posting result.
            $table->char('ingestion_uuid', 26)->unique();     // Cloud-minted, returned in the ACK
            $table->string('status', 24);                     // applied | conflict | refused | exception
            $table->string('failure_code', 64)->nullable();   // e.g. ENVELOPE_CONFLICT, INSUFFICIENT_STOCK
            $table->unsignedBigInteger('ingested_sales_order_id')->nullable();
            $table->string('official_sale_no', 64)->nullable();
            $table->json('ack_payload')->nullable();          // durable ACK returned to the appliance
            $table->text('last_error')->nullable();

            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id'], 'edge_ingest_branch_index');
            $table->index('status', 'edge_ingest_status_index');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('edge_inbound_sale_ingestions');
    }
};
