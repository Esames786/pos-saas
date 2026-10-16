<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 — area ke andar ki jagahen (societies, mohalle) aik asal table me (malik, 16 Oct: "location
 * link to area proper table me"). Pehle `geo_areas.aliases` json me thin — na dhoondh sakte, na badal sakte.
 *
 * Ye PLATFORM ki list hai (har restaurant ke kaam ki): OpenStreetMap se mili (`online-ordering:map-places`) aur
 * hamari chuni hui. Restaurant apni jagahen / chhupana / doosre area me dalna `delivery_zone_places` me karta hai.
 *   kind  sure   = naam pate me mile to "You are in <area>"
 *         nearby = sirf nuqta (pados ki gawahi) — qareebi area ke andaze me ginta, naam se pakka nahi
 *   name_key = AreaLocator::norm (aik shehar me aik naam aik hi area ka)
 *
 * Purani aliases json se data yahan aata hai; column abhi rehta hai (wapsi ke liye), code ab is table se parhta hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('master');
        if (! $s->hasTable('geo_area_places')) {
            $s->create('geo_area_places', function (Blueprint $t) {
                $t->id();
                $t->foreignId('geo_area_id')->constrained('geo_areas')->cascadeOnDelete();
                $t->string('city', 100);
                $t->string('name', 190);
                $t->string('name_key', 190);
                $t->decimal('lat', 10, 7)->nullable();
                $t->decimal('lng', 10, 7)->nullable();
                $t->string('source', 20)->default('osm');     // osm | platform
                $t->string('kind', 10)->default('sure');      // sure | nearby
                $t->string('osm_ref', 40)->nullable();        // node/123, way/456
                $t->string('status', 10)->default('active');  // active | hidden
                $t->timestamps();
                $t->unique(['city', 'name_key']);
                $t->index(['geo_area_id', 'status']);
                $t->index(['lat', 'lng']);
            });
        }

        if (! $s->hasColumn('geo_areas', 'aliases')) {
            return;
        }
        // aliases json → rows (sirf jo pehle se nahi).
        $norm = fn (string $n): string => \App\Services\OnlineOrdering\AreaLocator::norm($n);
        foreach (DB::connection('master')->table('geo_areas')->whereNotNull('aliases')->get(['id', 'city', 'aliases']) as $g) {
            foreach ((array) json_decode((string) $g->aliases, true) as $a) {
                $name = trim((string) ($a['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                DB::connection('master')->table('geo_area_places')->insertOrIgnore([
                    'geo_area_id' => $g->id, 'city' => $g->city, 'name' => $name, 'name_key' => $norm($name),
                    'lat' => $a['lat'] ?? null, 'lng' => $a['lng'] ?? null,
                    'source' => ($a['src'] ?? '') === 'osm' ? 'osm' : 'platform',
                    'kind' => ! empty($a['point_only']) ? 'nearby' : 'sure',
                    'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('geo_area_places');
    }
};
