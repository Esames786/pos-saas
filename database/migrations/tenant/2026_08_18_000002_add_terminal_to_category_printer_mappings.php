<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KOT-ROUTING-TERMINAL-1 (Phase 3) — key KOT routing on the physical TERMINAL, like the receipt.
 *
 * Adds a nullable `terminal_id` to the category→printer map. NULL means "any terminal", so every
 * existing rule keeps working unchanged — the resolver only prefers a terminal-pinned rule WHEN one
 * exists (see PrintRoutingService). The uniqueness rule now includes terminal_id, because two
 * terminals may legitimately map the same category to the same printer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasColumn('category_printer_mappings', 'terminal_id')) {
            $schema->table('category_printer_mappings', function (Blueprint $table) {
                $table->unsignedBigInteger('terminal_id')->nullable()->after('branch_id');
                $table->index('terminal_id', 'cpm_terminal_idx');
            });
        }

        // Rebuild the uniqueness rule to include terminal_id. Guarded so a re-run — or a DB that never
        // had the old index — is a harmless no-op (the index command executes when table() returns,
        // hence the try wraps the whole call, not the closure).
        try {
            $schema->table('category_printer_mappings', fn (Blueprint $t) => $t->dropUnique('cpm_route_printer_unique'));
        } catch (\Throwable) {
        }
        try {
            $schema->table('category_printer_mappings', fn (Blueprint $t) => $t->unique(
                ['branch_id', 'terminal_id', 'category_id', 'printer_id', 'print_role', 'order_type'],
                'cpm_route_printer_terminal_unique',
            ));
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');

        try {
            $schema->table('category_printer_mappings', fn (Blueprint $t) => $t->dropUnique('cpm_route_printer_terminal_unique'));
        } catch (\Throwable) {
        }
        try {
            $schema->table('category_printer_mappings', fn (Blueprint $t) => $t->unique(
                ['branch_id', 'category_id', 'printer_id', 'print_role', 'order_type'],
                'cpm_route_printer_unique',
            ));
        } catch (\Throwable) {
        }
        if ($schema->hasColumn('category_printer_mappings', 'terminal_id')) {
            $schema->table('category_printer_mappings', function (Blueprint $table) {
                try {
                    $table->dropIndex('cpm_terminal_idx');
                } catch (\Throwable) {
                }
                $table->dropColumn('terminal_id');
            });
        }
    }
};
