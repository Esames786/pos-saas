<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLACE-LEARNING-1 — the website's delivery areas grow more accurate with every delivered order (owner, 2 Nov:
 * "suggested na ae, area mature hote rahen jese jese order barhte rahen").
 *
 *   delivery_place_sightings  one row per processed PAID website delivery order: the place name OpenStreetMap gives
 *                             for its pin, the area the restaurant delivered it to, and the pin — or why nothing was
 *                             learned (`skipped`: no pin, rough GPS, no usable name …), so an order is looked at once.
 *                             This is the evidence; the learned place (delivery_zone_places, source `learned`) is
 *                             recomputed from it — majority area, median point, order count.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('tenant');
        if ($s->hasTable('delivery_place_sightings')) {
            return;
        }
        $s->create('delivery_place_sightings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_order_id')->unique()->constrained('sales_orders')->cascadeOnDelete();
            $t->foreignId('delivery_zone_id')->nullable()->constrained('delivery_zones')->nullOnDelete();
            $t->string('name', 190)->nullable();
            $t->string('name_key', 190)->nullable()->index();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->unsignedInteger('accuracy_m')->nullable();   // phone GPS ±m; empty = a pin the customer placed on the map
            $t->string('skipped', 20)->nullable();           // why nothing was learned (null = learned)
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('delivery_place_sightings');
    }
};
