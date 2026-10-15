<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONLINE-ORDERING-1 — malik (15 Oct): "backup terminal ki jagah multi-select". Aik order type kai terminals par:
 * ghanti sab par bajti hai, draft pehle wale par jis ki shift khuli ho (tarteeb = list ki tarteeb).
 * Purani rows: [terminal, backup]. `terminal_id`/`backup_terminal_id` pehle do ke saath bhare rehte hain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('web_order_routes', 'terminal_ids')) {
            Schema::connection('tenant')->table('web_order_routes', function (Blueprint $table) {
                $table->json('terminal_ids')->nullable()->after('backup_terminal_id');
            });
        }

        foreach (DB::connection('tenant')->table('web_order_routes')->whereNull('terminal_ids')->get() as $r) {
            $ids = array_values(array_unique(array_filter([(int) $r->terminal_id, (int) $r->backup_terminal_id])));
            DB::connection('tenant')->table('web_order_routes')->where('id', $r->id)->update(['terminal_ids' => json_encode($ids)]);
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('web_order_routes', 'terminal_ids')) {
            Schema::connection('tenant')->table('web_order_routes', function (Blueprint $table) {
                $table->dropColumn('terminal_ids');
            });
        }
    }
};
