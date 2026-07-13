<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DELIVERY-CHANNELS-1: fulfilment channels (Foodpanda / aggregators / own delivery)
 * + branch-scoped delivery riders, attributed on sales_orders for delivery orders.
 * Attribution only - no stock or finance impact.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('delivery_channels')) {
            Schema::connection('tenant')->create('delivery_channels', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->enum('type', ['aggregator', 'own'])->default('aggregator');
                $table->decimal('commission_percent', 5, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });

            DB::connection('tenant')->table('delivery_channels')->insert([
                ['name' => 'Own Delivery', 'type' => 'own', 'commission_percent' => 0, 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Foodpanda', 'type' => 'aggregator', 'commission_percent' => 0, 'is_active' => true, 'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Careem', 'type' => 'aggregator', 'commission_percent' => 0, 'is_active' => true, 'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if (! Schema::connection('tenant')->hasTable('delivery_riders')) {
            Schema::connection('tenant')->create('delivery_riders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('name', 100);
                $table->string('phone', 30)->nullable();
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamps();
            });
        }

        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            $sm = Schema::connection('tenant');

            if (! $sm->hasColumn('sales_orders', 'delivery_channel_id')) {
                $table->foreignId('delivery_channel_id')->nullable()->after('order_type')
                    ->constrained('delivery_channels')->nullOnDelete();
            }

            if (! $sm->hasColumn('sales_orders', 'delivery_rider_id')) {
                $table->foreignId('delivery_rider_id')->nullable()->after('delivery_channel_id')
                    ->constrained('delivery_riders')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sales_orders', function (Blueprint $table) {
            $sm = Schema::connection('tenant');

            if ($sm->hasColumn('sales_orders', 'delivery_rider_id')) {
                $table->dropConstrainedForeignId('delivery_rider_id');
            }

            if ($sm->hasColumn('sales_orders', 'delivery_channel_id')) {
                $table->dropConstrainedForeignId('delivery_channel_id');
            }
        });

        Schema::connection('tenant')->dropIfExists('delivery_riders');
        Schema::connection('tenant')->dropIfExists('delivery_channels');
    }
};
