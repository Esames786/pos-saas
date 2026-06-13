<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::connection('master')->table('plans', function (Blueprint $table) {
            if (! Schema::connection('master')->hasColumn('plans', 'is_custom')) {
                $table->boolean('is_custom')->default(false)->after('is_public');
            }

            if (! Schema::connection('master')->hasColumn('plans', 'display_order')) {
                $table->unsignedInteger('display_order')->default(0)->after('trial_days');
            }

            if (! Schema::connection('master')->hasColumn('plans', 'public_description')) {
                $table->text('public_description')->nullable()->after('display_order');
            }

            if (! Schema::connection('master')->hasColumn('plans', 'monthly_price')) {
                $table->decimal('monthly_price', 12, 2)->nullable()->after('price');
            }

            if (! Schema::connection('master')->hasColumn('plans', 'yearly_price')) {
                $table->decimal('yearly_price', 12, 2)->nullable()->after('monthly_price');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('master')->table('plans', function (Blueprint $table) {
            foreach (['yearly_price', 'monthly_price', 'public_description', 'display_order', 'is_custom'] as $column) {
                if (Schema::connection('master')->hasColumn('plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
