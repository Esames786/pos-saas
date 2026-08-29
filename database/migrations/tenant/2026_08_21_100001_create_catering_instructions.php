<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-INSTRUCTIONS-1 — a managed kitchen-instruction vocabulary.
 *
 * The client's old system multi-selects instructions per booking line from a
 * curated list — Mirch Kam, Chawal Dana Dana, Koyala — because a free-text-only
 * field is how the kitchen ends up receiving four spellings of the same
 * instruction. This is the master for that vocabulary.
 *
 * DELIBERATELY SEEDED EMPTY. The authoritative ~55-entry list must come from
 * the client's export; inventing it here would put words in the kitchen's
 * mouth. The Owner records entries through the management screen, and the
 * existing free-text field survives as an "additional note" beside the
 * selections, so historical lines lose nothing.
 *
 * Labels: `label` is the Roman-Urdu working label operators type and search by;
 * `label_ur` is the Urdu script the kitchen sheet can print. Deactivating an
 * entry hides it from new selection but never deletes it — lines that already
 * reference it keep their meaning.
 *
 * This is MASTER data — a vocabulary, like the rate books — so a transaction
 * reset keeps it. The line-to-instruction pivot is transactional and goes with
 * the lines it points at.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('catering_instructions')) {
            Schema::connection('tenant')->create('catering_instructions', function (Blueprint $table) {
                $table->id();
                $table->string('label', 120);
                $table->string('label_ur', 120)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique('label', 'catering_instructions_label_unique');
            });
        }

        if (! Schema::connection('tenant')->hasTable('catering_estimate_line_instruction')) {
            Schema::connection('tenant')->create('catering_estimate_line_instruction', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catering_estimate_line_id');
                $table->foreign('catering_estimate_line_id', 'celi_line_fk')
                    ->references('id')->on('catering_estimate_lines')->cascadeOnDelete();
                $table->foreignId('catering_instruction_id');
                $table->foreign('catering_instruction_id', 'celi_instruction_fk')
                    ->references('id')->on('catering_instructions')->cascadeOnDelete();

                $table->unique(['catering_estimate_line_id', 'catering_instruction_id'], 'celi_line_instruction_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('catering_estimate_line_instruction');
        Schema::connection('tenant')->dropIfExists('catering_instructions');
    }
};
