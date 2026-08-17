<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-STORE-2 — one trip to the store, many bookings.
 *
 * The kitchen man comes once in the morning and takes eighty kilos of chicken
 * covering twelve weddings. Until now the issue could name one booking, so the
 * storeman either picked one arbitrarily and the other eleven went unrecorded,
 * or he split one real handover into twelve fictional ones.
 *
 * Neither is what happened. The stock movement is a single fact; the bookings
 * are the reasons behind it. So the reference becomes a relation.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: allocate. Recording that eighty kilos went
 * out for twelve bookings is true. Recording that each of them took 6.67 kilos
 * is a number nobody measured, and it would be read later as if someone had.
 *
 * catering_material_issues.catering_event_id STAYS. It is not dropped here, and
 * new writes continue to mirror the first selected booking into it. That is what
 * makes this migration reversible in a way that keeps meaning: down() can only
 * rebuild from the legacy column, so without the mirror a rollback would leave
 * historical issues pointing at nothing at all.
 *
 * Additive, idempotent, and a no-op on the eight tenants that have no catering
 * rows — the table exists everywhere, the data does not.
 */
return new class extends Migration
{
    private const TABLE = 'catering_material_issue_events';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('catering_material_issues')) {
            return; // catering never migrated here; nothing to relate
        }

        if (! Schema::connection('tenant')->hasTable(self::TABLE)) {
            Schema::connection('tenant')->create(self::TABLE, function (Blueprint $table) {
                $table->id();

                // Constraints are named explicitly: Laravel's generated name for
                // this pair is 65 characters, one over MySQL's identifier limit,
                // and the table simply refuses to be created.
                $table->foreignId('catering_material_issue_id');
                $table->foreign('catering_material_issue_id', 'cmie_issue_fk')
                    ->references('id')->on('catering_material_issues')
                    ->cascadeOnDelete();

                // Cascade, matching the ON DELETE SET NULL already carried by the
                // legacy column. The established contract is that deleting a
                // booking clears the reference and KEEPS the stock record: the
                // movement is the fact, the booking was only the explanation.
                // Restricting here would instead make bookings undeletable, which
                // is a different rule nobody asked for.
                $table->foreignId('catering_event_id');
                $table->foreign('catering_event_id', 'cmie_event_fk')
                    ->references('id')->on('catering_events')
                    ->cascadeOnDelete();

                $table->timestamps();

                $table->unique(
                    ['catering_material_issue_id', 'catering_event_id'],
                    'cmie_issue_event_unique'
                );
                $table->index('catering_event_id', 'cmie_event_idx');
            });
        }

        $this->backfillFromLegacyColumn();
    }

    /**
     * Every issue that already names a booking gets that relation.
     *
     * insertOrIgnore against the unique key, so running this twice — or running
     * it after new multi-booking rows already exist — adds nothing and breaks
     * nothing.
     */
    private function backfillFromLegacyColumn(): void
    {
        if (! Schema::connection('tenant')->hasColumn('catering_material_issues', 'catering_event_id')) {
            return;
        }

        DB::connection('tenant')->table('catering_material_issues')
            ->whereNotNull('catering_event_id')
            ->orderBy('id')
            ->select(['id', 'catering_event_id'])
            ->chunk(500, function ($issues) {
                $rows = [];
                foreach ($issues as $issue) {
                    $rows[] = [
                        'catering_material_issue_id' => $issue->id,
                        'catering_event_id' => $issue->catering_event_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($rows !== []) {
                    DB::connection('tenant')->table(self::TABLE)->insertOrIgnore($rows);
                }
            });
    }

    /**
     * The legacy column is left in place, so a rollback still knows which
     * booking each historical issue was primarily against. References beyond
     * that first one do not survive a rollback — there is nowhere for them to
     * live once this table is gone, and inventing a place would be worse.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists(self::TABLE);
    }
};
