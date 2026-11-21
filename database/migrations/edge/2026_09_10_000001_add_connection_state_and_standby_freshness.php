<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE EDGE — Q: connection state machine + warm-standby freshness (appliance side).
 *
 * The P lease stays the ONLY authority (edge_local_meta.authority_state). Q adds the persisted, audited
 * connection state derived from it, the heartbeat health counters, what the Cloud last advertised (config revision,
 * official-stock watermark), when the standby last caught up, the reconciliation/handback bookkeeping, the
 * single-instance state of the supervised authority worker, and the freshness stamp on accepted baselines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('edge_local_meta', function (Blueprint $table) {
            $table->string('connection_state', 32)->default('online');
            $table->timestamp('connection_state_since')->nullable();
            $table->string('connection_state_reason', 255)->nullable();
            $table->unsignedInteger('heartbeat_consecutive_failures')->default(0);
            $table->unsignedInteger('heartbeat_consecutive_acks')->default(0);
            $table->string('authority_cloud_holder_seen', 20)->nullable();      // holder the Cloud reported at the last ack
            $table->unsignedBigInteger('standby_config_revision_seen')->nullable(); // Cloud config revision at the last ack
            $table->string('standby_stock_watermark_seen', 128)->nullable();     // Cloud official-stock watermark at the last ack
            $table->timestamp('standby_stock_as_of_seen')->nullable();
            $table->timestamp('standby_config_refreshed_at')->nullable();
            $table->timestamp('standby_stock_refreshed_at')->nullable();
            $table->timestamp('reconcile_clean_at')->nullable();                 // last clean reconciliation since the connection was restored
            $table->string('handback_blocked_reason', 500)->nullable();
            $table->text('authority_takeover_freshness')->nullable();            // JSON: the provable freshness at takeover
        });

        Schema::connection('tenant')->table('edge_operational_stock_baselines', function (Blueprint $table) {
            $table->string('stock_watermark', 128)->nullable()->after('content_hash'); // Cloud official-stock position this baseline equals
            $table->timestamp('cloud_as_of')->nullable()->after('stock_watermark');
            $table->string('freshness_kind', 20)->default('cutover')->after('cloud_as_of'); // initial | cutover | standby_refresh
        });

        Schema::connection('tenant')->create('edge_local_connection_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('from_state', 32);
            $table->string('to_state', 32);
            $table->string('authority_state', 20);
            $table->string('reason', 500)->nullable();
            $table->timestamp('occurred_at');
            $table->index(['occurred_at'], 'elct_occurred_idx');
        });

        Schema::connection('tenant')->create('edge_local_authority_worker_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('singleton_guard')->default(1)->unique();
            $table->string('state', 20)->default('stopped');          // stopped | running
            $table->string('worker_uuid', 64)->nullable();
            $table->string('runtime_version', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('stop_requested_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('last_tick_at')->nullable();
            $table->string('last_tick_outcome', 191)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('edge_local_authority_worker_state');
        Schema::connection('tenant')->dropIfExists('edge_local_connection_transitions');
        Schema::connection('tenant')->table('edge_operational_stock_baselines', function (Blueprint $table) {
            $table->dropColumn(['stock_watermark', 'cloud_as_of', 'freshness_kind']);
        });
        Schema::connection('tenant')->table('edge_local_meta', function (Blueprint $table) {
            $table->dropColumn([
                'connection_state', 'connection_state_since', 'connection_state_reason',
                'heartbeat_consecutive_failures', 'heartbeat_consecutive_acks', 'authority_cloud_holder_seen',
                'standby_config_revision_seen', 'standby_stock_watermark_seen', 'standby_stock_as_of_seen',
                'standby_config_refreshed_at', 'standby_stock_refreshed_at', 'reconcile_clean_at',
                'handback_blocked_reason', 'authority_takeover_freshness',
            ]);
        });
    }
};
