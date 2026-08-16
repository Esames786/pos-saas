<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-CUSTOMER-CREDIT-1 — giving money back, as its own document.
 *
 * Until now a booking could only take money in. When a quotation was revised
 * downwards after advances had been received, the business was holding money it
 * no longer had a bill for, and there was no supported way to hand it back:
 * every available action either edited history or refused.
 *
 * A refund is therefore a document of its own, exactly like an advance is:
 *
 *   - it never edits or deletes the receipt it settles, so the original advance
 *     and its journal entry stay exactly as posted
 *   - it carries its own number, date, amount, reason and author
 *   - it posts through the same accounting authority (Dr 2300 Customer Advances
 *     / Cr the cash or bank it left from), so the liability falls as the money
 *     leaves and the two always agree
 *
 * ON DELETE RESTRICT on the booking is deliberate: a booking that has paid money
 * out must not be removable underneath its own refund record.
 *
 * Purely additive. Nothing existing reads this table, and a booking with no
 * refunds behaves exactly as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('catering_refunds')) {
            return;
        }

        Schema::connection('tenant')->create('catering_refunds', function (Blueprint $table) {
            $table->id();
            $table->char('refund_uuid', 26)->nullable()->unique();
            $table->string('refund_no', 40)->unique();

            $table->foreignId('catering_event_id')->constrained('catering_events')->restrictOnDelete();

            $table->decimal('amount', 14, 2);
            $table->date('refund_date');

            // How the money physically left. Null means it was recorded without a
            // mapped cash/bank account, exactly as an advance may be.
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('cash_bank_account_id')->nullable()->constrained('cash_bank_accounts')->nullOnDelete();

            $table->string('reference', 255)->nullable();

            // Required by the service. Money leaving the business without a stated
            // reason is not something the ledger should be able to contain.
            $table->string('reason', 255);

            $table->foreignId('refunded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamp('gl_posted_at')->nullable();

            $table->timestamps();

            $table->index(['catering_event_id', 'refund_date'], 'cat_refund_event_date_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('catering_refunds');
    }
};
