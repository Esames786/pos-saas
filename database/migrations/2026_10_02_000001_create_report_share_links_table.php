<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHATSAPP-REPORT-CHANNEL-1 (qadam 4) — the report link a WhatsApp message carries.
 *
 * This lives in MASTER on purpose. The approved template's button is a Dynamic URL whose base is
 * fixed at `https://bingoopos.com/r/{{1}}` — one path segment, on the CENTRAL domain, for every
 * tenant. A per-tenant subdomain would have meant a separate approved template per tenant, and a new
 * one for every customer onboarded.
 *
 * So the central app receives a token it cannot interpret on its own: it has no tenant context yet.
 * The row is what tells it which tenant to open, which is why the mapping cannot live in a tenant DB.
 *
 * `expires_at` is the point. A report link is a window onto a shop's takings; forwarded, screenshotted
 * or left in an old chat it would otherwise stay open forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_share_links')) {
            return;
        }

        Schema::create('report_share_links', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->json('filters');
            $table->json('sections');
            $table->string('label', 120);
            $table->timestamp('expires_at')->index();
            $table->timestamp('first_opened_at')->nullable();
            $table->unsignedInteger('opens')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_share_links');
    }
};
