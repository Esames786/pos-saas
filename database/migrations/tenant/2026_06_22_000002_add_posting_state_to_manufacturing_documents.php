<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-FIN-B — posting-state columns on future-postable manufacturing documents.
 *
 * INFRASTRUCTURE ONLY. Adds posting_status (default 'unposted'), posted_at,
 * posted_by_user_id, journal_entry_id, reversed_of_id so a FUTURE posting phase
 * can track whether a document has been posted/reversed. No posting happens here;
 * all existing rows default to 'unposted'. Explicit short index names (MySQL
 * 64-char limit).
 */
return new class extends Migration
{
    /** table => short index prefix */
    private array $tables = [
        'production_orders'                 => 'po',
        'manufacturing_consumption_records' => 'cons',
        'finished_good_receipts'            => 'fg',
        'manufacturing_scrap_records'       => 'scrap',
        'manufacturing_rejection_records'   => 'rej',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $p) {
            if (Schema::connection('tenant')->hasColumn($table, 'posting_status')) {
                continue;
            }
            Schema::connection('tenant')->table($table, function (Blueprint $t) use ($p) {
                $t->string('posting_status', 20)->default('unposted');
                $t->timestamp('posted_at')->nullable();
                $t->unsignedBigInteger('posted_by_user_id')->nullable();
                $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->unsignedBigInteger('reversed_of_id')->nullable();

                $t->index('posting_status', "{$p}_post_status_idx");
                $t->index('posted_by_user_id', "{$p}_posted_by_idx");
                $t->index('journal_entry_id', "{$p}_journal_entry_idx");
                $t->index('reversed_of_id', "{$p}_reversed_of_idx");
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $p) {
            if (! Schema::connection('tenant')->hasColumn($table, 'posting_status')) {
                continue;
            }
            Schema::connection('tenant')->table($table, function (Blueprint $t) use ($p) {
                $t->dropIndex("{$p}_post_status_idx");
                $t->dropIndex("{$p}_posted_by_idx");
                $t->dropIndex("{$p}_journal_entry_idx");
                $t->dropIndex("{$p}_reversed_of_idx");
                $t->dropColumn(['posting_status', 'posted_at', 'posted_by_user_id', 'journal_entry_id', 'reversed_of_id']);
            });
        }
    }
};
