<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 — har restaurant ki apni SEO (Google ka title/description/keywords) aur website ke safhe:
 * Privacy Policy (likha hua text) aur FAQs ([{category, q, a}]). Website Settings → "SEO & pages".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('web_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('web_settings', 'seo_title')) {
                $table->string('seo_title', 120)->nullable();
                $table->string('seo_description', 300)->nullable();
                $table->string('seo_keywords', 300)->nullable();
                $table->text('privacy_policy')->nullable();
                $table->json('faqs')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('web_settings', 'seo_title')) {
            Schema::connection('tenant')->table('web_settings', function (Blueprint $table) {
                $table->dropColumn(['seo_title', 'seo_description', 'seo_keywords', 'privacy_policy', 'faqs']);
            });
        }
    }
};
