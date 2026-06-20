<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('manufacturing_boms', function (Blueprint $table) {
            $table->id();
            $table->string('bom_no', 50)->unique();
            $table->unsignedBigInteger('finished_product_id')->index();
            $table->string('name', 255)->nullable();
            $table->string('version', 50)->default('1.0');
            $table->decimal('output_quantity', 18, 4)->default(1);
            $table->string('status', 30)->default('draft');
            $table->date('effective_from')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('manufacturing_boms');
    }
};
