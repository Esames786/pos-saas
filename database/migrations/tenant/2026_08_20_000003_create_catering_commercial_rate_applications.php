<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-RATE-IMPACT-1 — who moved a price, when, and from what to what.
 *
 * Applying a house rate is the one act in this feature that changes money. It is
 * selective by design, so afterwards the only way to answer "why is this
 * quotation 392 when it was 382 last week" is a record of the applying. Without
 * one, a selective apply is indistinguishable from a rate that drifted on its
 * own — which is precisely the accusation the whole preview-then-choose design
 * exists to make impossible.
 *
 * The repository has no general audit framework; the established convention is a
 * purpose-built table with a *_by_user_id column (catering_refunds,
 * catering_production_releases, catering_final_invoices all do this). This
 * follows it rather than introducing a second logging mechanism.
 *
 * DELIBERATELY NO FOREIGN KEYS, and deliberately denormalised names. A cost
 * block can be deactivated, a quotation superseded, a product renamed; an audit
 * row that vanished or became unreadable when any of those happened would be
 * worse than no audit at all, because it would look complete. The ids stay for
 * tracing, the text stays for reading, and neither depends on the other
 * surviving.
 *
 * TENANT RESET — WIPED, not kept. This records applications made against
 * transactions (drafts, revisions), and after a transaction reset those
 * references point at estimate ids that no longer exist; a surviving log would
 * read as a history of documents nobody can open. The commercial decisions
 * themselves are not lost by that: catering_material_commercial_rates is master
 * data and is KEPT, so what the house charges — and every dated change to it —
 * survives a reset intact. This table is the record of what was DONE with those
 * rates to transactional documents, and it goes where those documents go.
 */
return new class extends Migration
{
    private const TABLE = 'catering_commercial_rate_applications';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable(self::TABLE)) {
            return;
        }

        Schema::connection('tenant')->create(self::TABLE, function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('material_product_id');
            $table->string('material_name', 190)->nullable();

            // What happened: a rate was recorded, a block was linked to or
            // unlinked from the book, or an applied rate was pushed onto a dish,
            // a draft or a revision.
            $table->string('action', 32);

            // What it happened to.
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_label', 190)->nullable();

            // Set for anything touching a quotation, so one document's whole
            // rate history can be read back without joining through lines.
            $table->unsignedBigInteger('catering_estimate_id')->nullable();

            $table->decimal('old_commercial_rate', 14, 4)->nullable();
            $table->decimal('new_commercial_rate', 14, 4)->nullable();

            // Only meaningful where a dish or line rate exists to move.
            $table->decimal('old_calculated_rate', 14, 2)->nullable();
            $table->decimal('new_calculated_rate', 14, 2)->nullable();

            $table->unsignedBigInteger('performed_by_user_id')->nullable();
            $table->string('note', 255)->nullable();

            $table->timestamps();

            $table->index(['material_product_id', 'created_at'], 'ccra_material_idx');
            $table->index(['catering_estimate_id'], 'ccra_estimate_idx');
            $table->index(['target_type', 'target_id'], 'ccra_target_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists(self::TABLE);
    }
};
