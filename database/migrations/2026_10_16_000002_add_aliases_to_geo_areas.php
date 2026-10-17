<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 — bara area, us ke andar ki societies ke naam ke saath (`aliases`).
 *
 * UAT (15 Oct): Gulshan-e-Kaneez Fatima Society (Scheme 33) ka customer "Gulistan e Johar - Block 4" par chala
 * gaya. OSM ke pate me "Scheme 33" likha hi nahi hota, aur Scheme 33 ka aik nuqta (Super Highway ki taraf) 7 km
 * door tha jab ke Johar Block 4 ka 3.6 km. Ab har alias ka naam pate se milta hai, aur us ka apna nuqta
 * (`online-ordering:geocode-areas` bharta hai) qareebi area me gina jata hai.
 *
 * Shakl: [{"name": "Gulzar-e-Hijri", "lat": null, "lng": null}, …]. Sirf jahan khaali ho wahan bharta hai.
 */
return new class extends Migration
{
    /** Karachi: purani website ki list me Scheme 33 aik hi area hai — ye uske andar ki societies hain. */
    private const KARACHI = [
        'Scheme 33' => ['Gulshan-e-Kaneez Fatima', 'Gulzar-e-Hijri', 'Saadi Town'],
    ];

    public function up(): void
    {
        if (! Schema::connection('master')->hasColumn('geo_areas', 'aliases')) {
            Schema::connection('master')->table('geo_areas', function (Blueprint $table) {
                $table->json('aliases')->nullable()->after('lng');
            });
        }

        foreach (self::KARACHI as $area => $names) {
            DB::connection('master')->table('geo_areas')
                ->where('city', 'Karachi')->where('name', $area)->whereNull('aliases')
                ->update(['aliases' => json_encode(array_map(fn ($n) => ['name' => $n, 'lat' => null, 'lng' => null], $names))]);
        }
    }

    public function down(): void
    {
        if (Schema::connection('master')->hasColumn('geo_areas', 'aliases')) {
            Schema::connection('master')->table('geo_areas', function (Blueprint $table) {
                $table->dropColumn('aliases');
            });
        }
    }
};
