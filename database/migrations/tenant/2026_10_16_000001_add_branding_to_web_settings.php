<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 — website ki shakl: logo, banner slides, footer ki maloomat, aur cart me "Popular with your
 * order" kis category se. Malik: purani website jaisi (logo, banners, WhatsApp, footer). Sab ikhtiyari — khaali
 * ho to website pehle jaisi chalti hai. POS par koi asar nahi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('web_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('web_settings', 'logo_path')) {
                $table->string('logo_path')->nullable();
                // [{"path": "...-1920.webp", "sm": "...-960.webp"}, …] — tarteeb wohi jo slider me.
                $table->json('banners')->nullable();
                $table->string('whatsapp_number', 40)->nullable();
                $table->string('contact_email', 150)->nullable();
                $table->string('contact_address', 255)->nullable();
                $table->string('facebook_url', 255)->nullable();
                $table->string('instagram_url', 255)->nullable();
                $table->text('about_text')->nullable();
                $table->unsignedBigInteger('upsell_category_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('web_settings', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'banners', 'whatsapp_number', 'contact_email', 'contact_address',
                'facebook_url', 'instagram_url', 'about_text', 'upsell_category_id']);
        });
    }
};
