<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-EVENT-HISTORY-1 — every change to a booking, remembered.
 *
 * Append-only by convention and by code: rows are written on every meaningful
 * save (create, header update, draft-lines save, finalize, revert) and are
 * never updated or deleted. A revert does not rewrite history — it APPLIES an
 * old snapshot through the normal pipelines and then writes its own row.
 *
 * The snapshot is the WHOLE operational state of the booking at that moment
 * (header + lines + rates + per-material supply splits + instructions +
 * charges). Money (advances/refunds/invoices) is deliberately NOT in here:
 * it already has an immutable ledger of its own and is never revertable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('catering_event_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catering_event_id')->constrained('catering_events')->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->nullable();
            $table->string('action', 40);
            $table->text('change_summary')->nullable();
            $table->json('snapshot');
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['catering_event_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('catering_event_revisions');
    }
};
