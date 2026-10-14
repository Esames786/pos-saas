<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 (Phase 1) — tenant ki website ki settings, delivery areas aur website price.
 *
 * - `web_settings`: aik row. Website on/off, waqt, ETA, auto-cancel, WhatsApp ke toggles.
 * - `web_order_routes`: har branch par "kaun sa order type kis terminal par" (ghanti + draft wahan).
 * - `delivery_zones`: master `geo_areas` ka jo area ye restaurant serve karta hai, kis branch se,
 *   kitni fee. Area ka naam yahan bhi likha jata hai taake tenant ki DB khud mukammal rahe.
 * - `products.website_price`, `combos.website_price`: website ki apni qeemat (malik: "website ke
 *   rate alag rakhna"). Khaali = POS ki qeemat. POS ki qeemat kabhi nahi chhuti.
 *
 * Kuch bhi kisi tenant par khud on nahi hota: `web_settings.is_enabled` default false, aur module
 * plan se milta hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable('web_settings')) {
            $schema->create('web_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('is_enabled')->default(false);
                // 'area' = har area apni branch se; 'single' = poori website aik branch se.
                $table->string('branch_mode', 20)->default('area');
                $table->foreignId('default_branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->unsignedSmallInteger('default_eta_minutes')->default(45);
                $table->unsignedSmallInteger('pickup_eta_minutes')->default(25);
                // {"mon":{"open":"12:00","close":"04:00","closed":false}, ...}
                $table->json('hours')->nullable();
                $table->string('closed_message', 255)->nullable();
                // null = auto-cancel band (malik: aksar order taker khud call kar ke Held karta hai).
                $table->unsignedSmallInteger('auto_cancel_minutes')->nullable();
                // WhatsApp ikhtiyari service hai — client har status ka message khud on/off kare.
                $table->boolean('wa_notify_received')->default(true);
                $table->boolean('wa_notify_confirmed')->default(true);
                $table->boolean('wa_notify_cancelled')->default(true);
                $table->string('contact_phone', 40)->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('web_order_routes')) {
            $schema->create('web_order_routes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                // POS ke order types me se: Delivery aur Pick-Up (= takeaway).
                $table->string('order_type', 20);
                // Pick-Up par null = "Delivery wala hi".
                $table->foreignId('terminal_id')->nullable()->constrained('terminals')->nullOnDelete();
                $table->foreignId('backup_terminal_id')->nullable()->constrained('terminals')->nullOnDelete();
                $table->timestamps();

                $table->unique(['branch_id', 'order_type']);
            });
        }

        if (! $schema->hasTable('delivery_zones')) {
            $schema->create('delivery_zones', function (Blueprint $table) {
                $table->id();
                // master `geo_areas.id` — doosri DB, is liye foreign key nahi.
                $table->unsignedBigInteger('geo_area_id');
                $table->string('area_name', 150);
                $table->string('city', 80);
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->decimal('fee', 12, 2)->default(0);
                $table->decimal('min_order', 12, 2)->nullable();
                $table->decimal('free_delivery_above', 12, 2)->nullable();
                $table->unsignedSmallInteger('eta_minutes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                // Aik area aik hi branch se (area → branch).
                $table->unique('geo_area_id');
                $table->index(['branch_id', 'is_active']);
            });
        }

        if (! $schema->hasColumn('products', 'website_price')) {
            $schema->table('products', function (Blueprint $table) {
                $table->decimal('website_price', 14, 2)->nullable()->after('default_selling_price');
            });
        }

        if (! $schema->hasColumn('combos', 'website_price')) {
            $schema->table('combos', function (Blueprint $table) {
                $table->decimal('website_price', 14, 2)->nullable()->after('price');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');

        if ($schema->hasColumn('combos', 'website_price')) {
            $schema->table('combos', fn (Blueprint $table) => $table->dropColumn('website_price'));
        }
        if ($schema->hasColumn('products', 'website_price')) {
            $schema->table('products', fn (Blueprint $table) => $table->dropColumn('website_price'));
        }
        $schema->dropIfExists('delivery_zones');
        $schema->dropIfExists('web_order_routes');
        $schema->dropIfExists('web_settings');
    }
};
