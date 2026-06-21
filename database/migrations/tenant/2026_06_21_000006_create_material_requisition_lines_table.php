<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('material_requisition_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('material_requisition_id')->index();
            $table->unsignedBigInteger('component_product_id')->index();
            $table->unsignedBigInteger('unit_id')->nullable()->index();
            $table->decimal('required_quantity', 18, 4);
            $table->decimal('issued_quantity', 18, 4)->default(0);
            $table->decimal('wastage_percent', 8, 4)->default(0);
            $table->unsignedBigInteger('source_bom_line_id')->nullable()->index();
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('material_requisition_lines');
    }
};
