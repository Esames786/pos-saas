<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATERING-ADVANCE-VOID-1 — receipt ko ULTA karne ke khaane.
 *
 * Malik (1 Oct): "client keh raha hai us ne amount daalte hue ghalti kar di,
 * ya reference likhte hue — wo kaise edit hoga?"
 *
 * Aaj tak koi raasta tha hi nahi: receipt par sirf `store` hai, na edit na
 * void. Amount zyada ho to Refund se wapas karna parta tha, kam ho to ek aur
 * receipt — aur bilkul ghalat entry (duplicate, ya galat booking par) ka koi
 * ilaj hi nahi tha.
 *
 * AMOUNT KO "EDIT" KARNA JAAN-BOOJH KAR NAHI BANAYA JA RAHA. Jab receipt
 * darj hoti hai to paisa HIL CHUKA hota hai: ek journal entry ban chuki hoti
 * hai aur cash/bank ka balance barh chuka hota hai. Us adad ko chup chaap
 * badal dena sab se bura hal hota — kitabon me ek adad hota aur screen par
 * doosra, aur koi nishan na hota ke kya hua tha. Is liye receipt ULTI hoti
 * hai (journal reversal + cash wapas), aur sahi receipt nayi darj hoti hai.
 * Poora trail qayam rehta hai.
 *
 * Ye wohi tareeqa hai jo `ExpenseService::void()` barson se chala raha hai.
 *
 * Additive: chaar nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('catering_advances', 'voided_at')) {
            return;
        }

        Schema::connection('tenant')->table('catering_advances', function (Blueprint $table) {
            // Yehi ek khaana faisla karta hai ke receipt ginti me aaye ya nahi —
            // dekho CateringAdvance ka global scope.
            $table->timestamp('voided_at')->nullable()->after('gl_posted_at');
            $table->string('void_reason', 255)->nullable()->after('voided_at');
            $table->unsignedBigInteger('voided_by_user_id')->nullable()->after('void_reason');
            // Ulti journal entry, taake ledger par dono satrein jor kar dekhi
            // ja sakein aur dobara reversal na bane.
            $table->unsignedBigInteger('void_journal_entry_id')->nullable()->after('voided_by_user_id');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasColumn('catering_advances', 'voided_at')) {
            return;
        }

        Schema::connection('tenant')->table('catering_advances', function (Blueprint $table) {
            $table->dropColumn(['voided_at', 'void_reason', 'voided_by_user_id', 'void_journal_entry_id']);
        });
    }
};
