<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BUG-062 FIX: add 'partially_received' to purchase_orders.status enum so GRNs
 * can correctly distinguish partial vs full receipt without marking a PO as fully
 * 'received' on the very first GRN regardless of how many items remain outstanding.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('purchase_orders')) {
            return;
        }

        // Check whether the value already exists (idempotent).
        $col = DB::connection('tenant')->selectOne(
            "SHOW COLUMNS FROM purchase_orders WHERE Field = 'status'"
        );

        if ($col && str_contains($col->Type ?? '', 'partially_received')) {
            return; // already present
        }

        DB::connection('tenant')->statement(
            "ALTER TABLE purchase_orders
             MODIFY COLUMN status
             ENUM('draft','approved','cancelled','received','partially_received')
             NOT NULL DEFAULT 'draft'"
        );
    }

    public function down(): void
    {
        // Only remove the value if no rows use it (safe rollback).
        $inUse = DB::connection('tenant')
            ->table('purchase_orders')
            ->where('status', 'partially_received')
            ->exists();

        if ($inUse) {
            return;
        }

        DB::connection('tenant')->statement(
            "ALTER TABLE purchase_orders
             MODIFY COLUMN status
             ENUM('draft','approved','cancelled','received')
             NOT NULL DEFAULT 'draft'"
        );
    }
};
