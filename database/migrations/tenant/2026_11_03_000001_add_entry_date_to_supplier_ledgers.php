<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUPPLIER-LEDGER-DAILY-1 — a supplier ledger row had no date of its own: the screen showed when it was typed
 * (created_at), so a bill typed a day late sat on the wrong day and a day's debit/credit/balance could not be read
 * (client, 3 Nov: "ledger daily basis par check karna hai — invoice ki date se, posting date se nahi").
 *
 * `entry_date` = the business date of the document behind the row (invoice / payment / return / journal date).
 * Additive and NULL: old rows are filled by `finance:supplier-ledger-dates` (dry-run first); a row it cannot match
 * stays NULL and the screen falls back to DATE(created_at). Nothing else reads it.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('supplier_ledgers')
            || Schema::connection('tenant')->hasColumn('supplier_ledgers', 'entry_date')) {
            return;
        }

        Schema::connection('tenant')->table('supplier_ledgers', function (Blueprint $t) {
            $t->date('entry_date')->nullable()->after('amount');
            $t->index(['supplier_id', 'entry_date'], 'supplier_ledgers_supplier_entry_date_index');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasColumn('supplier_ledgers', 'entry_date')) {
            return;
        }
        Schema::connection('tenant')->table('supplier_ledgers', function (Blueprint $t) {
            $t->dropIndex('supplier_ledgers_supplier_entry_date_index');
            $t->dropColumn('entry_date');
        });
    }
};
