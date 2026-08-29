<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QUICK-REPORT-SEND-1 — per-user saved defaults for the POS Quick Report modal (which sections, which
 * categories / items / waiters / order-types). Additive; one row per user.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('pos_quick_report_settings')) {
            return;
        }

        Schema::connection('tenant')->create('pos_quick_report_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('pos_quick_report_settings');
    }
};
