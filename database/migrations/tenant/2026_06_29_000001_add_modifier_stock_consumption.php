<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODIFIER-INVENTORY-1 — let a modifier option deduct a linked inventory product when
 * a sale is finalized. All additive + nullable; existing options keep consume_stock=false
 * so no current behaviour changes until an admin enables it.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $sm = Schema::connection('tenant');

        $sm->table('modifiers', function (Blueprint $t) use ($sm) {
            if (! $sm->hasColumn('modifiers', 'consume_stock')) {
                $t->boolean('consume_stock')->default(false)->after('linked_product_id');
            }
            if (! $sm->hasColumn('modifiers', 'linked_quantity')) {
                $t->decimal('linked_quantity', 18, 4)->nullable()->after('consume_stock');
            }
            if (! $sm->hasColumn('modifiers', 'linked_unit_id')) {
                $t->unsignedBigInteger('linked_unit_id')->nullable()->after('linked_quantity');
            }
        });
    }

    public function down(): void
    {
        $sm = Schema::connection('tenant');

        $sm->table('modifiers', function (Blueprint $t) use ($sm) {
            foreach (['linked_unit_id', 'linked_quantity', 'consume_stock'] as $c) {
                if ($sm->hasColumn('modifiers', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
