<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('manufacturing_rejection_lines', function (Blueprint $table) {
            $table->id();
            // Explicit short index name — the auto-generated name exceeds MySQL's 64-char limit.
            $table->unsignedBigInteger('manufacturing_rejection_record_id')->index('mfg_rej_lines_record_idx');
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('unit_id')->nullable()->index();
            $table->decimal('quantity', 18, 4);
            $table->decimal('rework_quantity', 18, 4)->default(0);
            $table->decimal('scrap_quantity', 18, 4)->default(0);
            $table->decimal('accepted_after_review_quantity', 18, 4)->default(0);
            $table->decimal('disposed_quantity', 18, 4)->default(0);
            $table->decimal('estimated_loss_value', 18, 4)->nullable();
            $table->string('defect_code', 80)->nullable();
            $table->string('batch_no', 80)->nullable();
            $table->string('lot_no', 80)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('manufacturing_rejection_lines');
    }
};
