<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('material_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('mrc_no', 50)->unique();
            $table->unsignedBigInteger('production_order_id')->nullable()->index();
            $table->unsignedBigInteger('manufacturing_customer_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->date('request_date');
            $table->date('required_date')->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('priority', 20)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('material_requisitions');
    }
};
