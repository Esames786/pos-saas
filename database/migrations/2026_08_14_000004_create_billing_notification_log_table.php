<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLOUD-BILLING-3A — an at-most-once ledger for transactional billing emails.
 *
 * A UNIQUE (event, subject_type, subject_id) row is CLAIMED before a mail is sent, so a retry /
 * overlap / re-run can never send the same billing email twice. Same idempotency shape as the
 * scheduled-report dispatcher. Master connection.
 */
return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        if (Schema::connection('master')->hasTable('billing_notification_log')) {
            return;
        }

        Schema::connection('master')->create('billing_notification_log', function (Blueprint $table) {
            $table->id();
            $table->string('event');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('recipient')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['event', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('billing_notification_log');
    }
};
