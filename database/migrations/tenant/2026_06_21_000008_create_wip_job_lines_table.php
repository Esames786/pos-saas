<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('wip_job_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wip_job_id')->index();
            $table->unsignedBigInteger('material_requisition_line_id')->nullable()->index();
            $table->unsignedBigInteger('component_product_id')->index();
            $table->unsignedBigInteger('unit_id')->nullable()->index();
            $table->decimal('required_quantity', 18, 4);
            $table->decimal('issued_quantity', 18, 4)->default(0);
            $table->decimal('consumed_quantity', 18, 4)->default(0);
            $table->decimal('remaining_quantity', 18, 4)->default(0);
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('wip_job_lines');
    }
};
