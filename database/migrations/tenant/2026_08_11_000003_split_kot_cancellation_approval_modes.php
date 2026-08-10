<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-08-11: cancelling a whole order and reducing an item's quantity after the KOT went
 * to the kitchen are different risks, so they get separate approval settings. The existing column
 * keeps its meaning (WHOLE ORDER); the new one covers quantity reductions and is seeded from the
 * existing value, so behaviour is identical until someone changes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('branches', 'held_kot_line_cancellation_approval_mode')) {
            return;
        }

        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            $table->string('held_kot_line_cancellation_approval_mode', 30)
                ->default('manager_required')
                ->after('held_kot_cancellation_approval_mode');
        });

        DB::connection('tenant')->statement(
            'UPDATE branches SET held_kot_line_cancellation_approval_mode = held_kot_cancellation_approval_mode'
        );
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('branches', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('branches', 'held_kot_line_cancellation_approval_mode')) {
                $table->dropColumn('held_kot_line_cancellation_approval_mode');
            }
        });
    }
};
