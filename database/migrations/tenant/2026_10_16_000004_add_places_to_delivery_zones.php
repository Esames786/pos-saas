<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 — "Places inside this area" (Delivery Areas screen): restaurant khud likhe ke kaun si
 * societies is area me hain (Scheme 33 → Chappal Sun City, New Rizvia Society…). "Use my current location"
 * par OSM ka pata in naamon se milta hai, aur har jagah ka apna nuqta qareebi area me gina jata hai.
 *
 * Shakl: [{"name": "Chappal Sun City", "lat": 24.95, "lng": 67.14}, …] — nuqta save par OSM se.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('delivery_zones', 'places')) {
            Schema::connection('tenant')->table('delivery_zones', function (Blueprint $table) {
                $table->json('places')->nullable()->after('eta_minutes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('delivery_zones', 'places')) {
            Schema::connection('tenant')->table('delivery_zones', function (Blueprint $table) {
                $table->dropColumn('places');
            });
        }
    }
};
