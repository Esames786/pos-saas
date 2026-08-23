<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-EVENT-FORM-1 — the sittings a caterer actually books.
 *
 * Lunch, Dinner, Sehri, Iftar, Mehndi, Late night: these are the house's own
 * habits, not the developer's guesses hard-coded into a blade. They live in
 * the tenant's data so the owner can rename, retime, reorder or retire them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('catering_service_time_presets', function (Blueprint $table) {
            $table->id();
            $table->string('label', 60);
            $table->time('service_time');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Reference data ships WITH its table: every tenant gets the sittings
        // on migrate, with no extra deploy step to forget. The seeder class
        // exists for reruns and never overwrites an owner's own timing.
        (new \Database\Seeders\Tenant\CateringServiceTimePresetSeeder)->run();
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('catering_service_time_presets');
    }
};
