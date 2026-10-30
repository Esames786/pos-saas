<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * VOID-PURGE-1: numbers that belonged to a permanently removed entry. Journal, manual-journal and expense numbers are
 * "highest + 1"; without this, removing the latest entry would hand its number to the next one. The generators ask
 * here for the highest removed number too. Before the table exists (deploy window) nothing has been removed: null.
 */
final class PurgedNumbers
{
    public const JOURNAL_ENTRY = 'journal_entry';
    public const MANUAL_JOURNAL = 'manual_journal';
    public const EXPENSE_VOUCHER = 'expense_voucher';

    /** @var array<string, bool> per connection */
    private static array $ready = [];

    /** The highest removed number of this kind that starts with $prefix (string order — the numbers are zero-padded). */
    public static function highest(string $kind, string $prefix = ''): ?string
    {
        if (! self::ready()) {
            return null;
        }

        return DB::connection('tenant')->table('purged_numbers')->where('kind', $kind)
            ->when($prefix !== '', fn ($q) => $q->where('number', 'like', $prefix . '%'))
            ->orderByDesc('number')->value('number');
    }

    /** The highest removed manual-journal sequence (stored as digits). */
    public static function highestInt(string $kind): int
    {
        if (! self::ready()) {
            return 0;
        }

        return (int) DB::connection('tenant')->table('purged_numbers')->where('kind', $kind)
            ->max(DB::raw('CAST(number AS UNSIGNED)'));
    }

    public static function reserve(string $kind, string $number, ?int $purgedEntryId): void
    {
        DB::connection('tenant')->table('purged_numbers')->insertOrIgnore([
            'kind' => $kind, 'number' => $number, 'purged_entry_id' => $purgedEntryId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function ready(): bool
    {
        $key = (string) DB::connection('tenant')->getDatabaseName();

        return self::$ready[$key] ??= DB::connection('tenant')->getSchemaBuilder()->hasTable('purged_numbers');
    }

    /** Tests and the migration's first use: forget what was cached. */
    public static function forget(): void
    {
        self::$ready = [];
    }
}
