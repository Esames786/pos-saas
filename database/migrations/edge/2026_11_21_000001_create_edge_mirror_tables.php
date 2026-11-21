<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 — SHIFT / ORDER CONTINUITY, appliance side: the LIVE-STATE MIRROR store (contract K1, stream A).
 *
 * While the Cloud is the writer the appliance polls `GET /api/edge/live-state` and keeps a READ-ONLY copy of the branch's
 * open shifts (per terminal), open dine-in table sessions with their unpaid orders, and held bills. Every row carries the
 * Cloud `revision` it came from and `mirrored_at` (appliance clock). A package REPLACES the whole set atomically
 * (replace-by-revision, LiveStateMirror::apply) — nothing here is ever a financial fact: rows become Edge drafts only through
 * ContinuityAdoptionService after a supervised takeover, recorded once per segment in `edge_mirror_adoptions`.
 *
 * EDGE-ONLY (database/migrations/edge; never a Cloud tenant DB). Additive, idempotent (hasTable guards) — safe for the
 * forward-only appliance schema upgrader.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);

        if (! $s->hasTable('edge_mirror_meta')) {
            $s->create('edge_mirror_meta', function (Blueprint $table) {
                $table->id();
                $table->unsignedTinyInteger('singleton_guard')->default(1)->unique();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('revision', 128)->nullable();          // the Cloud revision of the applied package
                $table->timestamp('generated_at')->nullable();        // Cloud clock (package generated_at)
                $table->timestamp('mirrored_at')->nullable();         // appliance clock: last package applied OR confirmed unchanged
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->string('last_error', 500)->nullable();
                $table->unsignedInteger('shifts_count')->default(0);
                $table->unsignedInteger('table_sessions_count')->default(0);
                $table->unsignedInteger('held_sales_count')->default(0);
                $table->string('adopted_segment', 40)->nullable();    // the local-authority period already decided (fast path)
                $table->timestamp('adopted_at')->nullable();
                $table->string('adoption_status', 30)->nullable();    // adopted | refused_stale | refused_untrusted | nothing_mirrored
                $table->string('adoption_reason', 500)->nullable();   // the cashier-facing reason when adoption was refused
                $table->timestamps();
            });
        }

        if (! $s->hasTable('edge_mirror_shifts')) {
            $s->create('edge_mirror_shifts', function (Blueprint $table) {
                $table->id();
                $table->char('shift_uuid', 26)->unique();
                $table->unsignedBigInteger('terminal_id')->index();
                $table->string('status', 20)->default('open');
                $table->date('business_date')->nullable();            // K1 optional (requested from stream C)
                $table->timestamp('opened_at')->nullable();
                $table->unsignedBigInteger('opened_by_user_id')->nullable();
                $table->decimal('opening_cash', 14, 2)->default(0);
                $table->decimal('cash_sales', 14, 2)->default(0);
                $table->decimal('card_sales', 14, 2)->default(0);
                $table->decimal('refunds', 14, 2)->default(0);
                $table->decimal('cash_in', 14, 2)->default(0);
                $table->decimal('cash_out', 14, 2)->default(0);
                $table->decimal('expected_cash', 14, 2)->default(0);
                $table->string('revision', 128);
                $table->timestamp('mirrored_at');
                $table->timestamps();
            });
        }

        if (! $s->hasTable('edge_mirror_table_sessions')) {
            $s->create('edge_mirror_table_sessions', function (Blueprint $table) {
                $table->id();
                $table->char('session_uuid', 26)->unique();
                $table->unsignedBigInteger('table_id')->index();
                $table->string('session_no', 100)->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->unsignedInteger('covers')->nullable();
                $table->unsignedBigInteger('waiter_user_id')->nullable();
                $table->string('revision', 128);
                $table->timestamp('mirrored_at');
                $table->timestamps();
            });
        }

        if (! $s->hasTable('edge_mirror_table_orders')) {
            $s->create('edge_mirror_table_orders', function (Blueprint $table) {
                $table->id();
                $table->char('order_uuid', 26)->unique();
                $table->char('session_uuid', 26)->index();
                $table->string('order_no', 100)->nullable();
                $table->string('status', 30)->default('open');
                $table->char('shift_uuid', 26)->nullable();
                $table->unsignedBigInteger('terminal_id')->nullable();
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('discount', 14, 2)->default(0);
                $table->decimal('tax', 14, 2)->default(0);
                $table->decimal('service_charge', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->decimal('paid_total', 14, 2)->default(0);     // Σ mirrored payments (an order paid in part online is not adoptable)
                $table->json('payments')->nullable();
                $table->boolean('adoptable')->default(true);
                $table->string('not_adoptable_reason', 100)->nullable();
                $table->char('fingerprint', 64);                      // ContinuityFingerprint of the mirrored order
                $table->string('revision', 128);
                $table->timestamp('mirrored_at');
                $table->timestamps();
            });
        }

        if (! $s->hasTable('edge_mirror_held_sales')) {
            $s->create('edge_mirror_held_sales', function (Blueprint $table) {
                $table->id();
                $table->char('order_uuid', 26)->unique();
                $table->string('order_no', 100)->nullable();
                $table->string('order_type', 30)->nullable();
                $table->timestamp('held_at')->nullable();
                $table->unsignedBigInteger('terminal_id')->nullable()->index();
                $table->char('shift_uuid', 26)->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('discount', 14, 2)->default(0);
                $table->decimal('tax', 14, 2)->default(0);
                $table->decimal('service_charge', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->decimal('paid_total', 14, 2)->default(0);
                $table->boolean('adoptable')->default(true);
                $table->string('not_adoptable_reason', 100)->nullable();
                $table->char('fingerprint', 64);
                $table->string('revision', 128);
                $table->timestamp('mirrored_at');
                $table->timestamps();
            });
        }

        if (! $s->hasTable('edge_mirror_order_lines')) {
            $s->create('edge_mirror_order_lines', function (Blueprint $table) {
                $table->id();
                $table->char('order_uuid', 26)->index();
                $table->string('source', 10);                         // table | held
                $table->char('line_uuid', 26);
                $table->unsignedInteger('position')->default(0);
                $table->string('line_kind', 20)->default('standard');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variant_id')->nullable();
                $table->string('product_name', 255)->nullable();
                $table->decimal('qty', 14, 3);
                $table->decimal('unit_price', 14, 2);
                $table->decimal('discount_amount', 14, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->text('notes')->nullable();
                $table->json('modifiers')->nullable();
                $table->string('kot_state', 20)->nullable();          // fired | pending | … (Cloud KOT state)
                $table->string('kot_batch_uuid', 36)->nullable();
                $table->string('revision', 128);
                $table->timestamps();
                $table->unique(['order_uuid', 'line_uuid'], 'edge_mirror_order_lines_order_line_unique');
            });
        }

        if (! $s->hasTable('edge_mirror_adoptions')) {
            $s->create('edge_mirror_adoptions', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 20);                           // shift | held_sale | table_session | table_order
                $table->char('source_uuid', 26);                      // the Cloud identity adopted
                $table->string('segment', 40);                        // the local-authority period (authority_takeover_at)
                $table->string('status', 20)->default('adopted');     // adopted | skipped | released
                $table->string('reason', 255)->nullable();
                $table->unsignedBigInteger('terminal_id')->nullable();
                $table->unsignedBigInteger('local_id')->nullable();   // the Edge row created (shift / sale / session id)
                $table->unsignedBigInteger('user_id')->nullable();    // the operator whose touch triggered the adoption
                $table->string('mirror_revision', 128)->nullable();
                $table->timestamp('mirrored_at')->nullable();
                $table->char('fingerprint', 64)->nullable();
                $table->timestamp('adopted_at');
                $table->timestamps();
                $table->unique(['kind', 'source_uuid', 'segment'], 'edge_mirror_adoptions_kind_source_segment_unique');
            });
        }
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        foreach (['edge_mirror_adoptions', 'edge_mirror_order_lines', 'edge_mirror_held_sales', 'edge_mirror_table_orders', 'edge_mirror_table_sessions', 'edge_mirror_shifts', 'edge_mirror_meta'] as $t) {
            $s->dropIfExists($t);
        }
    }
};
