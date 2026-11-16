<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WEB-PICKUP-SWITCH-1 — website par Pick-Up ka on/off (malik, 29 Oct: "pickup band kardo"). Default ON: deploy kisi
 * restaurant ki website nahi badalta; band karna Website Settings se.
 */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection('tenant');
        if ($s->hasTable('web_settings') && ! $s->hasColumn('web_settings', 'pickup_enabled')) {
            $s->table('web_settings', function (Blueprint $t) {
                $t->boolean('pickup_enabled')->default(true)->after('pickup_eta_minutes');
            });
        }
    }

    public function down(): void
    {
        $s = Schema::connection('tenant');
        if ($s->hasTable('web_settings') && $s->hasColumn('web_settings', 'pickup_enabled')) {
            $s->table('web_settings', fn (Blueprint $t) => $t->dropColumn('pickup_enabled'));
        }
    }
};
