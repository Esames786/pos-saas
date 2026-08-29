<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATERING-SLICE-1: the separate Catering event/estimate domain.
 *
 * Catering quotations are NOT sales_orders and never become sales_orders rows.
 * Events own the operational lifecycle; estimates are versioned commercial
 * documents under an event (unique per version); lines snapshot names/units so
 * a sent estimate stays readable even if the catalog changes later.
 *
 * All three documents carry canonical ULIDs (HasCanonicalIdentity) so future
 * generic Edge sync reconciles by identity, not numeric id. Zero GL / stock /
 * shift / KOT interaction in this slice by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('catering_events')) {
            Schema::connection('tenant')->create('catering_events', function (Blueprint $table) {
                $table->id();
                $table->char('event_uuid', 26)->nullable()->unique();
                $table->string('event_no', 50)->unique();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
                // Snapshots so the document survives later customer edits.
                $table->string('customer_name');
                $table->string('customer_name_ur')->nullable();
                $table->string('customer_phone', 50)->nullable();
                $table->string('customer_email')->nullable();
                $table->text('customer_address')->nullable();
                $table->string('event_type', 50)->nullable(); // wedding, corporate, ...
                $table->date('booking_date');
                $table->date('event_date');
                $table->time('service_time')->nullable();
                $table->string('venue', 255)->nullable();
                $table->unsignedInteger('pax')->default(0);
                // inquiry|draft|quoted|confirmed|production_ready|released|completed|closed|cancelled
                $table->string('status', 30)->default('inquiry');
                $table->text('notes')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['event_date', 'status'], 'catering_events_date_status_idx');
                $table->index(['status', 'booking_date'], 'catering_events_status_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('catering_estimates')) {
            Schema::connection('tenant')->create('catering_estimates', function (Blueprint $table) {
                $table->id();
                $table->char('estimate_uuid', 26)->nullable()->unique();
                $table->foreignId('catering_event_id')->constrained('catering_events')->cascadeOnDelete();
                $table->unsignedInteger('version_no')->default(1);
                // draft|sent|accepted|superseded|cancelled — sent+ is commercially immutable.
                $table->string('status', 20)->default('draft');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('service_charge_amount', 14, 2)->default(0);
                $table->string('other_charge_label')->nullable();
                $table->decimal('other_charge_amount', 14, 2)->default(0);
                $table->string('discount_type', 20)->default('none'); // none|fixed|percent
                $table->decimal('discount_value', 14, 2)->default(0);
                $table->decimal('discount_amount', 14, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('grand_total', 14, 2)->default(0);
                $table->decimal('estimated_material_cost', 14, 2)->nullable();
                $table->text('terms')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('superseded_at')->nullable();
                $table->timestamps();

                $table->unique(['catering_event_id', 'version_no'], 'catering_estimates_event_version_unique');
                $table->index(['status', 'created_at'], 'catering_estimates_status_idx');
            });
        }

        if (! Schema::connection('tenant')->hasTable('catering_estimate_lines')) {
            Schema::connection('tenant')->create('catering_estimate_lines', function (Blueprint $table) {
                $table->id();
                $table->char('line_uuid', 26)->nullable()->unique();
                $table->foreignId('catering_estimate_id')->constrained('catering_estimates')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
                // Name/unit snapshots: the printed document must not drift with the catalog.
                $table->string('item_name');
                $table->string('item_name_ur')->nullable();
                $table->decimal('quantity', 12, 3);
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->string('unit_code', 50)->nullable();
                $table->decimal('rate', 14, 2)->default(0);
                $table->decimal('amount', 14, 2)->default(0);
                $table->text('instructions')->nullable();
                $table->decimal('estimated_unit_cost', 14, 4)->nullable();
                $table->decimal('estimated_cost_total', 14, 2)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['catering_estimate_id', 'sort_order'], 'catering_estimate_lines_idx');
                $table->index('product_id', 'catering_estimate_lines_product_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('catering_estimate_lines');
        Schema::connection('tenant')->dropIfExists('catering_estimates');
        Schema::connection('tenant')->dropIfExists('catering_events');
    }
};
