<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PARTNER-SHARE-1 — one dish, two kitchens.
 *
 * Some dishes are made partly by the restaurant and partly by a partner kitchen run inside it. Each
 * time such a dish sells, one side owes the other a FIXED amount. These two tables hold that rate
 * list; the Report Center's "Partner Share" section multiplies it by what was sold. Nothing here
 * touches a sale, stock or the books — the report only reads.
 *
 * `share_rates` rows are versions: a change of rate is a NEW row with a later `effective_from`, so a
 * past period keeps reporting at the rate it sold under. `item_key` ("p:52" a product, "c:6" a deal)
 * exists because MySQL lets any number of rows share a unique key that has a NULL in it — a plain
 * unique over (partner, product_id, combo_id, effective_from) would not stop a duplicate deal rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('share_partners')) {
            Schema::connection('tenant')->create('share_partners', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120)->unique();
                // A dish given away (a Rs 0 line) owes nothing unless the owner says otherwise.
                $table->boolean('count_free_lines')->default(false);
                $table->boolean('is_active')->default(true);
                $table->string('notes', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::connection('tenant')->hasTable('share_rates')) {
            Schema::connection('tenant')->create('share_rates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('share_partner_id')->constrained('share_partners')->cascadeOnDelete();
                $table->string('item_key', 32);
                $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
                $table->foreignId('combo_id')->nullable()->constrained('combos')->cascadeOnDelete();
                $table->string('payer', 16);   // partner = the partner pays us · us = we pay the partner
                $table->decimal('amount', 12, 2);
                $table->date('effective_from');
                $table->string('notes', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['share_partner_id', 'item_key', 'effective_from'], 'share_rates_version_unique');
                $table->index(['item_key', 'effective_from']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('share_rates');
        Schema::connection('tenant')->dropIfExists('share_partners');
    }
};
