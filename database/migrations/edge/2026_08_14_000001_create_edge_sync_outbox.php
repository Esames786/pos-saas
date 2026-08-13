<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE-SYNC-ENGINE-1B — EDGE-ONLY migration (database/migrations/edge; never a Cloud tenant DB).
 *
 * The append-only sale sync outbox (docs/design/OFFLINE_SYNC_ENGINE_V1.md §6). One row per PAID
 * offline sale, written IN THE SAME TRANSACTION that finalizes the sale, carrying the IMMUTABLE
 * canonical envelope + content hash and the config_revision / activation_epoch frozen at sale time.
 * Only delivery-state metadata (state/lease/attempts/ack fields) ever changes after insert; the
 * envelope columns never do (model guard + tests). Acknowledged rows are RETAINED — durable audit,
 * never delete-on-ack. The Cloud-side ingestion registry is a SEPARATE 1C concern; this table never
 * ships to Cloud tenant migrations.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection($this->connection)->create('edge_sync_outbox', function (Blueprint $table) {
            // id IS the monotonic local sequence (append-only InnoDB auto-increment).
            $table->id();

            // Immutable envelope identity + frozen context (never mutated after insert).
            $table->char('sale_uuid', 26)->unique();
            $table->string('envelope_schema_version', 64);
            $table->unsignedBigInteger('config_revision');    // revision that governed THIS sale
            $table->unsignedBigInteger('activation_epoch');   // appliance generation at sale time
            $table->longText('envelope');                     // canonical JSON, immutable
            $table->char('content_hash', 64);                 // sha256 over the canonical envelope (minus this field)

            // Delivery-state metadata (the ONLY mutable part).
            // pending -> leased -> acknowledged; leased -> pending (retryable release/expiry);
            // leased -> failed_permanent (explicit terminal Cloud verdict, 1C/1D).
            $table->string('state', 20)->default('pending');
            $table->string('lease_owner', 96)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('first_sent_at')->nullable();   // first lease-for-sending
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('ack_ingestion_uuid', 64)->nullable(); // Cloud ingestion identity (1D verified ACK)
            $table->json('ack_payload')->nullable();              // durable ACK audit

            $table->timestamps();

            $table->index(['state', 'id'], 'edge_sync_outbox_state_sequence_index'); // lease scan order
            $table->index('lease_owner', 'edge_sync_outbox_lease_owner_index');      // claim readback
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('edge_sync_outbox');
    }
};
