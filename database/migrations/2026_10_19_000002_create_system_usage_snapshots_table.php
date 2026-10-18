<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MASTER-UNIVERSE-1 — honest usage numbers for Bingoo Master.
 *
 * `system:usage-snapshot` writes one row for the whole server (tenant_id NULL: CPU load, RAM, disk,
 * total MySQL size) and one row per tenant (its database size, its files, 30-day sales, products,
 * users). The Master dashboard reads the newest rows instead of opening every tenant on each visit,
 * and the disk forecast is the growth between two server rows at least a day apart.
 *
 * Per-tenant CPU / RAM is NOT stored: on one shared PHP-FPM + MySQL it cannot be measured, so the
 * screens show an "activity share" (sales share) clearly labelled as an estimate.
 * A value that could not be read is NULL (shown as "—"), never 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('master');
        if ($schema->hasTable('system_usage_snapshots')) {
            return;
        }

        $schema->create('system_usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->timestamp('taken_at')->index();
            // NULL = the whole server; otherwise one tenant.
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('db_bytes')->nullable();
            $table->unsignedBigInteger('files_bytes')->nullable();
            $table->unsignedInteger('sales_30d')->nullable();
            $table->unsignedInteger('products')->nullable();
            $table->unsignedInteger('users')->nullable();
            $table->decimal('cpu_load_1', 8, 2)->nullable();
            $table->unsignedSmallInteger('cpu_cores')->nullable();
            $table->unsignedBigInteger('ram_total_kb')->nullable();
            $table->unsignedBigInteger('ram_used_kb')->nullable();
            $table->unsignedBigInteger('disk_total_bytes')->nullable();
            $table->unsignedBigInteger('disk_free_bytes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('system_usage_snapshots');
    }
};
