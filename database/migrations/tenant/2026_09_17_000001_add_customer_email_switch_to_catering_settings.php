<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATERING-EMAIL-SWITCH-1 — one switch for "do we email the customer at all?"
 *
 * Asked for by the owner on 2026-09-17, during the live trial: a global setting
 * for whether customer emails go out, because on day one they did not want the
 * system writing to their customers while they were still finding their way
 * around it.
 *
 * DEFAULT ON, deliberately. Every tenant already behaves this way, and a
 * migration that silently stopped a live tenant's quotation emails would be a
 * change nobody asked for. Kashif can switch it off from the Catering Settings
 * screen; everyone else carries on exactly as before.
 *
 * It sits on catering_settings, which is already the per-tenant singleton
 * (branch_id NULL) with the reminder recipient and the print language on it —
 * the same place an operator already goes to decide how catering talks to
 * people. Not on branches: an email either goes to a customer or it does not,
 * and a caterer with two kitchens does not want that answered twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('catering_settings', 'send_customer_emails')) {
            return;
        }

        Schema::connection('tenant')->table('catering_settings', function (Blueprint $table) {
            $table->boolean('send_customer_emails')->default(true)->after('reminder_recipient_email');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasColumn('catering_settings', 'send_customer_emails')) {
            return;
        }

        Schema::connection('tenant')->table('catering_settings', function (Blueprint $table) {
            $table->dropColumn('send_customer_emails');
        });
    }
};
