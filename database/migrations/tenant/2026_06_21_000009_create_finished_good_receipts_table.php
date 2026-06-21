<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('finished_good_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('fg_no', 50)->unique();
            $table->unsignedBigInteger('wip_job_id')->index();
            $table->unsignedBigInteger('production_order_id')->index();
            $table->unsignedBigInteger('manufacturing_customer_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('finished_product_id')->index();
            $table->date('receipt_date');
            $table->string('status', 30)->default('draft');
            $table->string('quality_status', 30)->nullable();
            $table->decimal('planned_quantity', 18, 4);
            $table->decimal('received_quantity', 18, 4);
            $table->decimal('accepted_quantity', 18, 4)->default(0);
            $table->decimal('rejected_quantity', 18, 4)->default(0);
            $table->decimal('scrap_quantity', 18, 4)->default(0);
            $table->string('priority', 20)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('finished_good_receipts');
    }
};
