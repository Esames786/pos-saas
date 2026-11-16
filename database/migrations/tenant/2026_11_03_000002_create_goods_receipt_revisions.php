<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PURCHASE-EDIT-1 — a posted receipt (and its supplier invoice) can be corrected in place: the same GRN and invoice
 * numbers, the stock / supplier ledger / GL corrected by their own entries. One row per edit or void: what it was,
 * what it became, the plain-words change list the GRN page shows, and the journals it reversed and posted.
 *
 * `goods_receipts.revision_no` is the stamp the edit form carries back: if someone else saved first, the save is
 * refused instead of silently overwriting their change. Both additive; nothing existing is altered or backfilled.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable('goods_receipt_revisions')) {
            $schema->create('goods_receipt_revisions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('goods_receipt_id');
                $t->unsignedBigInteger('purchase_bill_id')->nullable()->index();
                $t->unsignedInteger('revision_no');
                $t->string('action', 10);                        // edit | void
                $t->json('before');
                $t->json('after');
                $t->json('changes');                             // plain-words lines for the GRN page
                $t->string('reason', 255)->nullable();
                $t->string('date_mode', 10)->nullable();         // void only: original | today
                $t->json('reversed_journal_ids')->nullable();
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->timestamps();
                $t->unique(['goods_receipt_id', 'revision_no'], 'goods_receipt_revisions_grn_revision_unique');
            });
        }

        if ($schema->hasTable('goods_receipts') && ! $schema->hasColumn('goods_receipts', 'revision_no')) {
            $schema->table('goods_receipts', function (Blueprint $t) {
                $t->unsignedInteger('revision_no')->default(0)->after('status');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('goods_receipt_revisions');
        if (Schema::connection('tenant')->hasColumn('goods_receipts', 'revision_no')) {
            Schema::connection('tenant')->table('goods_receipts', fn (Blueprint $t) => $t->dropColumn('revision_no'));
        }
    }
};
