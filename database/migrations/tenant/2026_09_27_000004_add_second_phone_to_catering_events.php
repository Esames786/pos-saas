<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATERING-PHONE-2-1 — booking par doosra phone.
 *
 * Malik (27 Sep): "phone 1 required kardo, phone 2 optional kardo."
 *
 * Ye sirf sahulat nahi, ek MOJOODA KHARABI ka ilaj hai. Abhi ek hi khaana hai,
 * is liye jahan graahak ke do number hain wahan dono ek hi khaane me thoos
 * diye gaye — prod par EV-20260921-0021 ka phone is waqt
 * "0312-0080000  0312-0090000" hai. Aisa khaana na dial ho sakta hai, na
 * dhoonda ja sakta hai, aur customer ki pehchan bhi usi par bunti hai.
 * (Yehi kharabi legacy import me 165 customers par darj hai.)
 *
 * PEHCHAN PHONE 1 SE HI RAHEGI. Customer `findOrCreateByPhone` se milta hai
 * aur wo pehla number parhta hai; doosra number booking par likha ek raabte ka
 * zariya hai — driver ke liye — customer ki doosri pehchan nahi. Do numbers se
 * pehchan karna ek shaks ke do customer bana deta.
 *
 * Additive: ek nullable column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('catering_events', 'customer_phone_2')) {
            return;
        }

        Schema::connection('tenant')->table('catering_events', function (Blueprint $table) {
            $table->string('customer_phone_2', 40)->nullable()->after('customer_phone');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasColumn('catering_events', 'customer_phone_2')) {
            return;
        }

        Schema::connection('tenant')->table('catering_events', function (Blueprint $table) {
            $table->dropColumn('customer_phone_2');
        });
    }
};
