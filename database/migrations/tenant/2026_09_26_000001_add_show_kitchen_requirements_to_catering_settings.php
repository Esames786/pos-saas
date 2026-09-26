<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KITCHEN-SHEET-REQUIREMENTS-TOGGLE-1 — "Consolidated Raw Material
 * Requirements" ka switch, aur wo band haalat me aata hai.
 *
 * Client (26 Sep): kitchen sheet par wo planning wali table har baar chhapti
 * hai, aur bawarchi ko us se koi kaam nahi — wo sirf jagah leti hai.
 *
 * DEFAULT FALSE, jaan-boojh kar. Maang yehi thi ("by default off this
 * setting"), aur ek aisa hissa jo koi nahi parhta, band hi hona chahiye — jise
 * chahiye wo ek switch se khol le.
 *
 * Additive: ek nullable-nahi boolean column apni default ke saath. Koi purana
 * data nahi badalta, aur wapas lena sirf column girana hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('catering_settings', 'show_kitchen_requirements')) {
            return;
        }

        Schema::connection('tenant')->table('catering_settings', function (Blueprint $table) {
            $table->boolean('show_kitchen_requirements')->default(false)->after('print_language_profile');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasColumn('catering_settings', 'show_kitchen_requirements')) {
            return;
        }

        Schema::connection('tenant')->table('catering_settings', function (Blueprint $table) {
            $table->dropColumn('show_kitchen_requirements');
        });
    }
};
