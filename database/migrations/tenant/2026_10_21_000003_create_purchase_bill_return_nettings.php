<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUPPLIER-NET-BILL-1 — a supplier invoice that already takes off goods returned before it.
 *
 * GRN 1,000; 200 returned before the bill; the supplier bills 800. The bill records the invoice as written (800) and
 * one row here per return it already takes off. The row is what keeps that return's credit from being taken off a
 * second time: the supplier ledger gets a matching debit, the GL a matching entry, and the running account never offers
 * that credit again. The return itself is never edited. One return can be taken off one invoice only (unique).
 *
 * A new table — nothing existing is altered or backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Owner, 21 Oct: a return's credit that already settled ANOTHER bill is moved only on an authorized person's
        // explicit confirmation, and every move is written down: which bill gave it up, how much, its outstanding
        // before and after, who confirmed and when.
        if (! Schema::hasTable('supplier_credit_reallocations')) {
            Schema::create('supplier_credit_reallocations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('purchase_bill_id')->index();          // the invoice the credit moves to
                $t->unsignedBigInteger('purchase_return_id')->index();
                $t->unsignedBigInteger('from_purchase_bill_id')->index();     // the bill it came off
                $t->decimal('amount', 15, 4);
                $t->decimal('from_due_before', 15, 4);
                $t->decimal('from_due_after', 15, 4);
                $t->unsignedBigInteger('confirmed_by')->nullable();
                $t->timestamp('confirmed_at')->nullable();
                $t->timestamps();
            });
        }
        if (Schema::hasTable('purchase_bill_return_nettings')) {
            return;
        }
        Schema::create('purchase_bill_return_nettings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_bill_id')->constrained('purchase_bills');
            $t->foreignId('purchase_return_id')->unique()->constrained('purchase_returns');
            $t->unsignedBigInteger('supplier_id')->index();
            $t->decimal('amount', 15, 4);
            $t->unsignedBigInteger('journal_entry_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_bill_return_nettings');
        Schema::dropIfExists('supplier_credit_reallocations');
    }
};
