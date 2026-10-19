<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SAAS-BILLING-AUTO-1 — har tenant ka apna invoice banne ka din.
 *
 * Malik ne har tenant ke liye alag tareekh di (khatri 15, kashifkitchen 20, kashiffood 25,
 * tawakal 10). Ab tak ye kahin mehfooz nahi thi — sirf ek backfill command ke andar likhi hui thi,
 * yani har mahine invoice banane ke liye mujhe kehna paRta. Wohi baat WhatsApp ki settings ke saath
 * hui thi aur malik ne theek kaha tha "nazar nahi ari".
 *
 * NULL ka matlab: is subscription ka invoice khud nahi banega. Demo aur trial tenants ko isi se
 * chhoR diya jata hai — unhe mahana bill nahi jata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->table('subscriptions', function (Blueprint $table) {
            // 1–31. 31 un mahinon me khud chhota ho jata hai jin me 31 din nahi (February ki 28/29),
            // warna February me kisi ka invoice banta hi nahi.
            $table->unsignedTinyInteger('invoice_day')->nullable()->after('billing_period');
        });
    }

    public function down(): void
    {
        Schema::connection('master')->table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('invoice_day');
        });
    }
};
