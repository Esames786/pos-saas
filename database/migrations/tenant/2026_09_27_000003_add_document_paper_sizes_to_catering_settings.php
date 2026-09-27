<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KITCHEN-SHEET-PAPER-SETTING-1 — kaghaz ka size ab setting hai, code me
 * thoosa hua nahi.
 *
 * Malik (27 Sep): "add a setting in software setting — kitchen sheet default
 * print size the same size you handle, and for other draft estimation or
 * quotation print normal A4 size."
 *
 * Do alag column jaan-boojh kar, ek nahi: ye do MUKHTALIF kaghaz hain jo
 * mukhtalif logon ke liye chhapte hain. Kitchen sheet bawarchi ke haath me
 * jaati hai — chhoti behtar hai. Quotation graahak ko jaati hai — wo A4 hi
 * rehti hai. Ek hi setting dono par lagti to ek ko theek karne par doosri
 * bigadti.
 *
 * DEFAULTS wohi jo malik ne maange: kitchen sheet A5 chaura (wo naya size jo
 * purane software se mila), quotation A4 lamba (jaisi abhi hai). Yani migrate
 * hone par kisi tenant ke liye kuch nahi badalta — ye sirf us cheez ko setting
 * bana deti hai jo abhi tak tay-shuda thi.
 *
 * Additive: do string column apni default ke saath.
 */
return new class extends Migration
{
    /** Kaghaz ke jaiz naam — screen, document aur validation teenon isi par chalte hain. */
    public const SIZES = ['a5_portrait', 'a5_landscape', 'a4_portrait', 'a4_landscape'];

    public function up(): void
    {
        Schema::connection('tenant')->table('catering_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('catering_settings', 'kitchen_sheet_paper')) {
                $table->string('kitchen_sheet_paper', 20)->default('a5_portrait');
            }
            if (! Schema::connection('tenant')->hasColumn('catering_settings', 'quotation_paper')) {
                $table->string('quotation_paper', 20)->default('a4_portrait');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('catering_settings', function (Blueprint $table) {
            foreach (['kitchen_sheet_paper', 'quotation_paper'] as $col) {
                if (Schema::connection('tenant')->hasColumn('catering_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
