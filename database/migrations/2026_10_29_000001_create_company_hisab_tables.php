<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MASTER-HISAB-1 — Bingoo ka apna hisab (Master → Hisab). Plan: docs/plans/master-hisab-2026-10-29.md.
 *
 * Paisa tenants se aata hai magar company ke apne bank me nahi — owners ke apne accounts me. To hisab ki
 * buniyaad "khata" hai: paisa kis ke paas para hai. Har owner ka hissa (share %) mahine ke nafe se banta hai,
 * aur jo khata us owner ka hai us ka balance batata hai ke use asal me kitna mila.
 *
 * Sirf naye tables; koi live code inhe nahi likhta, sirf naya safha — is liye ek hi deploy kaafi hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // Har mahine ke nafe me se kitna. Sab active owners mil kar 100 — check controller me.
            $table->decimal('share_percent', 5, 2);
            $table->foreignId('central_user_id')->nullable()->constrained('central_users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('company_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // Kis owner ka apna account hai. NULL = company ka apna khata (us ka paisa abhi kisi ka nahi).
            $table->foreignId('partner_id')->nullable()->constrained('company_partners')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('company_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_no', 30)->unique();
            $table->date('entry_date');
            // in = paisa aaya, out = kharcha, transfer = ek khate se doosre me (nafa nahi badalta).
            $table->string('type', 10);
            // Kis client (tenant) se aaya — sirf "in" par, aur woh bhi zaroori nahi.
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->text('note')->nullable();

            // WhatsApp ka card wala kharcha: Meta ka bill dollar me, bank ka tax/fee, aur kitne messages ka bill.
            // Line ki raqam = bank ne bill ka jo kata + fee. Dollar rate aur tax % inhi se nikalte hain.
            $table->decimal('usd_amount', 12, 4)->nullable();
            $table->decimal('bank_fee', 15, 2)->nullable();
            $table->unsignedInteger('message_count')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('central_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('central_users')->nullOnDelete();
            // Delete = chhupana, mitana nahi: paisa ka record wajah ke saath rehta hai.
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('central_users')->nullOnDelete();
            $table->string('delete_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['entry_date', 'type']);
            $table->index('deleted_at');
        });

        Schema::create('company_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_entry_id')->constrained('company_entries')->cascadeOnDelete();
            // in: jis khate me aaya · out: jis khate se gaya · transfer: jis khate se gaya.
            $table->foreignId('account_id')->constrained('company_accounts')->restrictOnDelete();
            // Sirf transfer: jis khate me gaya.
            $table->foreignId('to_account_id')->nullable()->constrained('company_accounts')->restrictOnDelete();
            $table->string('category', 40);
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index(['account_id', 'company_entry_id']);
            $table->index('to_account_id');
        });

        Schema::create('company_entry_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_entry_id')->constrained('company_entries')->cascadeOnDelete();
            $table->string('action', 20);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('central_user_id')->nullable()->constrained('central_users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_entry_logs');
        Schema::dropIfExists('company_entry_lines');
        Schema::dropIfExists('company_entries');
        Schema::dropIfExists('company_accounts');
        Schema::dropIfExists('company_partners');
    }
};
