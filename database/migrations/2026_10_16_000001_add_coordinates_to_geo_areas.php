<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 — har master area ka markazi nuqta (OpenStreetMap se, `online-ordering:geocode-areas`).
 *
 * Customer "Use my current location" dabaye to OSM jagah ka naam deta hai, magar aksar wo naam hamari list
 * wala nahi hota (Scheme 33 ke andar ka point OSM par "Usmani Town, Gulshan-e-Iqbal Town" aata hai). Naam na
 * mile to sab se qareebi area — us ke liye ye nuqta. Khaali ho to bas naam se milta hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('master')->hasColumn('geo_areas', 'lat')) {
            Schema::connection('master')->table('geo_areas', function (Blueprint $table) {
                $table->decimal('lat', 10, 7)->nullable()->after('name');
                $table->decimal('lng', 10, 7)->nullable()->after('lat');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('master')->hasColumn('geo_areas', 'lat')) {
            Schema::connection('master')->table('geo_areas', function (Blueprint $table) {
                $table->dropColumn(['lat', 'lng']);
            });
        }
    }
};
