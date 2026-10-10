<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATERING-INVOICE-VOID-1 (10 Oct) — bill wapas kholne ka raasta.
 *
 * Malik: "jo order ab complete ho gaye hain before date, un ki invoices ko
 * proforma mein change karna hoga taake wo order edit ho sake."
 *
 * Ab tak is ka koi raasta tha hi nahi. Model ke comment me likha tha
 * "void/reversal policy is a future finance design" — yani ye jaan boojh kar
 * baad ke liye chhora gaya tha, aur banaya kabhi nahi gaya. Prod par 33 me se
 * 3 bookings apne din se pehle bill ho chuki hain aur phans gayi hain.
 *
 * 🚨 ROW MITAYA NAHI JATA. Numbered dastavez ko mita dena do kharabiyan ek
 * saath hai: qatar me hamesha ka sooraakh, aur "us waqt kya bill hua tha" ki
 * gawahi ka gum ho jana. Is liye row rehta hai aur us par nishan lagta hai.
 *
 * Teen khaane, teenon nullable — purane bill jaise hain waise rehte hain:
 *   • voided_at         — kab
 *   • voided_by_user_id — kis ne
 *   • void_reason       — kyun (lazmi likhna hota hai; service khali qubool nahi karti)
 */
return new class extends Migration
{
    private const TABLE = 'catering_final_invoices';

    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable(self::TABLE)) {
            return;
        }

        if (! $schema->hasColumn(self::TABLE, 'voided_at')) {
            $schema->table(self::TABLE, function (Blueprint $table) {
                $table->timestamp('voided_at')->nullable()->after('issued_by_user_id');
                $table->foreignId('voided_by_user_id')->nullable()->after('voided_at')
                    ->constrained('users')->nullOnDelete();
                $table->string('void_reason', 500)->nullable()->after('voided_by_user_id');

                // Index is liye ke har screen ab "void nahi hua" poochhegi — wo
                // shart ek global scope se har query par lagti hai.
                $table->index('voided_at', 'catering_final_invoices_voided_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable(self::TABLE) || ! $schema->hasColumn(self::TABLE, 'voided_at')) {
            return;
        }

        $schema->table(self::TABLE, function (Blueprint $table) {
            $table->dropIndex('catering_final_invoices_voided_idx');
            $table->dropConstrainedForeignId('voided_by_user_id');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
