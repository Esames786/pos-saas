<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PNL-COST-BY-CATEGORY-1 (14 Oct) — P&L par Beverages (aur koi bhi cost head) alag line.
 *
 * Bill ka bina-stock hissa aaj tak sirf ek khaate, 5100 Product COGS, me jata tha — cold drink, paani,
 * sab ek satar. Ab category apna "Cost of Sales account" rakh sakti hai (5000 ke neeche), aur bill us
 * line ka hissa usi khaate me daalta hai. Khali = purana bartaao (5100), yani mapping ke baghair kuch
 * nahi badalta.
 *
 *   • categories.cogs_account_id           — category ka cost head (child khali ho to parent ka)
 *   • goods_receipt_lines.cost_account_id  — bill ne IS line ka hissa asal me kis khaate me daala.
 *       Return isi ko credit karta hai, taake mapping baad me badle tab bhi return usi khaate ko
 *       palte jise bill ne debit kiya tha. Purani lines par NULL = mapping se pehle ka bill (5100).
 *
 * Dono nullable aur additive; har tenant par deploy ke saath chalti hai, mapping na ho to be-asar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if ($schema->hasTable('categories') && ! $schema->hasColumn('categories', 'cogs_account_id')) {
            $schema->table('categories', function (Blueprint $table) {
                $table->foreignId('cogs_account_id')->nullable()->after('branch_id')
                    ->constrained('accounts')->nullOnDelete();
            });
        }

        if ($schema->hasTable('goods_receipt_lines') && ! $schema->hasColumn('goods_receipt_lines', 'cost_account_id')) {
            $schema->table('goods_receipt_lines', function (Blueprint $table) {
                $table->foreignId('cost_account_id')->nullable()->after('affects_stock')
                    ->constrained('accounts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');

        if ($schema->hasColumn('goods_receipt_lines', 'cost_account_id')) {
            $schema->table('goods_receipt_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('cost_account_id'));
        }
        if ($schema->hasColumn('categories', 'cogs_account_id')) {
            $schema->table('categories', fn (Blueprint $table) => $table->dropConstrainedForeignId('cogs_account_id'));
        }
    }
};
