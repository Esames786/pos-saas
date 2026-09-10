<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE EDGE — F1 SALES RETURNS, appliance side.
 *
 * RETURNABLE-SALE WARM CACHE. A sale created ONLINE must be returnable offline, so the appliance holds a read-only
 * projection of the branch's returnable Cloud sales as SHADOW rows in the canonical sales tables (status
 * `cloud_mirror`, never a report population status, never synced) plus a mapping table with the Cloud identities and
 * the Cloud's own returned quantities. The canonical return math (SalesReturnService::computeReturn) therefore applies
 * unchanged; the Cloud stays the official financial truth. Cloud returns already posted against those sales are
 * mirrored the same way (sales_returns status `cloud_mirror`) so remaining discount/tax allocation is exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addEnumValue('sales_orders', 'status', 'cloud_mirror', 'draft');
        $this->addEnumValue('sales_returns', 'status', 'cloud_mirror', 'posted');

        Schema::connection('tenant')->table('sales_returns', function (Blueprint $table) {
            $table->char('edge_return_uuid', 26)->nullable()->unique('sr_edge_return_uuid_unique'); // the immutable return EVENT this document is
            $table->string('edge_origin', 20)->nullable();                                          // local | cloud_mirror
            $table->unsignedBigInteger('edge_cloud_sales_return_id')->nullable();                   // mirrored Cloud return identity
        });

        Schema::connection('tenant')->create('edge_returnable_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cloud_sales_order_id')->unique();
            $table->unsignedBigInteger('local_sales_order_id')->unique();     // the shadow sales_orders row
            $table->char('sale_uuid', 26)->nullable();                         // Edge-originated sales carry it
            $table->string('sale_no', 64);
            $table->string('cloud_status', 30);
            $table->timestamp('cloud_updated_at')->nullable();
            $table->timestamp('refreshed_at');
            $table->timestamps();
        });

        Schema::connection('tenant')->create('edge_returnable_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('returnable_sale_id')->constrained('edge_returnable_sales')->cascadeOnDelete();
            $table->unsignedBigInteger('cloud_sales_order_line_id')->unique();
            $table->unsignedBigInteger('local_sales_order_line_id')->unique(); // the shadow sales_order_lines row
            $table->decimal('sold_quantity', 14, 3);
            $table->decimal('cloud_returned_quantity', 14, 3)->default(0);    // authoritative Cloud returns at refresh
            $table->string('unit_code', 50)->nullable();
            $table->string('unit_type', 20)->nullable();                       // quantity | weight | volume | length
            $table->timestamps();
        });

        Schema::connection('tenant')->table('edge_local_meta', function (Blueprint $table) {
            $table->string('returnable_cache_watermark', 128)->nullable();
            $table->timestamp('returnable_cache_as_of')->nullable();
            $table->timestamp('returnable_cache_refreshed_at')->nullable();
            $table->string('standby_returnable_watermark_seen', 128)->nullable();
            $table->timestamp('standby_returnable_as_of_seen')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('edge_local_meta', function (Blueprint $table) {
            $table->dropColumn(['returnable_cache_watermark', 'returnable_cache_as_of', 'returnable_cache_refreshed_at', 'standby_returnable_watermark_seen', 'standby_returnable_as_of_seen']);
        });
        Schema::connection('tenant')->dropIfExists('edge_returnable_sale_lines');
        Schema::connection('tenant')->dropIfExists('edge_returnable_sales');
        Schema::connection('tenant')->table('sales_returns', function (Blueprint $table) {
            $table->dropUnique('sr_edge_return_uuid_unique');
            $table->dropColumn(['edge_return_uuid', 'edge_origin', 'edge_cloud_sales_return_id']);
        });
    }

    /** Append one value to a MySQL ENUM column (idempotent), keeping every existing value and the default. */
    private function addEnumValue(string $table, string $column, string $value, string $default): void
    {
        $conn = DB::connection('tenant');
        $row = collect($conn->select("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"))->first();
        if (! $row) {
            return;
        }
        $type = (string) $row->Type; // enum('a','b')
        if (! str_starts_with(strtolower($type), 'enum(')) {
            return; // a string column already accepts the value
        }
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", substr($type, 5, -1), $m);
        $values = $m[1];
        if (in_array($value, $values, true)) {
            return;
        }
        $values[] = $value;
        $list = implode(',', array_map(fn ($v) => "'" . str_replace("'", "''", $v) . "'", $values));
        $conn->statement("ALTER TABLE `{$table}` MODIFY `{$column}` ENUM({$list}) NOT NULL DEFAULT '{$default}'");
    }
};
