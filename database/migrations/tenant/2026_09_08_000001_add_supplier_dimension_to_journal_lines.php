<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUPPLIER-FINANCE-DIRECT-1 — journal line par counterparty ka khaana.
 *
 * Manual journal aaj `2100 Accounts Payable` par credit/debit kar sakta hai bina batae ke
 * KIS supplier ka. Nateeja: AP control hil jata hai aur supplier subledger ko pata bhi nahi
 * chalta — yani wohi drift jo requirement K mana karti hai.
 *
 * Dono column **nullable** hain, jaan-boojh kar:
 *   - purane 2,700+ journal lines waise hi rehte hain (koi backfill nahi, koi maani nahi badla)
 *   - system ke banaye journals (sale, purchase bill, opening balance, catering) inhein nahi
 *     bharte — shart sirf manual/interactive raaste par lagti hai
 *
 * Yani ye migration **kuch bhi lazmi nahi** karti; lazmi hona controller ki validation par hai.
 * Schema sirf jagah deta hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('journal_lines', function (Blueprint $table) {
            // Abhi sirf 'supplier' istemal hota hai. String rakha, enum nahi — kal customer
            // ya employee ka dimension chahiye ho to migration dobara nahi likhni paregi
            // (supplier_ledgers.entry_type bhi isi wajah se string(50) hai, enum nahi).
            $table->string('counterparty_type', 20)->nullable()->after('branch_id');

            $table->foreignId('supplier_id')->nullable()->after('counterparty_type')
                ->constrained('suppliers')->nullOnDelete();

            // AP control ko supplier ke hisab se jama karne wali query isi par chalti hai.
            $table->index(['counterparty_type', 'supplier_id'], 'journal_lines_counterparty_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('journal_lines', function (Blueprint $table) {
            $table->dropIndex('journal_lines_counterparty_idx');
            $table->dropForeign(['supplier_id']);
            $table->dropColumn(['counterparty_type', 'supplier_id']);
        });
    }
};
