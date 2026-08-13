<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATERING-SLICE-3: operational documents + communication plumbing.
 *
 * - catering_advances: OPERATIONAL advance records for the event balance
 *   display. V1 posts NO GL and NO cash-bank movement (spec §19) — future
 *   finance posting will reuse JournalPostingService via a translator method.
 * - catering_production_releases(+lines): immutable production snapshot —
 *   a separate catering document type, NOT a POS KOT; carries no customer
 *   pricing.
 * - catering_event_reminders: claim-before-send reminder schedule rows
 *   (report_schedule_runs pattern; unique key = the claim).
 * - catering_email_logs: idempotency claims for customer emails.
 * - catering_printer_mappings: independent Catering routing authority
 *   mirroring category_printer_mappings' shape. POS KOT mappings are never
 *   modified by catering code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('catering_advances')) {
            Schema::connection('tenant')->create('catering_advances', function (Blueprint $table) {
                $table->id();
                $table->char('advance_uuid', 26)->nullable()->unique();
                $table->foreignId('catering_event_id')->constrained('catering_events')->cascadeOnDelete();
                $table->decimal('amount', 14, 2);
                $table->date('received_date');
                $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
                $table->string('reference')->nullable();
                $table->string('notes')->nullable();
                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('catering_event_id', 'catering_advances_event_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('catering_production_releases')) {
            Schema::connection('tenant')->create('catering_production_releases', function (Blueprint $table) {
                $table->id();
                $table->char('release_uuid', 26)->nullable()->unique();
                $table->string('release_no', 50)->unique();
                $table->foreignId('catering_event_id')->constrained('catering_events')->cascadeOnDelete();
                $table->foreignId('catering_estimate_id')->nullable()->constrained('catering_estimates')->nullOnDelete();
                // Event header snapshot (venue/time/pax) — the sheet must not drift.
                $table->json('event_snapshot');
                // Consolidated raw-material requirements snapshot (planning, read-only).
                $table->json('requirements_snapshot')->nullable();
                $table->string('status', 20)->default('released'); // released | cancelled
                $table->timestamp('released_at');
                $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['catering_event_id', 'status'], 'catering_releases_event_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('catering_production_release_lines')) {
            Schema::connection('tenant')->create('catering_production_release_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_production_release_id')
                    ->constrained('catering_production_releases', indexName: 'catering_release_lines_release_fk')
                    ->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->string('item_name');
                $table->string('item_name_ur')->nullable();
                $table->decimal('quantity', 12, 3);
                $table->string('unit_code', 50)->nullable();
                $table->string('production_station', 50)->nullable();
                $table->text('instructions')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['catering_production_release_id', 'sort_order'], 'catering_release_lines_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('catering_event_reminders')) {
            Schema::connection('tenant')->create('catering_event_reminders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_event_id')->constrained('catering_events')->cascadeOnDelete();
                $table->string('reminder_key', 20); // d7 | d3 | d1 | same_day
                $table->date('due_date');
                $table->timestamp('sent_at')->nullable();
                $table->string('sent_to')->nullable();
                $table->timestamps();

                // The unique key IS the idempotency claim (insertOrIgnore pattern).
                $table->unique(['catering_event_id', 'reminder_key'], 'catering_event_reminders_unique');
                $table->index(['due_date', 'sent_at'], 'catering_event_reminders_due_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('catering_email_logs')) {
            Schema::connection('tenant')->create('catering_email_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_event_id')->constrained('catering_events')->cascadeOnDelete();
                $table->string('email_type', 40); // booking_confirmed | quotation_sent | ...
                $table->string('dedupe_key', 100); // e.g. estimate version, advance id
                $table->string('recipient');
                $table->timestamp('sent_at')->nullable();
                $table->string('error')->nullable();
                $table->timestamps();

                $table->unique(['catering_event_id', 'email_type', 'dedupe_key'], 'catering_email_logs_unique');
            });
        }

        if (! Schema::connection('tenant')->hasTable('catering_printer_mappings')) {
            Schema::connection('tenant')->create('catering_printer_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
                $table->foreignId('category_id')->nullable()->constrained('categories')->cascadeOnDelete();
                $table->string('production_station', 50)->nullable();
                $table->foreignId('printer_id')->constrained('printers')->cascadeOnDelete();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['branch_id', 'category_id', 'production_station', 'printer_id'], 'catering_printer_mappings_unique');
                $table->index('printer_id', 'catering_printer_mappings_printer_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('catering_printer_mappings');
        Schema::connection('tenant')->dropIfExists('catering_email_logs');
        Schema::connection('tenant')->dropIfExists('catering_event_reminders');
        Schema::connection('tenant')->dropIfExists('catering_production_release_lines');
        Schema::connection('tenant')->dropIfExists('catering_production_releases');
        Schema::connection('tenant')->dropIfExists('catering_advances');
    }
};
