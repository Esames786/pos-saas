<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 (Phase 4b) — usage invoice ki child rows, aur website orders ka record.
 *
 * Malik (14 Oct): "1 invoice multiple child rows … whatsapp pe b ese … ta k record b rahe ga or group b".
 * Teen tah: invoice (mahine ki aik) → lines (har service ki har din ki aik) → record (us din ke messages
 * `whatsapp_messages` me, us din ke orders `website_order_charges` me).
 *
 * Invoice ka total hamesha SUM(lines) — har raat dobara nikala jata hai, joRa nahi jata. Har builder sirf
 * apni line_type chhuta hai: WhatsApp wala commission ki lines nahi mitata, commission wala WhatsApp ki nahi.
 */
return new class extends Migration
{
    public function up(): void
    {
        $m = Schema::connection('master');

        if (! $m->hasTable('subscription_invoice_lines')) {
            $m->create('subscription_invoice_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('subscription_invoices')->cascadeOnDelete();
                $table->enum('line_type', ['whatsapp_messages', 'website_commission', 'plan', 'manual']);
                $table->date('usage_date')->nullable();
                $table->string('description', 255);
                // Messages ya orders ki ginti.
                $table->decimal('quantity', 12, 2)->default(0);
                // Commission ke liye us din ki net sale (returns minus). WhatsApp par khaali.
                $table->decimal('base_amount', 14, 2)->nullable();
                $table->decimal('rate', 10, 4)->nullable();
                $table->enum('rate_unit', ['per_message', 'percent', 'fixed'])->nullable();
                // 4 decimal par: din ki lines ka jor = mahine ki sale × 1% (paisa-paisa); invoice ka total 2 par.
                $table->decimal('amount', 14, 4)->default(0);
                $table->timestamps();

                $table->unique(['invoice_id', 'line_type', 'usage_date'], 'sil_invoice_type_day_unique');
            });
        }

        if (! $m->hasTable('website_order_charges')) {
            $m->create('website_order_charges', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                // 'sale' = paid website order (us ke business din par); 'return' = us order ka return (return ke din par, minus).
                $table->enum('kind', ['sale', 'return']);
                // Tenant DB ki row: sales_orders.id ya sales_returns.id.
                $table->unsignedBigInteger('source_id');
                $table->string('sale_no', 60);
                $table->date('business_date');
                // grand_total (items + delivery + tax sab); return par minus.
                $table->decimal('net_amount', 14, 2);
                $table->decimal('rate_percent', 6, 3);
                $table->decimal('amount', 14, 4);
                $table->foreignId('invoice_id')->nullable()->constrained('subscription_invoices')->nullOnDelete();
                $table->timestamps();

                $table->unique(['tenant_id', 'kind', 'source_id'], 'woc_tenant_kind_source_unique');
                $table->index(['tenant_id', 'business_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('website_order_charges');
        Schema::connection('master')->dropIfExists('subscription_invoice_lines');
    }
};
