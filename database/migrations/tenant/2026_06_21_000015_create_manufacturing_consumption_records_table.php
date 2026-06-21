<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('manufacturing_consumption_records', function (Blueprint $table) {
            $table->id();
            $table->string('consumption_no', 50)->unique();
            $table->date('consumption_date');
            $table->string('source_type', 30)->nullable();
            // Explicit short index names — auto-generated names exceed MySQL's 64-char limit.
            $table->unsignedBigInteger('wip_job_id')->nullable();
            $table->unsignedBigInteger('material_requisition_id')->nullable();
            $table->unsignedBigInteger('production_order_id')->nullable();
            $table->unsignedBigInteger('manufacturing_customer_id')->nullable();
            $table->unsignedBigInteger('branch_id');
            $table->string('status', 30)->default('draft');
            $table->string('consumption_type', 40)->default('production_usage');
            $table->string('issue_reference', 100)->nullable();
            $table->decimal('total_planned_quantity', 18, 4)->default(0);
            $table->decimal('total_consumed_quantity', 18, 4)->default(0);
            $table->decimal('total_wastage_quantity', 18, 4)->default(0);
            $table->decimal('total_variance_quantity', 18, 4)->default(0);
            $table->decimal('estimated_consumption_value', 18, 4)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index('wip_job_id', 'mfg_cons_rec_wip_idx');
            $table->index('material_requisition_id', 'mfg_cons_rec_mrc_idx');
            $table->index('production_order_id', 'mfg_cons_rec_po_idx');
            $table->index('manufacturing_customer_id', 'mfg_cons_rec_cust_idx');
            $table->index('branch_id', 'mfg_cons_rec_branch_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('manufacturing_consumption_records');
    }
};
