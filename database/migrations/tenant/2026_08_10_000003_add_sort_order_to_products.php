<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// KHATRI-MENU-2: explicit product display ordering (small → large within a category).
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('products', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('products', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('products', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('products', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });
    }
};
