<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PURCHASE-EDIT-1 — a supplier invoice could never be voided: `status` had no such value, so a receipt that had an
 * invoice could not be voided either (GRN-VOID-1 refused it). A voided invoice keeps its row and number, its amounts
 * go to 0 (so a report that does not know the new status still adds nothing), and what it was lives on in
 * goods_receipt_revisions. 'voided' is appended at the end — MySQL 8 changes only the column's metadata.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $schema = Schema::connection('tenant');
        if (! $schema->hasTable('purchase_bills')) {
            return;
        }

        $col = DB::connection('tenant')->selectOne("SHOW COLUMNS FROM purchase_bills WHERE Field = 'status'");
        if ($col && ! str_contains($col->Type ?? '', "'voided'") && preg_match('/enum\((.+)\)/i', $col->Type ?? '', $m)) {
            $null = (isset($col->Null) && $col->Null === 'YES') ? 'NULL' : 'NOT NULL';
            $default = $col->Default !== null ? " DEFAULT '".$col->Default."'" : '';
            DB::connection('tenant')->statement(
                "ALTER TABLE purchase_bills MODIFY COLUMN status ENUM({$m[1]},'voided') {$null}{$default}"
            );
        }

        if (! $schema->hasColumn('purchase_bills', 'voided_at')) {
            $schema->table('purchase_bills', function (Blueprint $t) {
                $t->timestamp('voided_at')->nullable();
                $t->unsignedBigInteger('voided_by_user_id')->nullable();
                $t->string('void_reason', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        // No safe rollback once a row is voided — the columns stay, as the other void migrations do.
    }
};
