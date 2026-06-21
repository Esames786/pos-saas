<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('finished_good_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('finished_good_receipt_id')->index();
            $table->unsignedBigInteger('finished_product_id')->index();
            $table->unsignedBigInteger('unit_id')->nullable()->index();
            $table->string('batch_no', 80)->nullable();
            $table->string('lot_no', 80)->nullable();
            $table->decimal('received_quantity', 18, 4);
            $table->decimal('accepted_quantity', 18, 4)->default(0);
            $table->decimal('rejected_quantity', 18, 4)->default(0);
            $table->decimal('scrap_quantity', 18, 4)->default(0);
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('finished_good_receipt_lines');
    }
};
