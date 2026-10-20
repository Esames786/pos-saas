<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * UB-06 (unified-business roadmap) — one form, posted once.
 *
 * A double click, a slow network that the browser retries, or Back + Submit sent the same return twice: a return
 * with a bill only stopped when nothing was left to return, one without a bill never stopped. Now each form carries
 * a key made when the page is drawn. The server keeps the key on the record (unique), so a second post of the SAME
 * form finds the first record instead of making another one — and the same key with different contents is refused,
 * never posted.
 *
 * A form without a key (a tab opened before this release) posts exactly as before. Writing the key is skipped while
 * the column is missing (the migration may not have reached this tenant yet — schema first).
 */
final class SubmissionKey
{
    public const FIELD = 'submission_key';

    /** @var array<string, bool> keyed by "<database>.<table>" — the answer for this request */
    private static array $supported = [];

    public static function rule(): array
    {
        return ['nullable', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'];
    }

    /** A new key for a form being drawn. */
    public static function fresh(): string
    {
        return (string) Str::uuid();
    }

    /** The table can hold the key on this tenant's database. */
    public static function supported(string $table): bool
    {
        $connection = DB::connection('tenant');
        $id = $connection->getDatabaseName() . '.' . $table;

        return self::$supported[$id] ??= Schema::connection('tenant')->hasColumn($table, self::FIELD);
    }

    /** The key to store, or null when the form sent none or the table cannot hold it yet. */
    public static function usable(?string $key, string $table): ?string
    {
        $key = $key !== null ? trim($key) : '';

        return $key !== '' && self::supported($table) ? $key : null;
    }

    /**
     * A fingerprint of what the form asked for, so a reused key with different contents is caught.
     *
     * @param  array<mixed>  $payload
     */
    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode(self::normalise($payload), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** The insert failed only because another post already holds this key. */
    public static function isDuplicate(QueryException $e): bool
    {
        return self::duplicateOn($e, self::FIELD);
    }

    /**
     * A unique clash on this column. Read from the driver's own message ("Duplicate entry 'x' for key
     * 'sales_returns.sales_returns_return_no_unique'"), never from getMessage(): that one carries the whole SQL, which
     * names every column of the insert — every clash would look like a clash on every column.
     */
    public static function duplicateOn(QueryException $e, string $column): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000' && str_contains((string) ($e->errorInfo[2] ?? ''), $column);
    }

    /** Sorted keys, numbers as numbers, so "1" and 1 and 1.0 fingerprint the same. */
    private static function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = array_map(fn ($v) => self::normalise($v), $value);
            if (! array_is_list($out)) {
                ksort($out);
            }

            return $out;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return round((float) $value, 4);
        }

        return $value === null ? null : trim((string) $value);
    }
}
