<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('report_schedules', function (Blueprint $table) {
            $table->json('recipient_emails')->nullable()->after('sections');
            $table->string('delivery_format', 20)->default('csv')->after('recipient_emails');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('report_schedules', function (Blueprint $table) {
            $table->dropColumn(['recipient_emails', 'delivery_format']);
        });
    }
};
