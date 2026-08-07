<?php

namespace App\Support\Edge;

use Illuminate\Database\Eloquent\Model;

/**
 * EDGE-IDENTITY-1 — resolve an operational entity by its canonical CROSS-SYSTEM identity.
 *
 * A future sync payload refers to related entities by canonical identity (never by numeric id): a sale line
 * carries its parent sale's `sale_uuid`, a payment carries the sale's `sale_uuid`, a KOT line carries its
 * batch's `event_uuid`, a sale carries its `shift_uuid`, etc. On the receiving system the numeric ids differ,
 * so relationships are re-established by looking the canonical identity up in THIS database. That lookup is
 * exactly what this resolver does. It performs NO ingestion/writes and opens no sync boundary — it is a
 * read-only resolution primitive proven by the two-independent-database test.
 */
final class EdgeIdentityResolver
{
    /** The canonical [column, format] for a model, whether trait-managed or a reused identity. */
    public static function fieldFor(string $modelClass): ?array
    {
        return EdgeIdentity::REGISTRY[$modelClass] ?? EdgeIdentity::REUSED[$modelClass] ?? null;
    }

    /**
     * Resolve the row of $modelClass whose canonical identity equals $identity, on an optional connection.
     * Returns null if the model has no canonical identity or no row matches.
     */
    public static function resolve(string $modelClass, string $identity, ?string $connection = null): ?Model
    {
        $field = self::fieldFor($modelClass);
        if ($field === null) {
            return null;
        }
        [$column] = $field;

        $query = $connection !== null ? $modelClass::on($connection) : $modelClass::query();

        return $query->where($column, $identity)->first();
    }
}
