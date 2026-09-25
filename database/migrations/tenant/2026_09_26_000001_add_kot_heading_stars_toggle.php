<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KOT-HEADING-STARS-1 — `*** KOT #1 ***` ke sitare per-branch qaabu me.
 *
 * Khatri ke client ne parchi par likha: "star remove". Magar ye sitare
 * EscPosPayloadService::kot() me likhe hue hain, yani WOHI code chaaron chalti hui
 * businesses ki parchi chhapta hai — aur maang sirf ek ki hai. Seedha hata dena baaqi
 * teen ki parchi bina poochhe badal deta.
 *
 * Is liye switch, aur us ki default qeemat AAJ WALI SOORAT: `true`. Deploy ke din kisi
 * tenant ki parchi nahi badalti; sirf jab operator Edit Layout me ise band kare tab.
 *
 * Wohi tareeqa jo `show_column_dividers` (default OFF — aaj kisi parchi par lakeerein
 * nahi) aur `show_category_header` (default ON — aaj chhapta hai) ka tha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'show_heading_stars')) {
                $table->boolean('show_heading_stars')->default(true)->after('show_category_header');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('receipt_layout_settings', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('receipt_layout_settings', 'show_heading_stars')) {
                $table->dropColumn('show_heading_stars');
            }
        });
    }
};
