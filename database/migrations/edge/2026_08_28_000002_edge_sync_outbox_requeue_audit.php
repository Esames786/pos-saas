<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE-SYNC-ENGINE-1E — audit fields for the guarded supervisor REQUEUE of a failed_permanent outbox row.
 *
 * A terminal row may be requeued ONLY when its underlying cause is an operational condition that has been
 * explicitly resolved AND reusing the SAME immutable envelope is still valid (§H). The requeue never edits
 * the envelope; it records who requeued, when, why, and how many times, so the append-only outbox keeps a
 * full operator trail. Additive + idempotent (AE12).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('edge_sync_outbox')) {
            return;
        }
        Schema::connection($this->connection)->table('edge_sync_outbox', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('edge_sync_outbox', 'requeue_count')) {
                $table->unsignedInteger('requeue_count')->default(0)->after('attempts');
            }
            if (! Schema::connection($this->connection)->hasColumn('edge_sync_outbox', 'last_requeued_at')) {
                $table->timestamp('last_requeued_at')->nullable()->after('requeue_count');
            }
            if (! Schema::connection($this->connection)->hasColumn('edge_sync_outbox', 'last_requeue_reason')) {
                $table->string('last_requeue_reason', 500)->nullable()->after('last_requeued_at');
            }
            if (! Schema::connection($this->connection)->hasColumn('edge_sync_outbox', 'last_requeued_by')) {
                $table->string('last_requeued_by', 191)->nullable()->after('last_requeue_reason');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('edge_sync_outbox')) {
            return;
        }
        Schema::connection($this->connection)->table('edge_sync_outbox', function (Blueprint $table) {
            foreach (['requeue_count', 'last_requeued_at', 'last_requeue_reason', 'last_requeued_by'] as $col) {
                if (Schema::connection($this->connection)->hasColumn('edge_sync_outbox', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
