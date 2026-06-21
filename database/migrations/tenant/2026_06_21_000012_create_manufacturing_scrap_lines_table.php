<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('manufacturing_scrap_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('manufacturing_scrap_record_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('unit_id')->nullable()->index();
            $table->decimal('quantity', 18, 4);
            $table->decimal('recoverable_quantity', 18, 4)->default(0);
            $table->decimal('disposed_quantity', 18, 4)->default(0);
            $table->decimal('estimated_loss_value', 18, 4)->nullable();
            $table->string('batch_no', 80)->nullable();
            $table->string('lot_no', 80)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('manufacturing_scrap_lines');
    }
};
