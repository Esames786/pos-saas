<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PURCHASE-DISCOUNTS-1 (owner, 19 Oct: "product level discount fixed ya % … line discount … overall bill
 * discount fixed or %"). Additive, all NULL on every existing row — nothing changes until the new purchase
 * screens use them.
 *
 *   products   purchase_discount_type / _value   the usual discount when buying it (percent | amount, Rs per line)
 *              purchase_scheme_buy / _free        the usual scheme, e.g. 10 + 1 (in buying packs)
 *              sale_discount_type / _value        the usual discount when selling it (read by the counter later)
 *   suppliers  default_discount_percent          what this supplier usually gives
 *   goods_receipt_lines, purchase_order_lines
 *              entry_json                        what was typed on the line (pack, price, disc, scheme, bill disc)
 *                                                — read back as "the last deal" with this supplier.
 *
 * MySQL: ALGORITHM=INSTANT (no table copy, no lock that could freeze a live counter); if INSTANT is not
 * possible the statement fails instead of copying the table, and the deploy reports the tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::connection()->getDriverName() === 'mysql';
        if ($mysql) {
            DB::statement('SET SESSION lock_wait_timeout = 10');
        }

        $this->add('products', 'purchase_discount_type', [
            'purchase_discount_type' => ['VARCHAR(8) NULL', fn (Blueprint $t) => $t->string('purchase_discount_type', 8)->nullable()],
            'purchase_discount_value' => ['DECIMAL(12,4) NULL', fn (Blueprint $t) => $t->decimal('purchase_discount_value', 12, 4)->nullable()],
            'purchase_scheme_buy' => ['SMALLINT UNSIGNED NULL', fn (Blueprint $t) => $t->unsignedSmallInteger('purchase_scheme_buy')->nullable()],
            'purchase_scheme_free' => ['SMALLINT UNSIGNED NULL', fn (Blueprint $t) => $t->unsignedSmallInteger('purchase_scheme_free')->nullable()],
            'sale_discount_type' => ['VARCHAR(8) NULL', fn (Blueprint $t) => $t->string('sale_discount_type', 8)->nullable()],
            'sale_discount_value' => ['DECIMAL(12,4) NULL', fn (Blueprint $t) => $t->decimal('sale_discount_value', 12, 4)->nullable()],
        ], $mysql);
        $this->add('suppliers', 'default_discount_percent', [
            'default_discount_percent' => ['DECIMAL(6,3) NULL', fn (Blueprint $t) => $t->decimal('default_discount_percent', 6, 3)->nullable()],
        ], $mysql);
        foreach (['goods_receipt_lines', 'purchase_order_lines'] as $table) {
            $this->add($table, 'entry_json', [
                'entry_json' => ['JSON NULL', fn (Blueprint $t) => $t->json('entry_json')->nullable()],
            ], $mysql);
        }
    }

    public function down(): void
    {
        foreach ([
            'products' => ['purchase_discount_type', 'purchase_discount_value', 'purchase_scheme_buy', 'purchase_scheme_free', 'sale_discount_type', 'sale_discount_value'],
            'suppliers' => ['default_discount_percent'],
            'goods_receipt_lines' => ['entry_json'],
            'purchase_order_lines' => ['entry_json'],
        ] as $table => $cols) {
            $have = array_values(array_filter($cols, fn ($c) => Schema::hasColumn($table, $c)));
            if ($have) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($have));
            }
        }
    }

    /** @param array<string, array{0:string, 1:callable}> $cols */
    private function add(string $table, string $marker, array $cols, bool $mysql): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $marker)) {
            return;
        }
        if ($mysql) {
            DB::statement('ALTER TABLE `' . $table . '` '
                . implode(', ', array_map(fn ($c, $def) => 'ADD COLUMN `' . $c . '` ' . $def[0], array_keys($cols), $cols))
                . ', ALGORITHM=INSTANT');

            return;
        }
        Schema::table($table, function (Blueprint $t) use ($cols) {
            foreach ($cols as $def) {
                $def[1]($t);
            }
        });
    }
};
