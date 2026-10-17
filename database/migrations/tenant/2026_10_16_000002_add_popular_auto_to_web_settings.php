<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 — "Popular Items" khud asli bikri se (pichhle 7 din) ya haath se chune hue. Default: khud.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('web_settings', 'popular_auto')) {
            Schema::connection('tenant')->table('web_settings', function (Blueprint $table) {
                $table->boolean('popular_auto')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('web_settings', 'popular_auto')) {
            Schema::connection('tenant')->table('web_settings', function (Blueprint $table) {
                $table->dropColumn('popular_auto');
            });
        }
    }
};
