<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('manufacturing_scrap_records', function (Blueprint $table) {
            $table->id();
            $table->string('scrap_no', 50)->unique();
            $table->date('scrap_date');
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('wip_job_id')->nullable()->index();
            $table->unsignedBigInteger('finished_good_receipt_id')->nullable()->index();
            $table->unsignedBigInteger('production_order_id')->nullable()->index();
            $table->unsignedBigInteger('manufacturing_customer_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->string('status', 30)->default('draft');
            $table->string('scrap_type', 40)->default('production_loss');
            $table->string('reason_code', 40)->nullable();
            $table->string('quality_status', 30)->nullable();
            $table->decimal('total_quantity', 18, 4)->default(0);
            $table->decimal('recoverable_quantity', 18, 4)->default(0);
            $table->decimal('disposed_quantity', 18, 4)->default(0);
            $table->decimal('estimated_loss_value', 18, 4)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('manufacturing_scrap_records');
    }
};
