<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 — UAT (15 Oct): Chappal Sun City (OSM: "Chappal Sun City, New Rizvia Society") Scheme 33 me
 * hai, magar website ne "Gulistan e Johar - Block 7" chuna — Johar 7 ka nuqta 2.1 km, Scheme 33 ka qareebi
 * 2.4 km (dono Safoora ke paas milte hain). Naam hi pakka hai: dono naam Scheme 33 me. Sirf jo naam na hon wo
 * jorta hai; nuqte `online-ordering:geocode-areas` bharta hai.
 */
return new class extends Migration
{
    private const ADD = ['Scheme 33' => ['Chappal Sun City', 'New Rizvia Society']];

    public function up(): void
    {
        if (! Schema::connection('master')->hasColumn('geo_areas', 'aliases')) {
            return;
        }
        foreach (self::ADD as $area => $names) {
            $row = DB::connection('master')->table('geo_areas')->where('city', 'Karachi')->where('name', $area)->first(['id', 'aliases']);
            if (! $row) {
                continue;
            }
            $list = json_decode((string) $row->aliases, true) ?: [];
            $have = array_map(fn ($a) => mb_strtolower((string) ($a['name'] ?? '')), $list);
            foreach ($names as $n) {
                if (! in_array(mb_strtolower($n), $have, true)) {
                    $list[] = ['name' => $n, 'lat' => null, 'lng' => null];
                }
            }
            DB::connection('master')->table('geo_areas')->where('id', $row->id)->update(['aliases' => json_encode($list)]);
        }
    }

    public function down(): void
    {
        // Data only — naam rehne do.
    }
};
