<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUPPLIER-PAYMENT-VOID-1 (13 Oct) — supplier payment ulti karne ka raasta.
 *
 * Kashif Kitchen ka accountant Cash Book se ghalat entry ulti karna chahta tha. Catering receipt,
 * expense aur manual journal ke liye raasta pehle se tha; supplier payment ke liye KOI raasta nahi
 * tha — Owner ke paas bhi nahi. Ek baar save hui payment hamesha ke liye thi.
 *
 * 🚨 ROW MITAYA NAHI JATA — catering receipt aur final invoice ki tarah row rehta hai aur us par
 * nishan lagta hai. Paisa ek nayi ulti satar se wapas aata hai (cash, supplier ledger, GL).
 *
 * Chaar khaane, chaaron nullable — purani payments jaisi hain waisi rehti hain:
 *   • voided_at              — kab (yahi "void hua?" ka nishan hai)
 *   • voided_by_user_id      — kis ne
 *   • void_reason            — kyun (service khali qubool nahi karti)
 *   • void_journal_entry_id  — GL ki ulti entry
 */
return new class extends Migration
{
    private const TABLE = 'supplier_payments';

    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable(self::TABLE) || $schema->hasColumn(self::TABLE, 'voided_at')) {
            return;
        }

        $schema->table(self::TABLE, function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('posted_by_user_id');
            $table->foreignId('voided_by_user_id')->nullable()->after('voided_at')
                ->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by_user_id');
            $table->unsignedBigInteger('void_journal_entry_id')->nullable()->after('void_reason');
            $table->index('voided_at', 'supplier_payments_voided_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable(self::TABLE) || ! $schema->hasColumn(self::TABLE, 'voided_at')) {
            return;
        }

        $schema->table(self::TABLE, function (Blueprint $table) {
            $table->dropIndex('supplier_payments_voided_idx');
            $table->dropConstrainedForeignId('voided_by_user_id');
            $table->dropColumn(['voided_at', 'void_reason', 'void_journal_entry_id']);
        });
    }
};
