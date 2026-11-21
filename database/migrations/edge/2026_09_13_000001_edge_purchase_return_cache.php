<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OFFLINE EDGE — F3 PURCHASE RETURN PARITY (appliance side): the READ-ONLY warm projection of the branch's goods receipts
 * (the canonical source of a purchase return) with received / already-returned quantities and unit costs, the applied
 * Edge events, and the appliance's own immutable purchase-return events. No local GL, AP or valuation ledger.
 * Index names are explicit (MySQL 64-char identifier limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('edge_purchase_return_grns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cloud_grn_id')->unique('epr_grn_cloud_id_uq');
            $table->string('grn_no', 50);
            $table->unsignedBigInteger('branch_id');
            $table->string('status', 20)->default('posted');
            $table->date('receipt_date')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('cloud_supplier_id')->index('epr_grn_supplier_idx');
            $table->string('supplier_code', 50)->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_status', 20)->nullable();
            $table->unsignedBigInteger('cloud_bill_id')->nullable();
            $table->string('bill_no', 50)->nullable();
            $table->timestamp('cloud_updated_at')->nullable();
            $table->timestamp('refreshed_at');
            $table->timestamps();
        });

        Schema::connection('tenant')->create('edge_purchase_return_grn_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cloud_grn_line_id')->unique('epr_line_cloud_id_uq');
            $table->unsignedBigInteger('cloud_grn_id')->index('epr_line_grn_idx');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('unit_code', 50)->nullable();
            $table->string('batch_no', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity_received', 15, 3);
            $table->decimal('cloud_returned_quantity', 15, 3)->default(0);   // OFFICIAL posted returns sourced from this line
            $table->decimal('unit_cost', 15, 4)->default(0);                 // the canonical valuation of a return from this line
            $table->timestamp('refreshed_at');
            $table->timestamps();
        });

        Schema::connection('tenant')->create('edge_purchase_return_applied_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_uuid', 26)->unique('epr_applied_event_uq');
            $table->string('official_return_no', 64)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('refreshed_at');
            $table->timestamps();
        });

        Schema::connection('tenant')->create('edge_local_purchase_return_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_uuid', 26)->unique('elpr_event_uuid_uq');
            $table->unsignedBigInteger('cloud_grn_id')->index('elpr_event_grn_idx');
            $table->unsignedBigInteger('cloud_supplier_id')->index('elpr_event_supplier_idx');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->date('return_date');
            $table->string('reason_code', 50)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('grand_total', 15, 4);
            $table->json('payload');
            $table->string('envelope_schema_version', 64);
            $table->char('content_hash', 64);
            $table->string('projection_watermark', 128)->nullable();
            $table->timestamps();
        });

        Schema::connection('tenant')->create('edge_local_purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->char('event_uuid', 26)->index('elpr_line_event_idx');
            $table->char('line_uuid', 26)->unique('elpr_line_uuid_uq');
            $table->unsignedBigInteger('cloud_grn_line_id')->index('elpr_line_grn_line_idx');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('line_total', 15, 4)->default(0);
            $table->string('reason_code', 50)->nullable();
            $table->timestamps();
        });

        Schema::connection('tenant')->table('edge_local_meta', function (Blueprint $table) {
            $table->string('purchase_return_cache_watermark', 128)->nullable();
            $table->timestamp('purchase_return_cache_as_of')->nullable();
            $table->timestamp('purchase_return_cache_refreshed_at')->nullable();
            $table->string('standby_purchase_return_watermark_seen', 128)->nullable();
            $table->timestamp('standby_purchase_return_as_of_seen')->nullable();
            $table->text('purchase_return_rules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('edge_local_meta', function (Blueprint $table) {
            $table->dropColumn(['purchase_return_cache_watermark', 'purchase_return_cache_as_of', 'purchase_return_cache_refreshed_at', 'standby_purchase_return_watermark_seen', 'standby_purchase_return_as_of_seen', 'purchase_return_rules']);
        });
        foreach (['edge_local_purchase_return_lines', 'edge_local_purchase_return_events', 'edge_purchase_return_applied_events', 'edge_purchase_return_grn_lines', 'edge_purchase_return_grns'] as $t) {
            Schema::connection('tenant')->dropIfExists($t);
        }
    }
};
