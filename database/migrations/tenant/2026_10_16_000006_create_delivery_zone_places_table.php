<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 — RESTAURANT ki apni jagahen har delivery area ke saath (Online Ordering → Delivery Places):
 *   source manual  = admin ne likhi
 *          learned = customers ke Paid orders se seekhi (aage: aik jagah se 3+ orders, aik hi area)
 *          override = platform (geo_area_places) ki jagah is restaurant ke liye doosre area me / chhupi
 *   status active | hidden | suggested (seekhi hui, abhi lagi nahi)
 *   orders_count / last_order_at — seekhne ka saboot.
 * Aik restaurant me aik naam aik hi area ka (unique name_key). Purani delivery_zones.places json se data yahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('tenant');
        if (! $s->hasTable('delivery_zone_places')) {
            $s->create('delivery_zone_places', function (Blueprint $t) {
                $t->id();
                $t->foreignId('delivery_zone_id')->constrained('delivery_zones')->cascadeOnDelete();
                $t->unsignedBigInteger('geo_area_place_id')->nullable();   // platform jagah (override ke liye)
                $t->string('name', 190);
                $t->string('name_key', 190);
                $t->decimal('lat', 10, 7)->nullable();
                $t->decimal('lng', 10, 7)->nullable();
                $t->string('source', 10)->default('manual');   // manual | learned | override
                $t->string('kind', 10)->default('sure');       // sure | nearby
                $t->string('status', 10)->default('active');   // active | hidden | suggested
                $t->unsignedInteger('orders_count')->default(0);
                $t->timestamp('last_order_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique('name_key');
                $t->index(['delivery_zone_id', 'status']);
                $t->index('geo_area_place_id');
                $t->index(['lat', 'lng']);
            });
        }

        if (! $s->hasColumn('delivery_zones', 'places')) {
            return;
        }
        $norm = fn (string $n): string => \App\Services\OnlineOrdering\AreaLocator::norm($n);
        foreach (DB::connection('tenant')->table('delivery_zones')->whereNotNull('places')->get(['id', 'places']) as $z) {
            foreach ((array) json_decode((string) $z->places, true) as $p) {
                $name = trim((string) ($p['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                DB::connection('tenant')->table('delivery_zone_places')->insertOrIgnore([
                    'delivery_zone_id' => $z->id, 'name' => $name, 'name_key' => $norm($name),
                    'lat' => $p['lat'] ?? null, 'lng' => $p['lng'] ?? null, 'source' => 'manual', 'kind' => 'sure',
                    'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('delivery_zone_places');
    }
};
