<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEST FIXTURE ONLY — never ships. EdgeCleanMachineInstallMySqlTest copies this file into database/migrations/edge for the few
 * seconds it takes to build package B (0.2.0) so that B carries a migration package A (0.1.0) does not, then removes it again.
 * The A→B signed update must apply it in the NEW runtime (EDGE-UPDATE-SCHEMA-IN-NEW-RUNTIME-1). A Feature gate asserts the file
 * is never present in the tree outside that window, so a crashed run cannot leak it into a release.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('edge_clean_machine_proof_marker')) {
            Schema::create('edge_clean_machine_proof_marker', function (Blueprint $table) {
                $table->id();
                $table->string('note', 64);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_clean_machine_proof_marker');
    }
};
