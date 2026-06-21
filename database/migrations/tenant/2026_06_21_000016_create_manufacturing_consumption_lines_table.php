<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('manufacturing_consumption_lines', function (Blueprint $table) {
            $table->id();
            // Explicit short index names — auto-generated names exceed MySQL's 64-char limit.
            $table->unsignedBigInteger('manufacturing_consumption_record_id');
            $table->unsignedBigInteger('wip_job_line_id')->nullable();
            $table->unsignedBigInteger('material_requisition_line_id')->nullable();
            $table->unsignedBigInteger('component_product_id');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('planned_quantity', 18, 4)->default(0);
            $table->decimal('consumed_quantity', 18, 4);
            $table->decimal('wastage_quantity', 18, 4)->default(0);
            $table->decimal('variance_quantity', 18, 4)->default(0);
            $table->decimal('estimated_unit_cost', 18, 4)->nullable();
            $table->decimal('estimated_total_value', 18, 4)->nullable();
            $table->string('batch_no', 80)->nullable();
            $table->string('lot_no', 80)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('manufacturing_consumption_record_id', 'mfg_cons_line_rec_idx');
            $table->index('wip_job_line_id', 'mfg_cons_line_wip_line_idx');
            $table->index('material_requisition_line_id', 'mfg_cons_line_mrc_line_idx');
            $table->index('component_product_id', 'mfg_cons_line_product_idx');
            $table->index('unit_id', 'mfg_cons_line_unit_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('manufacturing_consumption_lines');
    }
};
