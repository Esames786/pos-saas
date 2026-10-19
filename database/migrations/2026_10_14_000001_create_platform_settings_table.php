<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 — platform ki apni settings (Billing rates).
 *
 * Malik ka faisla (14 Oct): WhatsApp ka fi message charge aur website orders ka commission % `.env` me
 * nahi, master ki settings me — taake rate badalne ke liye deploy aur `config:cache` na karna paRe,
 * aur platform admin khud badal sake.
 *
 * Pehli bharai `.env` ki MAUJOODA qeemat se (prod par WHATSAPP_RATE_PKR=9.85, WHATSAPP_COST_PKR=5.95),
 * taake deploy ke din kisi tenant ka bill aik paisa na hile. Row pehle se ho to chhui nahi jati.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('master')->hasTable('platform_settings')) {
            Schema::connection('master')->create('platform_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100)->unique();
                $table->text('value')->nullable();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('central_users')->nullOnDelete();
                $table->timestamps();
            });
        }

        $now = now();
        $seed = [
            'whatsapp_rate_pkr' => (string) (float) config('services.whatsapp.rate_pkr', 9.85),
            'whatsapp_cost_pkr' => (string) (float) config('services.whatsapp.cost_pkr', 5.95),
            'website_commission_percent' => '1',
        ];

        foreach ($seed as $key => $value) {
            DB::connection('master')->table('platform_settings')->insertOrIgnore([
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('platform_settings');
    }
};
