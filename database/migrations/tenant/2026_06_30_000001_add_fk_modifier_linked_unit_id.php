<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUG-021 FIX: Add foreign key constraint on modifiers.linked_unit_id so that
 * deleting a unit nullifies the modifier's linked_unit reference rather than
 * leaving a dangling pointer that causes stockConsumptionLabel() to show blank.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // First clean up any orphaned values from before the constraint existed.
        \Illuminate\Support\Facades\DB::connection('tenant')->statement(
            "UPDATE modifiers SET linked_unit_id = NULL
             WHERE linked_unit_id IS NOT NULL
               AND linked_unit_id NOT IN (SELECT id FROM units)"
        );

        Schema::connection('tenant')->table('modifiers', function (Blueprint $table) {
            if (! $this->fkExists('modifiers', 'modifiers_linked_unit_id_foreign')) {
                $table->foreign('linked_unit_id', 'modifiers_linked_unit_id_foreign')
                      ->references('id')
                      ->on('units')
                      ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('modifiers', function (Blueprint $table) {
            if ($this->fkExists('modifiers', 'modifiers_linked_unit_id_foreign')) {
                $table->dropForeign('modifiers_linked_unit_id_foreign');
            }
        });
    }

    private function fkExists(string $table, string $fkName): bool
    {
        $constraints = \Illuminate\Support\Facades\DB::connection('tenant')
            ->select(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND CONSTRAINT_NAME = ?
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$table, $fkName]
            );

        return count($constraints) > 0;
    }
};
