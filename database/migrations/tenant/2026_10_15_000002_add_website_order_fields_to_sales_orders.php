<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 (Phase 2c) — website se aaya order POS par.
 *
 * - order_source me 'website' (aaj sirf pos/manual).
 * - web_order_key: website ka apna idempotency key (customer do dafa "Place order" dabaye to aik hi
 *   order). `client_uuid` istemal nahi ho sakta: POS Pay par usay apne UUID se badal deta hai.
 * - web_token: customer ke order-status / confirm page ka lamba random link.
 * - web_meta: website ki tafseel (area, landmark, GPS pin, doosra number, customer ka note, har item ka
 *   note). Alag column is liye ke POS ka re-Hold `notes` ko mita deta hai — ye column POS kabhi nahi likhta.
 *
 * Mojooda orders par kuch nahi badalta: naye columns khaali, enum me sirf aik qeemat juRti hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');
        $driver = DB::connection('tenant')->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::connection('tenant')->statement(
                "ALTER TABLE sales_orders MODIFY order_source ENUM('pos','manual','website') NOT NULL DEFAULT 'manual'"
            );
        }

        $schema->table('sales_orders', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('sales_orders', 'web_order_key')) {
                $table->char('web_order_key', 36)->nullable()->unique();
                $table->string('web_token', 64)->nullable()->unique();
                $table->json('web_meta')->nullable();
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');
        if ($schema->hasColumn('sales_orders', 'web_order_key')) {
            $schema->table('sales_orders', function (Blueprint $table) {
                $table->dropUnique(['web_order_key']);
                $table->dropUnique(['web_token']);
                $table->dropColumn(['web_order_key', 'web_token', 'web_meta']);
            });
        }
        if (in_array(DB::connection('tenant')->getDriverName(), ['mysql', 'mariadb'], true)
            && ! DB::connection('tenant')->table('sales_orders')->where('order_source', 'website')->exists()) {
            DB::connection('tenant')->statement(
                "ALTER TABLE sales_orders MODIFY order_source ENUM('pos','manual') NOT NULL DEFAULT 'manual'"
            );
        }
    }
};
