<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUPPLIER-CREDIT-CLARITY-1 — a payment (or return) that settled one invoice, moved by a person to another invoice of the
 * same supplier (owner, 26 Oct: money that landed on an invoice the client did not mean is moved, never left unused).
 * One row per move: what moved, from which invoice to which, how much, why, who and when. The allocations themselves
 * stay the working rows (an invoice's paid = their sum); this table is the record of who changed them.
 *
 * A new table — nothing existing is altered or backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_credit_moves')) {
            return;
        }
        Schema::create('supplier_credit_moves', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_id')->index();
            $t->string('source_type', 20);                                   // payment | return (as supplier_credit_allocations)
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('from_purchase_bill_id')->index();        // the invoice it came off
            $t->unsignedBigInteger('to_purchase_bill_id')->index();          // the invoice it now settles
            $t->decimal('amount', 15, 4);
            $t->string('reason', 255);
            $t->unsignedBigInteger('moved_by')->nullable();
            $t->timestamps();
            $t->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_moves');
    }
};
