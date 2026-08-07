<?php

namespace App\Models\Concerns;

use App\Support\Edge\EdgeIdentity;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * EDGE-IDENTITY-1 — opt-in canonical cross-system identity for an operational model.
 *
 * The consuming model declares:
 *   protected string $canonicalIdentityColumn = 'sale_uuid';
 *   protected string $canonicalIdentityFormat = EdgeIdentity::FORMAT_ULID; // optional, defaults to ULID
 *
 * On create the identity is generated application-side if absent (so it exists BEFORE the row is durable —
 * never after printing or during sync). Once persisted it is IMMUTABLE: any attempt to change a non-null
 * identity throws. The column is deliberately kept OUT of the model's $fillable so a normal HTTP/mass-assign
 * request can never supply or overwrite it — a future trusted Edge-sync ingestion boundary (which does not
 * exist yet) will be the only path allowed to preserve an Edge-minted identity, and it will set it explicitly.
 *
 * DB-level UNIQUE indexes (added by the migration) are the authoritative uniqueness guarantee; this trait is
 * the application-level generate-once + immutability guard (defence-in-depth, not a DB trigger).
 */
trait HasCanonicalIdentity
{
    public static function bootHasCanonicalIdentity(): void
    {
        static::creating(function (Model $model) {
            /** @var Model&\App\Models\Concerns\HasCanonicalIdentity $model */
            $column = $model->canonicalIdentityColumn();
            if (empty($model->{$column})) {
                $model->{$column} = EdgeIdentity::generate($model->canonicalIdentityFormat());
            }
        });

        static::updating(function (Model $model) {
            /** @var Model&\App\Models\Concerns\HasCanonicalIdentity $model */
            $column = $model->canonicalIdentityColumn();
            if ($model->isDirty($column) && $model->getOriginal($column) !== null) {
                throw new RuntimeException("{$column} is immutable once assigned and cannot be changed.");
            }
        });
    }

    public function canonicalIdentityColumn(): string
    {
        return $this->canonicalIdentityColumn;
    }

    public function canonicalIdentityFormat(): string
    {
        return property_exists($this, 'canonicalIdentityFormat')
            ? $this->canonicalIdentityFormat
            : EdgeIdentity::FORMAT_ULID;
    }
}
