<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KITCHEN-SHEET-A5-1 — tqreeb par "dispatch" ka waqt.
 *
 * Ab tak sirf SERVICE TIME tha — wo waqt jab mehmaan khana khate hain. Magar
 * bawarchi-khane ka asal sawal doosra hai: khana yahan se NIKLEGA kab. Purane
 * software ke parche par wo waqt tha; hamare par nahi, is liye wo haath se
 * likha jata raha (malik ki bheji tasveer par "dispatch date/time" laal qalam
 * se likha hua hai).
 *
 * Nullable — purani 54 bookings ka koi dispatch waqt hai hi nahi, aur unhe
 * koi farzi waqt dena un ke barey me ek jhooti baat likh dena hota. Jahan
 * khali hai, kaghaz us satar ko chhapta hi nahi.
 *
 * Additive: sirf ek nullable column. Kisi mojooda row ko haath nahi lagta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('catering_events', 'dispatch_time')) {
            return;
        }

        Schema::connection('tenant')->table('catering_events', function (Blueprint $table) {
            // service_time ke theek baad — dono ek hi cheez ke do waqt hain.
            $table->time('dispatch_time')->nullable()->after('service_time');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasColumn('catering_events', 'dispatch_time')) {
            return;
        }

        Schema::connection('tenant')->table('catering_events', function (Blueprint $table) {
            $table->dropColumn('dispatch_time');
        });
    }
};
