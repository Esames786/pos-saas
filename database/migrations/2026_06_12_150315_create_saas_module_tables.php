<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        if (!Schema::connection('master')->hasTable('modules')) {
            Schema::connection('master')->create('modules', function (Blueprint $table) {
                $table->id();
                $table->string('key', 80)->unique();
                $table->string('name');
                $table->string('category', 80)->nullable();
                $table->text('description')->nullable();

                // Used later by 14A-2 enforcement to map route_catalogs.module_key
                // to commercial modules.
                $table->json('route_module_keys')->nullable();

                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_core')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['is_active', 'sort_order']);
            });
        }

        if (!Schema::connection('master')->hasTable('plan_modules')) {
            Schema::connection('master')->create('plan_modules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
                $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
                $table->boolean('is_enabled')->default(true);
                $table->json('limits')->nullable();
                $table->timestamps();

                $table->unique(['plan_id', 'module_id']);
                $table->index(['plan_id', 'is_enabled']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('plan_modules');
        Schema::connection('master')->dropIfExists('modules');
    }
};
