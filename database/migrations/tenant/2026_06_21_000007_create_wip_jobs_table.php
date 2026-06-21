<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('wip_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('wip_no', 50)->unique();
            $table->unsignedBigInteger('production_order_id')->index();
            $table->unsignedBigInteger('material_requisition_id')->nullable()->index();
            $table->unsignedBigInteger('manufacturing_customer_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('finished_product_id')->index();
            $table->decimal('planned_quantity', 18, 4);
            $table->decimal('started_quantity', 18, 4)->default(0);
            $table->decimal('completed_quantity', 18, 4)->default(0);
            $table->date('start_date');
            $table->date('target_date')->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('priority', 20)->nullable();
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('wip_jobs');
    }
};
