<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 — delivery areas ki MASTER list (sab tenants ki aik).
 *
 * Yahan sirf naam aur jagah hai: kaun sa area kis branch se, kitni fee, kitni der — ye har tenant ka
 * apna faisla hai aur tenant ki `delivery_zones` me rehta hai. Isi liye aik area ka naam do dafa aaye
 * (purani list me "FB Area Block 7" 200 aur 250 dono par tha) to yahan aik hi row banti hai; fee ka
 * sawal tenant ka hai.
 *
 * Pehli bharai: Karachi ke 74 areas, purani ordering website ki area list se. Seeder ke bajaye
 * migration me, kyunke deploy seeder nahi chalata — aur ye list har mahaul (prod, test, naya
 * machine) me honi chahiye. Row pehle se ho to chhui nahi jati.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('master')->hasTable('geo_areas')) {
            Schema::connection('master')->create('geo_areas', function (Blueprint $table) {
                $table->id();
                $table->string('country', 80);
                $table->string('state', 80)->nullable();
                $table->string('city', 80);
                $table->string('name', 150);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['country', 'city', 'name']);
                $table->index(['city', 'is_active']);
            });
        }

        $now = now();
        foreach ($this->karachi() as $i => $name) {
            DB::connection('master')->table('geo_areas')->insertOrIgnore([
                'country' => 'Pakistan',
                'state' => 'Sindh',
                'city' => 'Karachi',
                'name' => $name,
                'sort_order' => ($i + 1) * 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('geo_areas');
    }

    /** @return list<string> */
    private function karachi(): array
    {
        return [
            'Bahadurabad',
            'Clifton Block 1',
            'Clifton Block 2',
            'Clifton Block 3',
            'Clifton Block 4',
            'Clifton Block 5',
            'Clifton Block 6',
            'Clifton Block 7',
            'Clifton Block 8',
            'Clifton Block 9',
            'DHA Phase 1',
            'DHA Phase 2',
            'DHA Phase 2 Extension',
            'DHA Phase 3',
            'DHA Phase 4',
            'DHA Phase 5',
            'DHA Phase 6',
            'DHA Phase 7',
            'DHA Phase 7 Extension',
            'DHA Phase 8',
            'DHA Phase 8 Extension',
            'FB Area Block 2',
            'FB Area Block 4',
            'FB Area Block 5',
            'FB Area Block 6',
            'FB Area Block 7',
            'FB Area Block 8',
            'FB Area Block 9',
            'FB Area Block 12',
            'FB Area Block 13',
            'FB Area Block 14',
            'FB Area Block 15',
            'FB Area Block 16',
            'FB Area Block 17',
            'FB Area Block 21',
            'FC Area',
            'Gulistan e Johar - Block 4',
            'Gulistan e Johar - Block 5',
            'Gulistan e Johar - Block 6',
            'Gulistan e Johar - Block 7',
            'Gulistan e Johar - Block 8',
            'Gulistan e Johar - Block 9',
            'Gulistan e Johar - Block 10',
            'Gulistan e Johar - Block 11',
            'Gulistan e Johar - Block 12',
            'Gulistan e Johar - Block 13',
            'Gulistan e Johar - Block 14',
            'Gulistan e Johar - Block 15',
            'Gulistan e Johar - Block 16',
            'Gulistan e Johar - Block 16 A',
            'Gulistan e Johar - Block 17',
            'Gulistan e Johar - Block 18',
            'Gulistan e Johar - Block 19',
            'Gulistan e Johar - Block 20',
            'Gulshan e Iqbal - Block 1',
            'Gulshan e Iqbal - Block 2',
            'Gulshan e Iqbal - Block 3',
            'Gulshan e Iqbal - Block 5',
            'Gulshan e Iqbal - Block 6',
            'Gulshan e Iqbal - Block 8',
            'Gulshan e Iqbal - Block 9',
            'Gulshan e Iqbal - Block 13 D',
            'Gulshan e Iqbal - Block 13 D1',
            'Jahangir Road',
            'Jamshed Road',
            'Korangi Industrial Area',
            'Liaquatabad',
            'Main Shahrah e Faisal',
            'Malir Cantt',
            'Model Colony',
            'PIB Colony',
            'Saddar',
            'Scheme 33',
            'Shah Faisal Colony',
        ];
    }
};
