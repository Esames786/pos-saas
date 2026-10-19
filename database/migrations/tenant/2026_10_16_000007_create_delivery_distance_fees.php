<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DELIVERY-RADIUS-1 — website ki delivery fee branch se doori ke hisaab se (malik, 16 Oct: "rates radius ke
 * hisaab se, branch Karachi ki hai to Karachi se bahar delivery nahi").
 *
 *   web_branch_settings  branch ka map par nuqta (doori yahan se) + "Get directions" ka link (pick-up card).
 *                        `branches` nahi chhuti — wo POS / Edge ki table hai.
 *   delivery_fee_bands   har branch ke bands: "up_to_km tak = fee". Aakhri band se aage delivery nahi.
 *   web_settings.delivery_fee_mode  area (har area ki apni fee, pehle jaisa) | distance (bands). Default area:
 *                        deploy kuch nahi badalta, malik khud on karta hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('tenant');

        if (! $s->hasTable('web_branch_settings')) {
            $s->create('web_branch_settings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->unique()->constrained('branches')->cascadeOnDelete();
                $t->decimal('lat', 10, 7)->nullable();
                $t->decimal('lng', 10, 7)->nullable();
                $t->string('map_url', 500)->nullable();
                $t->timestamps();
            });
        }

        if (! $s->hasTable('delivery_fee_bands')) {
            $s->create('delivery_fee_bands', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $t->decimal('up_to_km', 6, 2);
                $t->decimal('fee', 10, 2);
                $t->timestamps();
                $t->unique(['branch_id', 'up_to_km']);
            });
        }

        if (! $s->hasColumn('web_settings', 'delivery_fee_mode')) {
            $s->table('web_settings', function (Blueprint $t) {
                $t->string('delivery_fee_mode', 10)->default('area')->after('pickup_eta_minutes');
            });
        }
    }

    public function down(): void
    {
        $s = Schema::connection('tenant');
        if ($s->hasColumn('web_settings', 'delivery_fee_mode')) {
            $s->table('web_settings', fn (Blueprint $t) => $t->dropColumn('delivery_fee_mode'));
        }
        $s->dropIfExists('delivery_fee_bands');
        $s->dropIfExists('web_branch_settings');
    }
};
