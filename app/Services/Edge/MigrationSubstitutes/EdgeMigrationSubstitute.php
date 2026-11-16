<?php

namespace App\Services\Edge\MigrationSubstitutes;

/**
 * OWNER DECISION (16 Nov 2026, item 3) — an Edge-specific EXECUTION of a canonical tenant migration.
 *
 * The canonical migration FILE is never edited and its NAME is still what the appliance records in the `migrations`
 * table (so the migration history stays aligned with the Cloud and the upgrader never re-applies it); only the BODY
 * that runs on the appliance is this class's up()/down(). Which migrations run through a substitute is declared,
 * per migration, in database/migrations/edge-tenant-manifest.php (EDGE_EXECUTION = SUBSTITUTE + EDGE_SUBSTITUTE =
 * the class) and shows in every plan / dry-run / install audit as the decision RUN_EDGE_SUBSTITUTE — nothing silent.
 *
 * A substitute still needs the owner's OWNER_APPROVAL on its manifest entry; without it the plan is a release blocker.
 */
interface EdgeMigrationSubstitute
{
    /** The exact canonical tenant migration name this class executes instead of (validated against the manifest key). */
    public static function forMigration(): string;

    /** One line for the plan / audit: what the Edge execution does differently from the canonical body. */
    public static function why(): string;

    public function up(): void;

    public function down(): void;
}
