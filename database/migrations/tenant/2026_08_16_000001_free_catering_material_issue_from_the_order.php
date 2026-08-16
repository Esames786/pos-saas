<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KASHIF-CATERING-STORE-1 — the store stops answering to the order.
 *
 * How the kitchen actually works: a man takes the sheet, walks to the store and
 * asks for what he is cooking. That might cover ten bookings, one, half of one,
 * or tomorrow's prep with no booking at all. It is his call.
 *
 * The schema forbade all of it. catering_production_release_id was NOT NULL and
 * UNIQUE — two rules nobody asked for: an issue cannot exist without an order,
 * and an order can never be issued against twice. No application code could work
 * around either, because they were database constraints.
 *
 * Three changes, and one correction that matters more than the rest:
 *
 *   1. the release reference becomes nullable   → issue with no order
 *   2. its UNIQUE index is dropped              → issue twice, or partially
 *   3. both references become ON DELETE SET NULL
 *
 * Point 3 is the important one. Both foreign keys were ON DELETE CASCADE, which
 * made sense while the link was mandatory — no order, no issue. Once the link is
 * only a reference, cascade becomes destructive: deleting a release would delete
 * the stock issue with it, erasing the record of material that physically left
 * the store. The stock movement is the fact; the order is only a note about it.
 *
 * Existing rows keep their references untouched. down() restores the old rules,
 * but refuses rather than silently deleting rows that could not exist under them.
 */
return new class extends Migration
{
    private const TABLE = 'catering_material_issues';

    private const RELEASE_FK = 'catering_material_issues_release_fk';

    private const EVENT_FK = 'catering_material_issues_catering_event_id_foreign';

    private const UNIQUE_IDX = 'catering_material_issues_release_unique';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable(self::TABLE)) {
            return;
        }

        // A unique index cannot be dropped while a foreign key relies on it, so
        // the constraints come off first and go back on at the end.
        $this->dropForeignIfExists(self::RELEASE_FK);
        $this->dropForeignIfExists(self::EVENT_FK);
        $this->dropIndexIfExists(self::UNIQUE_IDX);

        $this->run('ALTER TABLE `'.self::TABLE.'` MODIFY `catering_production_release_id` BIGINT UNSIGNED NULL');
        $this->run('ALTER TABLE `'.self::TABLE.'` MODIFY `catering_event_id` BIGINT UNSIGNED NULL');

        // Non-unique now: several issues may reference the same booking.
        $this->addIndexIfMissing('catering_production_release_id', 'cmi_release_idx');
        $this->addIndexIfMissing('catering_event_id', 'cmi_event_idx');

        // SET NULL, never CASCADE — see the note above. Losing the reference is
        // acceptable; losing the stock movement is not.
        $this->run(
            'ALTER TABLE `'.self::TABLE.'` ADD CONSTRAINT `'.self::RELEASE_FK.'` '
            .'FOREIGN KEY (`catering_production_release_id`) '
            .'REFERENCES `catering_production_releases` (`id`) ON DELETE SET NULL'
        );
        $this->run(
            'ALTER TABLE `'.self::TABLE.'` ADD CONSTRAINT `'.self::EVENT_FK.'` '
            .'FOREIGN KEY (`catering_event_id`) '
            .'REFERENCES `catering_events` (`id`) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable(self::TABLE)) {
            return;
        }

        $duplicates = DB::connection('tenant')->table(self::TABLE)
            ->whereNotNull('catering_production_release_id')
            ->select('catering_production_release_id')
            ->groupBy('catering_production_release_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()->count();

        $orphans = DB::connection('tenant')->table(self::TABLE)
            ->where(fn ($q) => $q->whereNull('catering_production_release_id')->orWhereNull('catering_event_id'))
            ->count();

        if ($duplicates > 0 || $orphans > 0) {
            throw new RuntimeException(
                'Cannot restore the one-issue-per-release rule: '
                ."{$duplicates} release(s) now have more than one issue and {$orphans} issue(s) have no "
                .'reference. Reversing would mean deleting real stock movements, so it is refused. '
                .'Resolve those rows first if the old rule is genuinely wanted back.'
            );
        }

        $this->dropForeignIfExists(self::RELEASE_FK);
        $this->dropForeignIfExists(self::EVENT_FK);
        $this->dropIndexIfExists('cmi_release_idx');
        $this->dropIndexIfExists('cmi_event_idx');

        $this->run('ALTER TABLE `'.self::TABLE.'` MODIFY `catering_production_release_id` BIGINT UNSIGNED NOT NULL');
        $this->run('ALTER TABLE `'.self::TABLE.'` MODIFY `catering_event_id` BIGINT UNSIGNED NOT NULL');

        $this->run('ALTER TABLE `'.self::TABLE.'` ADD UNIQUE `'.self::UNIQUE_IDX.'` (`catering_production_release_id`)');
        $this->run(
            'ALTER TABLE `'.self::TABLE.'` ADD CONSTRAINT `'.self::RELEASE_FK.'` '
            .'FOREIGN KEY (`catering_production_release_id`) '
            .'REFERENCES `catering_production_releases` (`id`) ON DELETE CASCADE'
        );
        $this->run(
            'ALTER TABLE `'.self::TABLE.'` ADD CONSTRAINT `'.self::EVENT_FK.'` '
            .'FOREIGN KEY (`catering_event_id`) REFERENCES `catering_events` (`id`) ON DELETE CASCADE'
        );
    }

    private function run(string $sql): void
    {
        DB::connection('tenant')->statement($sql);
    }

    private function dropForeignIfExists(string $name): void
    {
        $found = DB::connection('tenant')->selectOne(
            'SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = "FOREIGN KEY"',
            [self::TABLE, $name]
        );

        if ($found && (int) $found->c > 0) {
            $this->run('ALTER TABLE `'.self::TABLE.'` DROP FOREIGN KEY `'.$name.'`');
        }
    }

    private function dropIndexIfExists(string $index): void
    {
        if ($this->indexExists($index)) {
            $this->run('ALTER TABLE `'.self::TABLE.'` DROP INDEX `'.$index.'`');
        }
    }

    private function addIndexIfMissing(string $column, string $index): void
    {
        if (! $this->indexExists($index)) {
            $this->run('ALTER TABLE `'.self::TABLE.'` ADD INDEX `'.$index.'` (`'.$column.'`)');
        }
    }

    private function indexExists(string $index): bool
    {
        $found = DB::connection('tenant')->selectOne(
            'SELECT COUNT(*) c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [self::TABLE, $index]
        );

        return $found && (int) $found->c > 0;
    }
};
